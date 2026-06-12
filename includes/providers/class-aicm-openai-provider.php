<?php
/**
 * OpenAI Provider
 *
 * Implements AICM_LLM_Provider for the OpenAI API.
 *
 * All HTTP requests use wp_remote_post() / wp_remote_get() — the WordPress
 * HTTP API — which respects WP_PROXY settings, uses WordPress's SSL handling,
 * and is required by WordPress.org (no raw cURL or Guzzle).
 *
 * Supported models (Phase 1):
 *  - Chat:       gpt-4o-mini (default), gpt-4o
 *  - Embeddings: text-embedding-3-small (default), text-embedding-3-large
 *
 * Error handling:
 *  - WP_Error (network failure) → logged + empty/false return
 *  - HTTP 401 Unauthorized      → invalid API key
 *  - HTTP 429 Too Many Requests → rate limit or quota exceeded
 *  - HTTP 5xx                   → OpenAI server error, retry later
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the provider interface.
require_once AICM_PLUGIN_DIR . 'includes/providers/interface-aicm-llm-provider.php';

/**
 * Class AICM_OpenAI_Provider
 */
class AICM_OpenAI_Provider implements AICM_LLM_Provider {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	private const API_BASE        = 'https://api.openai.com/v1';
	private const ENDPOINT_CHAT   = self::API_BASE . '/chat/completions';
	private const ENDPOINT_EMBED  = self::API_BASE . '/embeddings';
	private const ENDPOINT_MODELS = self::API_BASE . '/models';

	/**
	 * Pricing per 1M tokens (USD) — update when OpenAI changes pricing.
	 *
	 * @var array<string, array{input: float, output: float}>
	 */
	private const PRICING = array(
		'gpt-4o-mini'            => array(
			'input'  => 0.15,
			'output' => 0.60,
		),
		'gpt-4o'                 => array(
			'input'  => 2.50,
			'output' => 10.00,
		),
		'text-embedding-3-small' => array(
			'input'  => 0.02,
			'output' => 0.00,
		),
		'text-embedding-3-large' => array(
			'input'  => 0.13,
			'output' => 0.00,
		),
	);

	// -------------------------------------------------------------------------
	// Properties
	// -------------------------------------------------------------------------

	private string $api_key;
	private string $chat_model;
	private string $embedding_model;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Constructor.
	 *
	 * Reads the encrypted API key and model settings from wp_options.
	 * The API key is decrypted here — it is never stored as a plaintext
	 * property beyond the lifetime of this object.
	 */
	public function __construct() {
		$encrypted_key         = (string) get_option( 'aicm_api_key_openai', '' );
		$this->api_key         = AICM_Encryption::decrypt( $encrypted_key );
		$this->chat_model      = (string) AI_ChatMate::get_setting( 'chat_model', 'gpt-4o-mini' );
		$this->embedding_model = (string) AI_ChatMate::get_setting( 'embedding_model', 'text-embedding-3-small' );
	}

	// -------------------------------------------------------------------------
	// AICM_LLM_Provider interface implementation
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function chat_completion( array $messages, array $functions = array(), array $options = array() ): array {
		$empty_result = array(
			'content'       => null,
			'function_call' => null,
			'usage'         => array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
			),
		);

		if ( '' === $this->api_key ) {
			return $empty_result;
		}

		$model       = $options['model'] ?? $this->chat_model;
		$max_tokens  = $options['max_tokens'] ?? 1024;
		$temperature = $options['temperature'] ?? 0.7;

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		);

		// Add tool/function definitions when provided.
		if ( ! empty( $functions ) ) {
			$body['tools'] = array_map(
				static fn( array $fn ): array => array(
					'type'     => 'function',
					'function' => $fn,
				),
				$functions
			);

			// A caller may force one specific tool (options['tool_choice'] =
			// function name) — used to guarantee quick-reply chips on
			// zero-result turns, where 'auto' models tend to write text lists.
			$forced              = (string) ( $options['tool_choice'] ?? '' );
			$body['tool_choice'] = '' !== $forced
				? array(
					'type'     => 'function',
					'function' => array( 'name' => $forced ),
				)
				: 'auto';
		}

		$response = $this->post( self::ENDPOINT_CHAT, $body, 60 );

		if ( null === $response ) {
			return $empty_result;
		}

		$choice  = $response['choices'][0] ?? null;
		$message = $choice['message'] ?? null;
		$usage   = $response['usage'] ?? array();

		$result = array(
			'content'       => null,
			'function_call' => null,
			'usage'         => array(
				'input_tokens'  => (int) ( $usage['prompt_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
			),
		);

		// Direct text reply.
		if ( isset( $message['content'] ) && null !== $message['content'] ) {
			$result['content'] = $message['content'];
		}

		// Tool / function call — OpenAI returns tool_calls[] (can be multiple;
		// we use the first one as AI ChatMate sends one function at a time).
		if ( ! empty( $message['tool_calls'] ) ) {
			$tool_call = $message['tool_calls'][0];
			if ( 'function' === ( $tool_call['type'] ?? '' ) ) {
				$result['function_call'] = array(
					'name'      => $tool_call['function']['name'] ?? '',
					'arguments' => $tool_call['function']['arguments'] ?? '{}',
				);
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embedding( string $text ): array {
		return $this->generate_embeddings_batch( array( $text ) )[0] ?? array();
	}

	/**
	 * {@inheritdoc}
	 *
	 * OpenAI allows up to 2,048 texts per batch for text-embedding-3-small.
	 * We enforce a hard cap of 100 here — callers should chunk large sets
	 * themselves to stay within memory limits.
	 */
	public function generate_embeddings_batch( array $texts ): array {
		if ( empty( $texts ) || '' === $this->api_key ) {
			return array();
		}

		// Safety cap — each text can be up to ~8k tokens, so 100 items
		// is already potentially 800k tokens in one call.
		$texts = array_slice( $texts, 0, 100 );

		$body = array(
			'model'           => $this->embedding_model,
			'input'           => array_values( $texts ),
			'encoding_format' => 'float',
		);

		$response = $this->post( self::ENDPOINT_EMBED, $body, 30 );

		if ( null === $response || empty( $response['data'] ) ) {
			return array();
		}

		// Sort by index — OpenAI guarantees the same order but we sort
		// defensively to avoid subtle bugs when chunks are processed in bulk.
		$data = $response['data'];
		usort( $data, static fn( array $a, array $b ): int => $a['index'] <=> $b['index'] );

		return array_map(
			static fn( array $item ): array => $item['embedding'],
			$data
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection(): array {
		if ( '' === $this->api_key ) {
			return array(
				'success' => false,
				'message' => __( 'No API key is stored. Enter your OpenAI API key in the settings.', 'ai-chatmate' ),
				'model'   => '',
			);
		}

		// List models — lightweight endpoint that confirms the key is valid.
		$response = $this->get( self::ENDPOINT_MODELS );

		if ( null === $response ) {
			return array(
				'success' => false,
				'message' => __( 'Could not connect to OpenAI. Check your API key and network.', 'ai-chatmate' ),
				'model'   => '',
			);
		}

		// Confirm gpt-4o-mini is available (our default model).
		$model_ids = array_column( $response['data'] ?? array(), 'id' );
		$confirmed = in_array( $this->chat_model, $model_ids, true )
			? $this->chat_model
			: ( $model_ids[0] ?? 'unknown' );

		return array(
			'success' => true,
			'message' => __( 'Connected successfully.', 'ai-chatmate' ),
			'model'   => $confirmed,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_provider_name(): string {
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_chat_model(): string {
		return $this->chat_model;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_embedding_model(): string {
		return $this->embedding_model;
	}

	/**
	 * {@inheritdoc}
	 */
	public function estimate_cost( int $input_tokens, int $output_tokens ): float {
		$pricing = self::PRICING[ $this->chat_model ] ?? array(
			'input'  => 0.00,
			'output' => 0.00,
		);

		return round(
			( $input_tokens / 1_000_000 ) * $pricing['input']
			+ ( $output_tokens / 1_000_000 ) * $pricing['output'],
			6
		);
	}

	// -------------------------------------------------------------------------
	// Private HTTP helpers
	// -------------------------------------------------------------------------

	/**
	 * POST to the OpenAI API.
	 *
	 * @param string $url     Full endpoint URL.
	 * @param array  $body    Request body (will be JSON-encoded).
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|null Decoded JSON response body, or null on failure.
	 */
	private function post( string $url, array $body, int $timeout = 30 ): ?array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $timeout,
				'headers'     => $this->build_headers(),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * GET from the OpenAI API.
	 *
	 * @param string $url     Full endpoint URL.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|null Decoded JSON response body, or null on failure.
	 */
	private function get( string $url, int $timeout = 15 ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => $this->build_headers(),
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Build the HTTP headers required by OpenAI.
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			// Identify our plugin to OpenAI (good practice, not required).
			'User-Agent'    => 'AI-ChatMate/' . AICM_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
		);
	}

	/**
	 * Parse and validate an HTTP response from wp_remote_*().
	 *
	 * Handles:
	 *  - WP_Error (network / DNS failure)
	 *  - Non-200 HTTP status codes
	 *  - Malformed JSON bodies
	 *  - OpenAI error objects ({ "error": { "message": "..." } })
	 *
	 * @param array|WP_Error $response wp_remote_post/get return value.
	 * @return array|null Decoded body, or null on any failure.
	 */
	private function parse_response( array|WP_Error $response ): ?array {
		if ( is_wp_error( $response ) ) {
			$this->log_error( 'Network error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Log non-2xx responses with the OpenAI error message when available.
		if ( $code < 200 || $code >= 300 ) {
			$api_error = $data['error']['message'] ?? "HTTP {$code}";
			$this->log_error( "OpenAI API error ({$code}): {$api_error}" );
			return null;
		}

		if ( ! is_array( $data ) ) {
			$this->log_error( 'OpenAI returned non-JSON or empty body.' );
			return null;
		}

		return $data;
	}

	/**
	 * Write a debug/error log entry when WP_DEBUG is enabled.
	 *
	 * We NEVER log the API key or user message content here.
	 *
	 * @param string $message Error description.
	 */
	private function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[AI ChatMate / OpenAI] ' . $message );
		}
	}
}
