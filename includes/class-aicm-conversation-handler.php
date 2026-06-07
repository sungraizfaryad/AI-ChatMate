<?php
/**
 * Conversation Handler
 *
 * Orchestrates the full RAG + function-calling chat flow for a single
 * user message turn.
 *
 * ── Flow ─────────────────────────────────────────────────────────────────────
 *
 *  ┌──────────────────────────────────────────────────────────────────────┐
 *  │  1. Resolve provider (bail early if no API key configured)           │
 *  │  2. Resolve / create session ID                                      │
 *  │  2.5 Q&A check — if admin Q&A pair matches, return stored answer     │
 *  │  3. RAG retrieval — embed query, find top-k similar chunks           │
 *  │  4. Build system prompt (personality + RAG context block)            │
 *  │  5. First chat_completion call (with search_posts function offered)  │
 *  │     ├─ AI replies directly (content) → Step 8                        │
 *  │     └─ AI calls function (function_call) ──────────────────────────┐ │
 *  │  6.   Execute WP_Query via AICM_Query_Builder                      │ │
 *  │  7.   Second chat_completion call (function result injected)       │ │
 *  │  8. Save turn to session history                                    │ │
 *  │  9. Track token usage cost                                          │ │
 *  │ 10. Return structured reply                                         │ │
 *  └──────────────────────────────────────────────────────────────────────┘
 *
 * ── Sessions ─────────────────────────────────────────────────────────────────
 * Conversation history is stored in a WordPress transient keyed by the
 * session_id the client supplies (or a new UUID if none is given). Transients
 * expire after SESSION_TTL_SECONDS (30 minutes of inactivity).
 *
 * The system prompt is never stored in the transient — it is rebuilt fresh on
 * every turn so that changes to settings (personality, indexed types) take
 * effect immediately without requiring a new session.
 *
 * ── Token budgeting ───────────────────────────────────────────────────────────
 * If the accumulated history would exceed session_token_cap, the oldest
 * message pairs (user + assistant) are trimmed from the front of the history
 * until the estimate fits. The first exchange is always kept as minimum
 * context. Token counts are estimated using the same 4-chars/token heuristic
 * used throughout the plugin.
 *
 * ── Function calling ──────────────────────────────────────────────────────────
 * The AI is offered one function: search_posts. It calls this when it needs
 * to look up specific posts by type, keyword, category, tag, or custom field
 * value — capabilities that RAG alone cannot provide (e.g. "show me the three
 * cheapest properties in London").
 *
 * The function result is injected into the second chat_completion call as a
 * 'tool' role message, following OpenAI's tools API format.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Conversation_Handler
 */
class AICM_Conversation_Handler {

	/**
	 * Session transient time-to-live in seconds.
	 * After this period of inactivity the session is automatically discarded.
	 */
	private const SESSION_TTL_SECONDS = 30 * MINUTE_IN_SECONDS;

	/** Prefix applied to all session transient keys. */
	private const SESSION_KEY_PREFIX = 'aicm_sess_';

	/**
	 * Maximum characters included in the RAG context block that is injected
	 * into the system prompt.
	 * ~6,000 chars ≈ 1,500 tokens — leaves plenty of room for the conversation
	 * history and the model's output within a typical 4k-token context window.
	 */
	private const RAG_CONTEXT_MAX_CHARS = 6000;

	/** Number of similar chunks to retrieve from the index per turn. */
	private const RAG_TOP_K = 5;

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Handle a single user message and return the AI's reply.
	 *
	 * This is the sole public entry point. Called from the REST endpoint.
	 *
	 * @param string $user_message User's message (already sanitized by REST args).
	 * @param string $session_id   Client-supplied session ID, or '' for new session.
	 * @return array {
	 *   'reply'      => string      AI reply text.
	 *   'session_id' => string      Session ID to echo back to the client.
	 *   'sources'    => int[]       Post IDs referenced in the answer context.
	 *   'error'      => string|null Error message, or null on success.
	 *   'usage'      => array       Combined token usage for this turn.
	 * }
	 */
	public static function handle( string $user_message, string $session_id ): array {

		// ── Step 1: resolve provider ──────────────────────────────────────
		$provider = self::get_provider();

		if ( null === $provider ) {
			return self::error_response(
				$session_id,
				__( 'AI ChatMate is not configured. Please add an API key in the admin settings.', 'ai-chatmate' )
			);
		}

		// ── Step 2: resolve session ───────────────────────────────────────
		$session_id = self::resolve_session_id( $session_id );
		$history    = self::load_history( $session_id );

		// ── Step 2.5: Q&A exact-match check ──────────────────────────────
		// Check admin-configured Q&A pairs before RAG and LLM.
		// When a pair scores at or above the similarity threshold (0.92), its
		// stored answer is returned immediately — no RAG, no LLM call, no cost.
		$qa_match = AICM_QA_Manager::find_match( $provider, $user_message );

		if ( null !== $qa_match ) {
			// Persist the exchange to session history so the conversation
			// continues naturally on subsequent turns.
			$history[] = array( 'role' => 'user',      'content' => $user_message );
			$history[] = array( 'role' => 'assistant',  'content' => $qa_match['answer'] );
			$history   = self::trim_history_to_budget( $history, (int) AI_ChatMate::get_setting( 'session_token_cap', 5000 ) );
			self::save_history( $session_id, $history );

			return array(
				'reply'      => $qa_match['answer'],
				'session_id' => $session_id,
				'sources'    => [],
				'error'      => null,
				'usage'      => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
			);
		}

		// ── Step 3: RAG retrieval ─────────────────────────────────────────
		$rag_chunks  = AICM_RAG_Retriever::find_similar( $provider, $user_message, self::RAG_TOP_K );
		$rag_context = self::build_rag_context( $rag_chunks );
		$source_ids  = array_values( array_unique( array_column( $rag_chunks, 'post_id' ) ) );

		// ── Step 4: build first-turn messages ────────────────────────────
		$system_prompt = self::build_system_prompt( $rag_context );
		$messages      = self::build_messages( $system_prompt, $history, $user_message );
		$functions     = self::get_function_definitions();

		// Determine how many output tokens we can afford.
		$token_cap        = (int) AI_ChatMate::get_setting( 'session_token_cap', 5000 );
		$estimated_input  = self::estimate_message_tokens( $messages );
		$max_output       = max( 256, min( 1024, $token_cap - $estimated_input ) );

		// ── Step 5: first chat_completion call ────────────────────────────
		$result = $provider->chat_completion(
			$messages,
			$functions,
			array( 'max_tokens' => $max_output )
		);

		$total_usage = $result['usage'] ?? array( 'input_tokens' => 0, 'output_tokens' => 0 );

		// ── Steps 6–7: function call branch ──────────────────────────────
		if ( ! empty( $result['function_call'] ) ) {
			$fn_name   = (string) ( $result['function_call']['name']      ?? '' );
			$fn_args   = json_decode( (string) ( $result['function_call']['arguments'] ?? '{}' ), true );
			$fn_args   = is_array( $fn_args ) ? $fn_args : [];
			$fn_result = self::execute_function( $fn_name, $fn_args );

			// Merge WP_Query result post IDs into the sources list.
			if ( ! empty( $fn_result['post_ids'] ) ) {
				$source_ids = array_values(
					array_unique( array_merge( $source_ids, (array) $fn_result['post_ids'] ) )
				);
			}

			// Build second-turn message array (history + user + assistant + tool).
			$messages_r2 = self::build_messages_with_tool_result(
				$system_prompt,
				$history,
				$user_message,
				$result['function_call'],
				$fn_name,
				$fn_result
			);

			$result2     = $provider->chat_completion( $messages_r2, [], array( 'max_tokens' => 1024 ) );
			$total_usage = self::merge_usage( $total_usage, $result2['usage'] ?? [] );
			$reply       = (string) ( $result2['content'] ?? '' );

		} else {
			$reply = (string) ( $result['content'] ?? '' );
		}

		// ── Fallback when the model returns an empty string ───────────────
		if ( '' === trim( $reply ) ) {
			$reply = __( "I'm sorry, I couldn't generate a response. Please try again.", 'ai-chatmate' );
		}

		// ── Step 8: persist session history ──────────────────────────────
		$history[] = array( 'role' => 'user',      'content' => $user_message );
		$history[] = array( 'role' => 'assistant',  'content' => $reply );
		$history   = self::trim_history_to_budget( $history, $token_cap );
		self::save_history( $session_id, $history );

		// ── Step 9: track cost ───────────────────────────────────────────
		self::track_usage( $provider, $total_usage );

		// ── Step 10: return reply ─────────────────────────────────────────
		return array(
			'reply'      => $reply,
			'session_id' => $session_id,
			'sources'    => $source_ids,
			'error'      => null,
			'usage'      => $total_usage,
		);
	}

	// ── Private: provider ─────────────────────────────────────────────────────

	/**
	 * Instantiate the configured LLM provider.
	 *
	 * Returns null when no API key is stored for the active provider so the
	 * handler can return a friendly error without making any API calls.
	 *
	 * @return AICM_LLM_Provider|null
	 */
	private static function get_provider(): ?AICM_LLM_Provider {
		$active     = (string) AI_ChatMate::get_setting( 'active_provider', 'openai' );
		$option_key = "aicm_api_key_{$active}";

		if ( '' === (string) get_option( $option_key, '' ) ) {
			return null;
		}

		if ( 'openai' === $active ) {
			require_once AICM_PLUGIN_DIR . 'includes/providers/class-aicm-openai-provider.php';
			return new AICM_OpenAI_Provider();
		}

		// Additional providers (Anthropic, Google) will be wired in future phases.
		return null;
	}

	// ── Private: session management ──────────────────────────────────────────

	/**
	 * Validate the client-supplied session ID, or generate a new one.
	 *
	 * Accepts UUIDs and alphanumeric tokens between 8 and 64 characters.
	 * Rejects anything else to prevent cache key injection.
	 *
	 * @param string $session_id Raw session ID from the REST request.
	 * @return string A valid session ID.
	 */
	private static function resolve_session_id( string $session_id ): string {
		if (
			'' !== $session_id
			&& preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $session_id )
		) {
			return $session_id;
		}

		return wp_generate_uuid4();
	}

	/**
	 * Load conversation history from a transient.
	 *
	 * @param string $session_id Session ID.
	 * @return array Message pairs; empty array on miss or expiry.
	 */
	private static function load_history( string $session_id ): array {
		$data = get_transient( self::SESSION_KEY_PREFIX . $session_id );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Persist conversation history to a transient.
	 *
	 * The TTL is refreshed on every turn so active sessions stay alive.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $history    Messages to store.
	 */
	private static function save_history( string $session_id, array $history ): void {
		set_transient(
			self::SESSION_KEY_PREFIX . $session_id,
			$history,
			self::SESSION_TTL_SECONDS
		);
	}

	/**
	 * Trim conversation history to fit within the session token budget.
	 *
	 * Removes the oldest user+assistant pair (2 messages) repeatedly until
	 * the estimated token count is within the cap, or only the most recent
	 * exchange remains. The last 2 messages (current turn) are never trimmed.
	 *
	 * @param array $history   Full message history for this session.
	 * @param int   $token_cap Maximum total estimated tokens.
	 * @return array Trimmed history.
	 */
	private static function trim_history_to_budget( array $history, int $token_cap ): array {
		// Must keep at least the two messages we just added (current turn).
		while ( count( $history ) > 2 ) {
			if ( self::estimate_message_tokens( $history ) <= $token_cap ) {
				break;
			}
			// Drop oldest pair.
			array_splice( $history, 0, 2 );
		}

		return $history;
	}

	// ── Private: prompt construction ─────────────────────────────────────────

	/**
	 * Build the system prompt for the current turn.
	 *
	 * Injects: site name, AI personality instructions, RAG context (when
	 * available), and guidance on when to use the search_posts function.
	 *
	 * The system prompt is intentionally rebuilt on every turn (not cached in
	 * session history) so settings changes take effect immediately.
	 *
	 * @param string $rag_context Pre-formatted RAG context block; may be empty.
	 * @return string System prompt text.
	 */
	private static function build_system_prompt( string $rag_context ): string {
		$site_name   = get_bloginfo( 'name' );
		$personality = (string) AI_ChatMate::get_setting( 'ai_personality', 'friendly' );

		$personality_text = match ( $personality ) {
			'professional' => 'You are a professional, formal, and precise assistant.',
			'casual'       => 'You are a laid-back, friendly assistant who speaks conversationally.',
			'custom'       => (string) AI_ChatMate::get_setting(
				'welcome_message',
				'You are a helpful assistant.'
			),
			default        => 'You are a friendly, warm, and helpful assistant.',
		};

		$prompt  = "You are an AI assistant for **{$site_name}**. {$personality_text}\n\n";
		$prompt .= "Your role is to help visitors find information, products, listings, and other content on this website.\n\n";

		if ( '' !== $rag_context ) {
			$prompt .= "## Relevant content retrieved from this website\n\n";
			$prompt .= $rag_context . "\n\n";
			$prompt .= "Use the content above to answer the user's question when it is relevant. "
				. "Cite the source title and link when referencing specific content. "
				. "If the retrieved content does not fully answer the question, you may call "
				. "the `search_posts` function to look for more specific results.\n\n";
		} else {
			$prompt .= "No pre-retrieved content was found for this query. "
				. "Use the `search_posts` function to find relevant content on this website "
				. "if the user's question requires looking up specific posts, pages, "
				. "listings, or products.\n\n";
		}

		$prompt .= "Always be accurate. Only state facts supported by the website's content. "
			. "If you cannot find relevant information after searching, say so honestly "
			. "and suggest how the visitor might find what they need.";

		return $prompt;
	}

	/**
	 * Format retrieved chunks into a context block for the system prompt.
	 *
	 * Groups chunks by post and labels each source with its title and URL.
	 * Truncates at RAG_CONTEXT_MAX_CHARS to keep the prompt size predictable.
	 *
	 * @param array[] $chunks Records returned by AICM_RAG_Retriever::find_similar().
	 * @return string Formatted context block, or '' if no chunks.
	 */
	private static function build_rag_context( array $chunks ): string {
		if ( empty( $chunks ) ) {
			return '';
		}

		$parts      = [];
		$total_chars = 0;
		// Cache post titles/URLs to avoid duplicate get_the_title() calls.
		$post_labels = [];

		foreach ( $chunks as $chunk ) {
			$text = trim( (string) ( $chunk['chunk_text'] ?? '' ) );

			if ( '' === $text ) {
				continue;
			}

			$len = mb_strlen( $text );

			// If adding this chunk would overflow, truncate it to fit.
			if ( $total_chars + $len > self::RAG_CONTEXT_MAX_CHARS ) {
				$remaining = self::RAG_CONTEXT_MAX_CHARS - $total_chars;
				if ( $remaining < 80 ) {
					// Not enough room for meaningful content — stop here.
					break;
				}
				$text = mb_substr( $text, 0, $remaining ) . '…';
				$len  = mb_strlen( $text );
			}

			$post_id = (int) $chunk['post_id'];

			if ( ! isset( $post_labels[ $post_id ] ) ) {
				$title = get_the_title( $post_id );
				$url   = get_permalink( $post_id );

				$post_labels[ $post_id ] = ( $title && $url )
					? "[{$title}]({$url})"
					: "Post #{$post_id}";
			}

			$parts[]      = "**Source:** {$post_labels[$post_id]}\n{$text}";
			$total_chars += $len;

			if ( $total_chars >= self::RAG_CONTEXT_MAX_CHARS ) {
				break;
			}
		}

		return implode( "\n\n---\n\n", $parts );
	}

	/**
	 * Build the messages array for the first chat_completion call.
	 *
	 * Format: system → [history pairs] → current user message.
	 *
	 * @param string $system_prompt System prompt text.
	 * @param array  $history       Stored conversation history (user/assistant pairs).
	 * @param string $user_message  Current user message.
	 * @return array OpenAI messages format.
	 */
	private static function build_messages(
		string $system_prompt,
		array  $history,
		string $user_message
	): array {
		$messages   = [];
		$messages[] = array( 'role' => 'system', 'content' => $system_prompt );

		foreach ( $history as $entry ) {
			$role = $entry['role'] ?? '';
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$messages[] = array(
				'role'    => $role,
				'content' => (string) ( $entry['content'] ?? '' ),
			);
		}

		$messages[] = array( 'role' => 'user', 'content' => $user_message );

		return $messages;
	}

	/**
	 * Build the messages array for the second call after a function result.
	 *
	 * Follows the OpenAI tools API format:
	 *  1. Existing messages (system + history + user).
	 *  2. Assistant message containing the tool_calls the AI requested.
	 *  3. Tool message containing the function's return value (JSON).
	 *
	 * @param string $system_prompt  System prompt.
	 * @param array  $history        Stored conversation history.
	 * @param string $user_message   Current user message.
	 * @param array  $function_call  function_call object from the first response.
	 * @param string $fn_name        Name of the called function.
	 * @param array  $fn_result      PHP result array from execute_function().
	 * @return array OpenAI messages array.
	 */
	private static function build_messages_with_tool_result(
		string $system_prompt,
		array  $history,
		string $user_message,
		array  $function_call,
		string $fn_name,
		array  $fn_result
	): array {
		$messages = self::build_messages( $system_prompt, $history, $user_message );

		// A stable but unique ID for this tool call turn.
		$tool_call_id = 'call_' . wp_generate_uuid4();

		// Append the assistant's tool-call message (content must be null or '').
		$messages[] = array(
			'role'       => 'assistant',
			'content'    => null,
			'tool_calls' => array(
				array(
					'id'       => $tool_call_id,
					'type'     => 'function',
					'function' => array(
						'name'      => (string) ( $function_call['name']      ?? $fn_name ),
						'arguments' => (string) ( $function_call['arguments']  ?? '{}' ),
					),
				),
			),
		);

		// Append the tool result message.
		$messages[] = array(
			'role'         => 'tool',
			'tool_call_id' => $tool_call_id,
			'content'      => (string) wp_json_encode( $fn_result ),
		);

		return $messages;
	}

	// ── Private: function execution ───────────────────────────────────────────

	/**
	 * Return the function definitions to pass to the AI each turn.
	 *
	 * Exposes one function: search_posts. The AI calls this when it needs to
	 * perform a structured query (filter by taxonomy, meta, order by price, etc.)
	 * that cannot be satisfied by the RAG context alone.
	 *
	 * The list of available post types is injected into the description so the
	 * AI knows which types it can search — it will not invent type names.
	 *
	 * @return array[] Function definition objects in OpenAI tools format.
	 */
	private static function get_function_definitions(): array {
		$configured_types = (array) AI_ChatMate::get_setting(
			'index_post_types',
			array( 'post', 'page' )
		);

		$type_list = implode( ', ', $configured_types );

		return array(
			array(
				'name'        => 'search_posts',
				'description' => "Search published content on this WordPress website. "
					. "Available post types: {$type_list}. "
					. "Call this when the user wants to find, browse, list, or filter specific posts, "
					. "pages, listings, products, or other content by type, keyword, category, "
					. "tag, or custom field value.",
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(

						'post_type' => array(
							'type'        => 'string',
							'description' => "Post type slug to search. One of: {$type_list}. "
								. "Omit to search all available types.",
						),

						'search' => array(
							'type'        => 'string',
							'description' => 'Keyword or phrase to search in post titles and content.',
						),

						'taxonomy_filters' => array(
							'type'        => 'array',
							'description' => 'Filter by taxonomy terms (e.g. category, tag, or a custom taxonomy).',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'taxonomy' => array(
										'type'        => 'string',
										'description' => 'Taxonomy slug (e.g. "category", "post_tag", "location").',
									),
									'term'     => array(
										'type'        => 'string',
										'description' => 'Term name to match.',
									),
								),
								'required'   => array( 'taxonomy', 'term' ),
							),
						),

						'meta_filters' => array(
							'type'        => 'array',
							'description' => 'Filter by custom field (post meta) values.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'key'     => array(
										'type'        => 'string',
										'description' => 'Meta key name.',
									),
									'value'   => array(
										'type'        => 'string',
										'description' => 'Value to compare against.',
									),
									'compare' => array(
										'type' => 'string',
										'enum' => array( '=', '!=', '<', '<=', '>', '>=', 'LIKE', 'EXISTS' ),
									),
								),
								'required'   => array( 'key' ),
							),
						),

						'orderby' => array(
							'type'        => 'string',
							'enum'        => array( 'date', 'modified', 'title', 'ID', 'meta_value', 'meta_value_num' ),
							'description' => 'How to sort the results.',
						),

						'order' => array(
							'type' => 'string',
							'enum' => array( 'ASC', 'DESC' ),
						),

						'meta_key' => array(
							'type'        => 'string',
							'description' => 'Meta key to sort by. Required when orderby is "meta_value" or "meta_value_num".',
						),

						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 10,
							'default'     => 5,
							'description' => 'Number of results to return (1–10).',
						),

					),
					'required'   => array(),
				),
			),
		);
	}

	/**
	 * Dispatch an AI function call and return the result.
	 *
	 * Currently handles 'search_posts' via AICM_Query_Builder.
	 * Unknown function names return a structured error the AI can interpret
	 * gracefully (it will tell the user it could not complete the search).
	 *
	 * @param string $fn_name Name of the function the AI requested.
	 * @param array  $fn_args JSON-decoded arguments.
	 * @return array Result payload for injection as the tool response message.
	 */
	private static function execute_function( string $fn_name, array $fn_args ): array {
		if ( 'search_posts' === $fn_name ) {
			$query_args = AICM_Query_Builder::build( $fn_args );
			return AICM_Query_Builder::execute( $query_args );
		}

		return array(
			'error'    => "Unknown function: {$fn_name}",
			'found'    => 0,
			'posts'    => [],
			'post_ids' => [],
		);
	}

	// ── Private: token and cost utilities ────────────────────────────────────

	/**
	 * Estimate the total token count for an array of messages.
	 *
	 * Uses the same 4-chars/token heuristic as AICM_Chunker::estimate_tokens().
	 * This is a conservative approximation — actual token counts vary by model
	 * and language, but the estimate is accurate enough for budgeting purposes.
	 *
	 * @param array $messages Messages array (OpenAI format).
	 * @return int Estimated token count.
	 */
	private static function estimate_message_tokens( array $messages ): int {
		$total = 0;

		foreach ( $messages as $message ) {
			$content = $message['content'] ?? '';
			if ( is_string( $content ) ) {
				$total += (int) ceil( mb_strlen( $content ) / 4 );
			}
		}

		return $total;
	}

	/**
	 * Sum two usage arrays (input + output tokens).
	 *
	 * @param array $a Usage from the first API call.
	 * @param array $b Usage from the second API call (may be empty).
	 * @return array Combined usage.
	 */
	private static function merge_usage( array $a, array $b ): array {
		return array(
			'input_tokens'  => (int) ( $a['input_tokens']  ?? 0 ) + (int) ( $b['input_tokens']  ?? 0 ),
			'output_tokens' => (int) ( $a['output_tokens'] ?? 0 ) + (int) ( $b['output_tokens'] ?? 0 ),
		);
	}

	/**
	 * Accumulate monthly API usage cost in the aicm_monthly_usage option.
	 *
	 * Stored as: [ 'YYYY-MM' => float_usd_cost, ... ]
	 * The admin can view this in the analytics page (Phase 6).
	 *
	 * @param AICM_LLM_Provider $provider Provider (needed for estimate_cost()).
	 * @param array             $usage    Combined input/output token counts.
	 */
	private static function track_usage( AICM_LLM_Provider $provider, array $usage ): void {
		$cost = $provider->estimate_cost(
			(int) ( $usage['input_tokens']  ?? 0 ),
			(int) ( $usage['output_tokens'] ?? 0 )
		);

		if ( $cost <= 0.0 ) {
			return;
		}

		$month_key  = gmdate( 'Y-m' );
		$usage_data = get_option( 'aicm_monthly_usage', array() );

		$usage_data[ $month_key ] = round(
			(float) ( $usage_data[ $month_key ] ?? 0.0 ) + $cost,
			6
		);

		update_option( 'aicm_monthly_usage', $usage_data );
	}

	// ── Private: error helper ─────────────────────────────────────────────────

	/**
	 * Return a structured error response.
	 *
	 * @param string $session_id Existing session ID (or '' if none yet).
	 * @param string $message    Human-readable error string.
	 * @return array Standardised response array.
	 */
	private static function error_response( string $session_id, string $message ): array {
		return array(
			'reply'      => $message,
			'session_id' => '' !== $session_id ? $session_id : wp_generate_uuid4(),
			'sources'    => [],
			'error'      => $message,
			'usage'      => array( 'input_tokens' => 0, 'output_tokens' => 0 ),
		);
	}
}
