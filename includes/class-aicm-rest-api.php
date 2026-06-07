<?php
/**
 * REST API Controller
 *
 * Registers all REST endpoints for AI ChatMate.
 *
 * Namespace: aicm/v1
 *
 * Security model:
 *  ┌──────────────────────────────────────────────────────────────┐
 *  │ Endpoint              │ Permission                           │
 *  ├──────────────────────────────────────────────────────────────┤
 *  │ POST /chat            │ Public — nonce + rate limiting       │
 *  │ POST /test-connection │ manage_options + nonce               │
 *  │ GET  /settings        │ manage_options + nonce               │
 *  │ POST /settings        │ manage_options + nonce               │
 *  │ GET  /index/status    │ manage_options + nonce               │
 *  │ POST /index/start     │ manage_options + nonce               │
 *  │ POST /index/stop      │ manage_options + nonce               │
 *  │ GET  /schema          │ manage_options + nonce               │
 *  │ POST /schema/rescan   │ manage_options + nonce               │
 *  │ GET  /qa              │ manage_options + nonce               │
 *  │ POST /qa              │ manage_options + nonce               │
 *  │ PUT  /qa/{id}         │ manage_options + nonce               │
 *  │ DELETE /qa/{id}       │ manage_options + nonce               │
 *  └──────────────────────────────────────────────────────────────┘
 *
 * Phase 1 implements settings and test-connection endpoints fully.
 * Phase 2 implements the schema endpoints (GET /schema, POST /schema/rescan).
 * Phase 3 implements the indexing endpoints (GET /index/status, POST /index/start, /index/stop).
 * Phase 4 implements the chat endpoint (POST /chat) via AICM_Conversation_Handler.
 * Phase 7 implements Q&A CRUD endpoints (GET /qa, POST /qa, PUT /qa/{id}, DELETE /qa/{id}).
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_REST_API
 */
class AICM_REST_API {

	/**
	 * REST namespace — matches plugin slug convention.
	 */
	private const NAMESPACE = 'aicm/v1';

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

	/**
	 * Register all REST routes.
	 *
	 * Called from AI_ChatMate::init_rest_api() on the `rest_api_init` hook.
	 */
	public function register_routes(): void {
		// Public: chat endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'chat_permission_check' ),
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( string $v ): bool => '' !== trim( $v ) && mb_strlen( $v ) <= 2000,
					),
					'session_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);

		// Admin: API key + model settings.
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);

		// Admin: test API connection.
		register_rest_route(
			self::NAMESPACE,
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'provider' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( string $v ): bool => in_array( $v, array( 'openai', 'anthropic', 'google' ), true ),
					),
					'api_key' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);

		// Admin: indexing status.
		register_rest_route(
			self::NAMESPACE,
			'/index/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_index_status' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// Admin: start indexing.
		register_rest_route(
			self::NAMESPACE,
			'/index/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_indexing' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// Admin: stop indexing.
		register_rest_route(
			self::NAMESPACE,
			'/index/stop',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stop_indexing' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// Admin: get schema.
		register_rest_route(
			self::NAMESPACE,
			'/schema',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// Admin: trigger schema rescan.
		register_rest_route(
			self::NAMESPACE,
			'/schema/rescan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rescan_schema' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// Admin: wizard — detect (preview) schema without persisting.
		register_rest_route(
			self::NAMESPACE,
			'/onboarding/detect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'onboarding_detect' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// Admin: wizard — read/save the field config.
		register_rest_route(
			self::NAMESPACE,
			'/field-config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_field_config' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_field_config' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);

		// Admin: wizard — mark onboarding complete.
		register_rest_route(
			self::NAMESPACE,
			'/onboarding/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_onboarding' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		// Admin: Q&A list + create.
		register_rest_route(
			self::NAMESPACE,
			'/qa',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_qa' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_qa' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => array(
						'question' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static fn( string $v ): bool => '' !== trim( $v ),
						),
						'answer'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'validate_callback' => static fn( string $v ): bool => '' !== trim( $v ),
						),
						'priority' => array(
							'required' => false,
							'type'     => 'integer',
							'minimum'  => 1,
							'maximum'  => 100,
							'default'  => 50,
						),
						'is_active' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => true,
						),
					),
				),
			)
		);

		// Admin: Q&A update + delete (single item).
		register_rest_route(
			self::NAMESPACE,
			'/qa/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_qa' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => array(
						'id'       => array(
							'required'          => true,
							'type'              => 'integer',
							'validate_callback' => static fn( $v ): bool => (int) $v > 0,
						),
						'question' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'answer'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'priority' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'is_active' => array(
							'type' => 'boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_qa' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'validate_callback' => static fn( $v ): bool => (int) $v > 0,
						),
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Permission check for the public /chat endpoint.
	 *
	 * Verifies a nonce (set by wp_localize_script / wp_add_inline_script
	 * on the frontend) and enforces per-IP rate limiting.
	 * Non-logged-in users are allowed — this endpoint must be public.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return bool|WP_Error
	 */
	public function chat_permission_check( WP_REST_Request $request ): bool|WP_Error {
		// Opt-in gate: the public chat is OFF until the admin explicitly enables
		// the widget. This is what stops an anonymous visitor from spending the
		// site owner's API budget the moment the plugin is active.
		if ( ! (bool) AI_ChatMate::get_setting( 'widget_enabled', false ) ) {
			return new WP_Error(
				'aicm_chat_disabled',
				__( 'The chat assistant is not enabled on this site.', 'ai-chatmate' ),
				array( 'status' => 403 )
			);
		}

		// Verify the nonce that the chat widget JS sends with every request.
		$nonce = sanitize_text_field( wp_unslash( $request->get_header( 'X-AICM-Nonce' ) ?? '' ) );

		if ( ! wp_verify_nonce( $nonce, 'aicm_chat_nonce' ) ) {
			return new WP_Error(
				'aicm_invalid_nonce',
				__( 'Security check failed.', 'ai-chatmate' ),
				array( 'status' => 403 )
			);
		}

		// Daily budget kill-switch: once today's API spend reaches the configured
		// daily budget, the bot pauses until tomorrow. Protects against bill shock.
		if ( AICM_Billing::daily_budget_reached() ) {
			return new WP_Error(
				'aicm_budget_reached',
				__( 'The chat assistant is temporarily unavailable. Please try again later.', 'ai-chatmate' ),
				array( 'status' => 503 )
			);
		}

		// Rate limiting — max messages per minute per IP.
		$rate_check = $this->check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Per-IP per-day message cap — a hard ceiling on how many messages one
		// visitor can send in a day (0 = unlimited).
		$daily_check = $this->check_daily_cap();
		if ( is_wp_error( $daily_check ) ) {
			return $daily_check;
		}

		return true;
	}

	/**
	 * Permission check for all admin-only endpoints.
	 *
	 * Requires the `manage_options` capability, which is restricted to
	 * WordPress Administrators. Also verifies a REST nonce.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return bool|WP_Error
	 */
	public function admin_permission_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'aicm_forbidden',
				__( 'You do not have permission to access this.', 'ai-chatmate' ),
				array( 'status' => 403 )
			);
		}

		// check_ajax_referer equivalent for REST — verify the _wpnonce header
		// or parameter sent by the admin JS.
		$nonce = sanitize_text_field(
			wp_unslash(
				$request->get_header( 'X-WP-Nonce' )
				?? $request->get_param( '_wpnonce' )
				?? ''
			)
		);

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'aicm_invalid_nonce',
				__( 'Security check failed.', 'ai-chatmate' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Endpoint handlers — fully implemented in Phase 1
	// -------------------------------------------------------------------------

	/**
	 * GET /settings
	 *
	 * Returns all plugin settings safe for the admin UI.
	 * API keys are returned as masked placeholders — never as plaintext.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$settings = AI_ChatMate::get_setting();

		return new WP_REST_Response(
			array(
				'settings'          => $settings,
				// Indicate whether a key is stored without revealing it.
				'has_key_openai'    => '' !== (string) get_option( 'aicm_api_key_openai', '' ),
				'has_key_anthropic' => '' !== (string) get_option( 'aicm_api_key_anthropic', '' ),
				'has_key_google'    => '' !== (string) get_option( 'aicm_api_key_google', '' ),
			),
			200
		);
	}

	/**
	 * POST /settings
	 *
	 * Saves plugin settings. API keys are encrypted before storage.
	 * An empty api_key value means "do not change the stored key".
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			return new WP_Error(
				'aicm_bad_request',
				__( 'Invalid request body.', 'ai-chatmate' ),
				array( 'status' => 400 )
			);
		}

		// -----------------------------------------------------------
		// Handle API keys separately — they are stored in their own
		// options with encryption, never mixed with general settings.
		// -----------------------------------------------------------
		$key_map = array(
			'api_key_openai'    => 'aicm_api_key_openai',
			'api_key_anthropic' => 'aicm_api_key_anthropic',
			'api_key_google'    => 'aicm_api_key_google',
		);

		foreach ( $key_map as $param_name => $option_name ) {
			if ( isset( $params[ $param_name ] ) ) {
				$raw_key = sanitize_text_field( wp_unslash( (string) $params[ $param_name ] ) );

				// Non-empty string = new key submitted; encrypt and store.
				// Empty string = leave existing key unchanged.
				if ( '' !== $raw_key ) {
					update_option( $option_name, AICM_Encryption::encrypt( $raw_key ) );
				}

				// Remove from params so it is not saved in aicm_settings.
				unset( $params[ $param_name ] );
			}
		}

		// -----------------------------------------------------------
		// Sanitize and save general settings.
		// -----------------------------------------------------------
		$current  = AI_ChatMate::get_setting();
		$defaults = array(
			'active_provider'   => 'openai',
			'chat_model'        => 'gpt-4o-mini',
			'embedding_model'   => 'text-embedding-3-small',
			'ai_personality'    => 'friendly',
			'index_post_types'  => array( 'post', 'page' ),
			'auto_sync'         => true,
			'cron_schedule'     => 'weekly',
			'batch_size'        => 50,
			'rate_limit_msgs'   => 20,
			'session_token_cap' => 5000,
			'monthly_budget'    => 0.00,
			'logging_enabled'   => false,
			'widget_position'   => 'bottom-right',
			'widget_color'      => '#0073aa',
			'welcome_message'   => '',
			'results_display'   => 'plugin_page',
		);

		$allowed_providers  = array( 'openai', 'anthropic', 'google' );
		$allowed_models     = array( 'gpt-4o-mini', 'gpt-4o', 'claude-sonnet-4-20250514', 'gemini-2.0-flash' );
		$allowed_embed      = array( 'text-embedding-3-small', 'text-embedding-3-large' );
		$allowed_personality = array( 'professional', 'friendly', 'casual', 'custom' );
		$allowed_positions  = array( 'bottom-right', 'bottom-left' );
		$allowed_display    = array( 'plugin_page', 'theme_archive', 'in_chat' );
		$allowed_schedules  = array( 'daily', 'weekly' );

		$updated = $current;

		// String fields with enum validation.
		if ( isset( $params['active_provider'] ) && in_array( $params['active_provider'], $allowed_providers, true ) ) {
			$updated['active_provider'] = $params['active_provider'];
		}
		if ( isset( $params['chat_model'] ) && in_array( $params['chat_model'], $allowed_models, true ) ) {
			$updated['chat_model'] = $params['chat_model'];
		}
		if ( isset( $params['embedding_model'] ) && in_array( $params['embedding_model'], $allowed_embed, true ) ) {
			$updated['embedding_model'] = $params['embedding_model'];
		}
		if ( isset( $params['ai_personality'] ) && in_array( $params['ai_personality'], $allowed_personality, true ) ) {
			$updated['ai_personality'] = $params['ai_personality'];
		}
		if ( isset( $params['widget_position'] ) && in_array( $params['widget_position'], $allowed_positions, true ) ) {
			$updated['widget_position'] = $params['widget_position'];
		}
		if ( isset( $params['results_display'] ) && in_array( $params['results_display'], $allowed_display, true ) ) {
			$updated['results_display'] = $params['results_display'];
		}
		if ( isset( $params['cron_schedule'] ) && in_array( $params['cron_schedule'], $allowed_schedules, true ) ) {
			$updated['cron_schedule'] = $params['cron_schedule'];
		}

		// Free-text string fields.
		if ( isset( $params['welcome_message'] ) ) {
			$updated['welcome_message'] = sanitize_text_field( wp_unslash( (string) $params['welcome_message'] ) );
		}

		// Hex colour validation.
		if ( isset( $params['widget_color'] ) ) {
			$color = sanitize_hex_color( (string) $params['widget_color'] );
			if ( $color ) {
				$updated['widget_color'] = $color;
			}
		}

		// Integer fields with range validation.
		if ( isset( $params['batch_size'] ) ) {
			$updated['batch_size'] = max( 10, min( 200, (int) $params['batch_size'] ) );
		}
		if ( isset( $params['rate_limit_msgs'] ) ) {
			$updated['rate_limit_msgs'] = max( 1, min( 100, (int) $params['rate_limit_msgs'] ) );
		}
		if ( isset( $params['session_token_cap'] ) ) {
			$updated['session_token_cap'] = max( 500, min( 32000, (int) $params['session_token_cap'] ) );
		}

		// Float fields.
		if ( isset( $params['monthly_budget'] ) ) {
			$updated['monthly_budget'] = max( 0.00, (float) $params['monthly_budget'] );
		}
		if ( isset( $params['daily_budget'] ) ) {
			$updated['daily_budget'] = max( 0.00, (float) $params['daily_budget'] );
		}
		if ( isset( $params['daily_msg_cap'] ) ) {
			$updated['daily_msg_cap'] = max( 0, (int) $params['daily_msg_cap'] );
		}

		// Boolean fields.
		if ( isset( $params['auto_sync'] ) ) {
			$updated['auto_sync'] = (bool) $params['auto_sync'];
		}
		if ( isset( $params['logging_enabled'] ) ) {
			$updated['logging_enabled'] = (bool) $params['logging_enabled'];
		}
		if ( isset( $params['semantic_mode'] ) ) {
			$updated['semantic_mode'] = (bool) $params['semantic_mode'];
		}
		if ( isset( $params['widget_enabled'] ) ) {
			$updated['widget_enabled'] = (bool) $params['widget_enabled'];
		}

		// Array field — post types.
		if ( isset( $params['index_post_types'] ) && is_array( $params['index_post_types'] ) ) {
			$updated['index_post_types'] = array_map( 'sanitize_key', $params['index_post_types'] );
		}

		update_option( 'aicm_settings', $updated );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /test-connection
	 *
	 * Tests the API key for the specified provider.
	 * If an api_key param is provided it is used temporarily (not saved).
	 * If not, the currently stored encrypted key is used.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$raw_key  = sanitize_text_field( wp_unslash( (string) $request->get_param( 'api_key' ) ) );

		// If a key was submitted with the request, temporarily store it
		// (encrypted) for the duration of this test without saving permanently.
		// The provider constructor reads from wp_options, so we need to
		// temporarily set the option, run the test, then restore.
		$option_name = "aicm_api_key_{$provider}";
		$original    = get_option( $option_name, '' );

		if ( '' !== $raw_key ) {
			update_option( $option_name, AICM_Encryption::encrypt( $raw_key ) );
		}

		$result = array( 'success' => false, 'message' => '', 'model' => '' );

		// Only OpenAI provider is implemented in Phase 1.
		if ( 'openai' === $provider ) {
			require_once AICM_PLUGIN_DIR . 'includes/providers/class-aicm-openai-provider.php';
			$openai_provider = new AICM_OpenAI_Provider();
			$result          = $openai_provider->test_connection();
		} else {
			$result['message'] = __( 'This provider is not yet implemented.', 'ai-chatmate' );
		}

		// Restore the original stored key if we temporarily replaced it.
		if ( '' !== $raw_key ) {
			update_option( $option_name, $original );

			// If the test succeeded and the admin wants to keep this key,
			// they will save it via POST /settings — we do not auto-save here.
		}

		return new WP_REST_Response( $result, 200 );
	}

	// -------------------------------------------------------------------------
	// Chat endpoint — fully implemented in Phase 4
	// -------------------------------------------------------------------------

	/**
	 * POST /chat
	 *
	 * Passes the user message to AICM_Conversation_Handler, which runs the
	 * full RAG + function-calling pipeline and returns a reply.
	 *
	 * Request params (validated by register_routes args):
	 *  - message    (string, required) — user's message, max 2,000 chars.
	 *  - session_id (string, optional) — session UUID for conversation history.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle_chat( WP_REST_Request $request ): WP_REST_Response {
		$message    = (string) $request->get_param( 'message' );
		$session_id = (string) $request->get_param( 'session_id' );

		$result = AICM_Conversation_Handler::handle( $message, $session_id );

		// Resolve source post IDs into title + URL objects for the frontend.
		$sources = [];
		foreach ( (array) ( $result['sources'] ?? [] ) as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( $post instanceof WP_Post ) {
				$sources[] = array(
					'id'    => $post->ID,
					'title' => $post->post_title,
					'url'   => get_permalink( $post->ID ),
				);
			}
		}

		return new WP_REST_Response(
			array(
				'reply'         => $result['reply'],
				'session_id'    => $result['session_id'],
				'sources'       => $sources,
				'preview_cards' => null,
				'results_url'   => null,
			),
			200
		);
	}

	/**
	 * GET /index/status
	 *
	 * @return WP_REST_Response
	 */
	public function get_index_status(): WP_REST_Response {
		$status = get_option(
			'aicm_index_status',
			array(
				'total_chunks' => 0,
				'pending'      => 0,
				'is_running'   => false,
				'last_indexed' => null,
			)
		);

		return new WP_REST_Response( $status, 200 );
	}

	/**
	 * POST /index/start
	 *
	 * Enqueues all published posts of the configured post types for a full
	 * re-index. Posts are added to the aicm_queue table and processed
	 * by the 5-minute WP-Cron job in batches — no API calls happen inline.
	 *
	 * Returns immediately with the number of posts queued so the admin UI
	 * can display progress without waiting for embedding to complete.
	 *
	 * @return WP_REST_Response
	 */
	public function start_indexing(): WP_REST_Response {
		$queued = AICM_Index_Manager::enqueue_full_reindex();

		if ( 0 === $queued ) {
			$message = __( 'All posts are already queued. Indexing is in progress.', 'ai-chatmate' );
		} else {
			$message = sprintf(
				/* translators: %d: number of posts added to the indexing queue */
				_n(
					'%d post has been queued for indexing.',
					'%d posts have been queued for indexing.',
					$queued,
					'ai-chatmate'
				),
				$queued
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $message,
				'queued'  => $queued,
			),
			200
		);
	}

	/**
	 * POST /index/stop
	 *
	 * Cancels all pending queue items and marks the indexer as not running.
	 * Any batch currently being processed by cron runs to completion —
	 * only future batches are cancelled.
	 *
	 * @return WP_REST_Response
	 */
	public function stop_indexing(): WP_REST_Response {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_queue';

		// Delete all pending rows — removes the remaining work from the queue.
		// 'processing' rows (current cron batch) are left to complete naturally.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM `{$table}` WHERE status = 'pending'" );

		// Update the status option to reflect the stop.
		$status                 = get_option( 'aicm_index_status', array() );
		$status['is_running']   = false;
		$status['pending']      = 0;
		update_option( 'aicm_index_status', $status );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * GET /schema
	 *
	 * Returns the cached site schema, or an empty state if no scan has
	 * been run yet. The schema is populated by AICM_Schema_Discovery::run()
	 * which is triggered either via POST /schema/rescan or the weekly cron.
	 *
	 * @return WP_REST_Response
	 */
	public function get_schema(): WP_REST_Response {
		$schema = AICM_Schema_Cache::get();

		return new WP_REST_Response(
			array(
				'schema'       => $schema,
				'discovered'   => null !== $schema,
				'generated_at' => AICM_Schema_Cache::last_generated_at(),
			),
			200
		);
	}

	/**
	 * POST /schema/rescan
	 *
	 * Runs schema discovery synchronously and caches the result.
	 *
	 * We run synchronously on the REST request (rather than scheduling a
	 * background cron) so the admin UI receives the fresh schema immediately
	 * in the response. Discovery is fast — it samples at most 50 posts per
	 * post type and 300 terms per taxonomy, so even large sites finish in
	 * a few seconds.
	 *
	 * @return WP_REST_Response
	 */
	public function rescan_schema(): WP_REST_Response {
		$schema = AICM_Schema_Discovery::run();

		return new WP_REST_Response(
			array(
				'success'      => true,
				'schema'       => $schema,
				'generated_at' => $schema['generated_at'] ?? null,
				'post_types'   => count( $schema['post_types'] ?? array() ),
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Onboarding wizard endpoints
	// -------------------------------------------------------------------------

	/**
	 * POST /onboarding/detect — run discovery in preview mode (no persist).
	 *
	 * @return WP_REST_Response
	 */
	public function onboarding_detect(): WP_REST_Response {
		$schema = AICM_Schema_Discovery::run( false );
		return new WP_REST_Response(
			array(
				'schema'     => $schema,
				'post_types' => count( $schema['post_types'] ?? array() ),
			),
			200
		);
	}

	/**
	 * GET /field-config — return the saved field config.
	 *
	 * @return WP_REST_Response
	 */
	public function get_field_config(): WP_REST_Response {
		return new WP_REST_Response( array( 'config' => AICM_Field_Config::get() ), 200 );
	}

	/**
	 * POST /field-config — sanitize and persist a field config payload.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_field_config( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! isset( $params['config'] ) ) {
			return new WP_Error( 'aicm_bad_request', __( 'Invalid request body.', 'ai-chatmate' ), array( 'status' => 400 ) );
		}
		$saved = AICM_Field_Config::save( $params['config'] );
		return new WP_REST_Response( array( 'success' => true, 'config' => $saved ), 200 );
	}

	/**
	 * POST /onboarding/complete — persist chosen post types + mark done.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function complete_onboarding( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();

		// Persist the chosen searchable post types (reuses existing setting).
		if ( isset( $params['index_post_types'] ) && is_array( $params['index_post_types'] ) ) {
			AI_ChatMate::update_setting(
				'index_post_types',
				array_map( 'sanitize_key', $params['index_post_types'] )
			);
		}

		// Persist the field config if supplied.
		if ( isset( $params['config'] ) ) {
			AICM_Field_Config::save( $params['config'] );
		}

		// Persist the live schema (the wizard previewed it; now make it live).
		AICM_Schema_Discovery::run( true );

		AICM_Onboarding::mark_complete();

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Q&A endpoints — fully implemented in Phase 7
	// -------------------------------------------------------------------------

	/**
	 * GET /qa
	 *
	 * Returns all Q&A pairs (embeddings excluded — binary BLOBs not useful in JSON).
	 *
	 * @return WP_REST_Response
	 */
	public function list_qa(): WP_REST_Response {
		$rows = AICM_QA_Manager::get_all();

		return new WP_REST_Response( array( 'items' => $rows ), 200 );
	}

	/**
	 * POST /qa
	 *
	 * Creates a new Q&A pair. Triggers embedding inline (best-effort).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_qa( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = AICM_QA_Manager::save(
			array(
				'question'  => (string) $request->get_param( 'question' ),
				'answer'    => (string) $request->get_param( 'answer' ),
				'priority'  => (int) $request->get_param( 'priority' ),
				'is_active' => (bool) $request->get_param( 'is_active' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response( array( 'success' => true, 'id' => $result ), 201 );
	}

	/**
	 * PUT /qa/{id}
	 *
	 * Updates an existing Q&A pair. Re-embeds the question when its text changes.
	 * Fields not sent in the request body retain their current stored values.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_qa( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$current = AICM_QA_Manager::get( $id );

		if ( null === $current ) {
			return new WP_Error(
				'aicm_qa_not_found',
				__( 'Q&A entry not found.', 'ai-chatmate' ),
				array( 'status' => 404 )
			);
		}

		// Merge request params over the current row — supports partial updates.
		$data = array( 'id' => $id );

		foreach ( array( 'question', 'answer', 'priority', 'is_active' ) as $key ) {
			$param         = $request->get_param( $key );
			$data[ $key ]  = ( null !== $param ) ? $param : $current[ $key ];
		}

		$result = AICM_QA_Manager::save( $data );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * DELETE /qa/{id}
	 *
	 * Deletes a Q&A pair (including its stored embedding).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_qa( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$deleted = AICM_QA_Manager::delete( $id );

		if ( ! $deleted ) {
			return new WP_Error(
				'aicm_qa_not_found',
				__( 'Q&A entry not found or already deleted.', 'ai-chatmate' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Rate limiting helper
	// -------------------------------------------------------------------------

	/**
	 * Check and increment the per-IP rate limit for the /chat endpoint.
	 *
	 * Rate limit: configurable messages per minute (default 20).
	 * IP addresses are NEVER stored — we hash them with wp_hash() before
	 * using as a transient key. The hash is one-way; the original IP
	 * cannot be recovered from the transient key.
	 *
	 * @return true|WP_Error True if within limit, WP_Error if exceeded.
	 */
	private function check_rate_limit(): true|WP_Error {
		$limit = (int) AI_ChatMate::get_setting( 'rate_limit_msgs', 20 );

		// Rate limiting disabled when limit is 0.
		if ( 0 === $limit ) {
			return true;
		}

		// Retrieve the client IP safely. REMOTE_ADDR is the most reliable
		// source — forwarded headers can be spoofed.
		$raw_ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';

		// One-way hash — IP is never stored in the database or logs.
		$ip_hash      = wp_hash( $raw_ip . wp_salt( 'nonce' ) );
		$transient_key = 'aicm_rl_' . $ip_hash;

		$current = (int) get_transient( $transient_key );

		if ( $current >= $limit ) {
			return new WP_Error(
				'aicm_rate_limited',
				__( 'Too many requests. Please wait a moment before sending another message.', 'ai-chatmate' ),
				array( 'status' => 429 )
			);
		}

		// Increment counter — expires after 60 seconds (one minute window).
		set_transient( $transient_key, $current + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Check and increment the per-IP per-day message cap for /chat.
	 *
	 * A hard ceiling on how many messages a single visitor can send in one day
	 * (0 = unlimited). IPs are never stored — they are one-way hashed, the same
	 * way as the per-minute limiter.
	 *
	 * @return true|WP_Error True if within the cap, WP_Error if exceeded.
	 */
	private function check_daily_cap(): true|WP_Error {
		$cap = (int) AI_ChatMate::get_setting( 'daily_msg_cap', 0 );

		if ( $cap <= 0 ) {
			return true;
		}

		$raw_ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';

		$ip_hash       = wp_hash( $raw_ip . wp_salt( 'nonce' ) );
		$transient_key = 'aicm_dc_' . gmdate( 'Ymd' ) . '_' . $ip_hash;

		$current = (int) get_transient( $transient_key );

		if ( $current >= $cap ) {
			return new WP_Error(
				'aicm_daily_cap',
				__( 'You have reached the daily message limit. Please try again tomorrow.', 'ai-chatmate' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $transient_key, $current + 1, DAY_IN_SECONDS );

		return true;
	}
}
