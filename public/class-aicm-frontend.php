<?php
/**
 * Frontend
 *
 * Registers the chat widget assets and renders the widget HTML in the
 * WordPress frontend (non-admin) context.
 *
 * ── What this class does ──────────────────────────────────────────────────
 *  - Enqueues aicm-widget.css and aicm-widget.js on every frontend page.
 *  - Injects the brand colour override and position rule via an inline style.
 *  - Passes the REST URL, nonce, and settings to JS via wp_localize_script().
 *  - Renders the launcher button and widget panel HTML in wp_footer (priority 100).
 *  - Registers the [ai_chatmate] shortcode (assets only — the widget is always
 *    in the footer, so the shortcode itself outputs nothing).
 *
 * ── Nonce ─────────────────────────────────────────────────────────────────
 * wp_create_nonce('aicm_chat_nonce') is passed to JS as aicmChat.nonce.
 * The JS widget sends it as the 'X-AICM-Nonce' request header, which the
 * REST permission callback verifies with wp_verify_nonce( $nonce, 'aicm_chat_nonce' ).
 *
 * ── Brand colour ──────────────────────────────────────────────────────────
 * The admin configures a hex colour in Settings → Widget. This class injects:
 *   :root { --aicm-color: #xxxxxx; }
 * as an inline style appended to the aicm-widget stylesheet. The CSS file
 * uses var(--aicm-color) everywhere, so one override changes the whole theme.
 *
 * ── Position ──────────────────────────────────────────────────────────────
 * bottom-right (default) — no additional CSS needed.
 * bottom-left            — overrides .aicm-launcher and .aicm-widget via
 *                          an additional inline style rule.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Frontend
 */
class AICM_Frontend {

	/**
	 * Singleton instance.
	 *
	 * @var AICM_Frontend|null
	 */
	private static ?AICM_Frontend $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return AICM_Frontend
	 */
	public static function instance(): AICM_Frontend {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor — private to enforce singleton.
	 *
	 * Registers all WordPress hooks. Only called from instance().
	 */
	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ), 100 );
		add_shortcode( 'ai_chatmate', array( $this, 'shortcode' ) );
	}

	/**
	 * Prevent cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Whether the public chat widget is enabled by the admin.
	 *
	 * The widget is OFF by default; the admin turns it on in the setup wizard
	 * or Settings. This is the same opt-in flag the /chat endpoint enforces, so
	 * the widget never appears for a bot that cannot answer.
	 *
	 * @return bool
	 */
	private static function is_enabled(): bool {
		return (bool) AI_ChatMate::get_setting( 'widget_enabled', false );
	}

	/**
	 * Whether the assistant is actually able to answer right now.
	 *
	 * The widget is only rendered when it can deliver: an API key must be
	 * configured for the active provider, and — if the optional Semantic Q&A
	 * mode is enabled — the content index must contain at least one chunk.
	 * Until then the widget is hidden entirely, so visitors are never shown
	 * a chat that can only apologise.
	 *
	 * Structured search (the default mode) queries WordPress directly and
	 * does NOT require the index, so sites that leave Semantic Q&A off get
	 * the widget as soon as an API key is saved.
	 *
	 * Public because the /chat REST permission callback applies the same
	 * gate server-side (the widget being hidden is not a security boundary).
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		return self::status()['ready'];
	}

	/**
	 * Full widget visibility status, for admin-facing messaging.
	 *
	 * Single source of truth for "will the widget appear on the frontend,
	 * and if not — why". Used by the settings and indexing admin pages so
	 * the site owner always knows why the widget is (not) showing.
	 *
	 * @return array{enabled: bool, ready: bool, reason: string}
	 *               reason is '' when ready, otherwise 'no_key' or 'index_empty'.
	 */
	public static function status(): array {
		$enabled = (bool) AI_ChatMate::get_setting( 'widget_enabled', false );
		$ready   = true;
		$reason  = '';

		$index = (array) get_option( 'aicm_index_status', array() );

		// Has any indexing run ever been started on this site?
		$index_started = ! empty( $index['is_running'] )
			|| (int) ( $index['pending'] ?? 0 ) > 0
			|| (int) ( $index['total_chunks'] ?? 0 ) > 0;

		// An API key for the active provider is always required.
		$active = (string) AI_ChatMate::get_setting( 'active_provider', 'openai' );
		if ( '' === (string) get_option( "aicm_api_key_{$active}", '' ) ) {
			$ready  = false;
			$reason = 'no_key';
		} elseif ( $index_started && empty( $index['initial_complete'] ) ) {
			// The FIRST indexing run must finish before the widget goes live —
			// a half-built index gives visitors half-baked answers. Sites that
			// never start indexing (structured search only) are not affected,
			// and later re-indexes do not re-hide the widget.
			$ready  = false;
			$reason = 'indexing';
		} elseif ( (bool) AI_ChatMate::get_setting( 'semantic_mode', false ) ) {
			// Semantic Q&A needs embeddings — require a non-empty index.
			if ( (int) ( $index['total_chunks'] ?? 0 ) < 1 ) {
				$ready  = false;
				$reason = 'index_empty';
			}
		}

		return array(
			'enabled' => $enabled,
			'ready'   => $ready,
			'reason'  => $reason,
		);
	}

	// ── Hooks ─────────────────────────────────────────────────────────────────

	/**
	 * Enqueue the widget stylesheet and script; inject settings into JS.
	 *
	 * Callback for the `wp_enqueue_scripts` action.
	 */
	public function enqueue_assets(): void {
		// Opt-in gate: do nothing unless the admin enabled the widget
		// AND the assistant is actually ready to answer (key + index state).
		if ( ! self::is_enabled() || ! self::is_ready() ) {
			return;
		}

		// Cache-bust on file change, not just on plugin release — browsers
		// hold widget assets aggressively and a stale script silently drops
		// newer features (chips, history) for returning visitors.
		$css_ver = (string) ( @filemtime( AICM_PLUGIN_DIR . 'public/css/aicm-widget.css' ) ?: AICM_VERSION ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filemtime may warn on exotic filesystems; version fallback handles it.
		$js_ver  = (string) ( @filemtime( AICM_PLUGIN_DIR . 'public/js/aicm-widget.js' ) ?: AICM_VERSION ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same fallback as above.

		// ── Stylesheet ────────────────────────────────────────────────────
		wp_enqueue_style(
			'aicm-widget',
			AICM_PLUGIN_URL . 'public/css/aicm-widget.css',
			array(),
			$css_ver
		);

		// ── Brand colour + position overrides (inline, appended to sheet) ─
		$raw_color    = (string) AI_ChatMate::get_setting( 'widget_color', '#0073aa' );
		$raw_position = (string) AI_ChatMate::get_setting( 'widget_position', 'bottom-right' );

		$color    = sanitize_hex_color( $raw_color ) ?: '#0073aa';
		$position = in_array( $raw_position, array( 'bottom-right', 'bottom-left' ), true )
			? $raw_position
			: 'bottom-right';

		$inline_css = ':root { --aicm-color: ' . esc_attr( $color ) . '; }';

		if ( 'bottom-left' === $position ) {
			// Reposition the launcher and panel to the left side.
			$inline_css .= ' .aicm-launcher { right: auto; left: 24px; }'
				. ' .aicm-widget { right: auto; left: 24px; transform-origin: bottom left; }'
				. ' @media (max-width:480px) {'
				. '   .aicm-launcher { right: auto; left: 16px; }'
				. ' }';
		}

		wp_add_inline_style( 'aicm-widget', $inline_css );

		// ── Script ────────────────────────────────────────────────────────
		wp_enqueue_script(
			'aicm-widget',
			AICM_PLUGIN_URL . 'public/js/aicm-widget.js',
			array(),       // No dependencies — vanilla JS.
			$js_ver,
			true      // Load in footer (after DOM is ready).
		);

		// ── Inline data for the JS widget ─────────────────────────────────
		wp_localize_script(
			'aicm-widget',
			'aicmChat',
			array(
				// REST base URL — the JS appends /chat, etc.
				'restUrl'        => esc_url_raw( rest_url( 'aicm/v1' ) ),
				// Nonce for the aicm_chat_nonce action (chat endpoint).
				'nonce'          => wp_create_nonce( 'aicm_chat_nonce' ),
				// Standard REST nonce (action 'wp_rest'), sent as X-WP-Nonce.
				// Without it, WordPress treats the request as logged-out (uid 0)
				// even for logged-in users — and the aicm_chat_nonce above was
				// minted for the logged-in uid, so verification would always
				// fail for logged-in visitors ("Security check failed").
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				// Site name shown in the widget header.
				'siteName'       => get_bloginfo( 'name' ),
				// First message displayed when the widget is opened (optional).
				'welcomeMessage' => (string) AI_ChatMate::get_setting( 'welcome_message', '' ),
				// Input placeholder — localised so it can be translated.
				'placeholder'    => __( 'Ask a question…', 'ai-chatmate' ),
				// Strings used by the chat-history UI.
				'i18n'           => array(
					'newConversation' => __( 'New conversation', 'ai-chatmate' ),
					'noHistory'       => __( 'No previous conversations yet.', 'ai-chatmate' ),
					'current'         => __( 'Current', 'ai-chatmate' ),
				),
			)
		);
	}

	/**
	 * Output the launcher button and chat widget panel HTML.
	 *
	 * Runs in wp_footer at priority 100 (after themes and other plugins have
	 * added their own footer content). The launcher and panel are always
	 * rendered — JavaScript controls open/close visibility.
	 *
	 * Callback for the `wp_footer` action.
	 */
	public function render_widget(): void {
		// Opt-in gate: render nothing unless the admin enabled the widget
		// AND the assistant is actually ready to answer (key + index state).
		if ( ! self::is_enabled() || ! self::is_ready() ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		?>
		<!-- Conciera widget — start -->
		<button
			type="button"
			id="aicm-launcher"
			class="aicm-launcher"
			aria-label="<?php esc_attr_e( 'Open chat', 'ai-chatmate' ); ?>"
			aria-expanded="false"
			aria-controls="aicm-widget"
		>
			<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
				viewBox="0 0 24 24" fill="none" stroke="currentColor"
				stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
				aria-hidden="true" focusable="false">
				<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
			</svg>
		</button>

		<div
			id="aicm-widget"
			class="aicm-widget"
			role="dialog"
			aria-label="<?php echo esc_attr( $site_name . ' — ' . __( 'Chat', 'ai-chatmate' ) ); ?>"
			aria-modal="true"
			aria-hidden="true"
		>
			<div class="aicm-widget__header">
				<span class="aicm-widget__title">
					<?php echo esc_html( $site_name ); ?>
				</span>
				<button
					type="button"
					class="aicm-widget__hbtn"
					id="aicm-history-btn"
					aria-label="<?php esc_attr_e( 'Previous chats', 'ai-chatmate' ); ?>"
					aria-expanded="false"
					aria-controls="aicm-history"
					title="<?php esc_attr_e( 'Previous chats', 'ai-chatmate' ); ?>"
				>
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
						viewBox="0 0 24 24" fill="none" stroke="currentColor"
						stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
						aria-hidden="true" focusable="false">
						<circle cx="12" cy="12" r="10"/>
						<polyline points="12 6 12 12 16 14"/>
					</svg>
				</button>
				<button
					type="button"
					class="aicm-widget__hbtn"
					id="aicm-newchat-btn"
					aria-label="<?php esc_attr_e( 'Start a new chat', 'ai-chatmate' ); ?>"
					title="<?php esc_attr_e( 'Start a new chat', 'ai-chatmate' ); ?>"
				>
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
						viewBox="0 0 24 24" fill="none" stroke="currentColor"
						stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
						aria-hidden="true" focusable="false">
						<line x1="12" y1="5" x2="12" y2="19"/>
						<line x1="5" y1="12" x2="19" y2="12"/>
					</svg>
				</button>
				<button
					type="button"
					class="aicm-widget__close"
					aria-label="<?php esc_attr_e( 'Close chat', 'ai-chatmate' ); ?>"
				>
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
						viewBox="0 0 24 24" fill="none" stroke="currentColor"
						stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
						aria-hidden="true" focusable="false">
						<line x1="18" y1="6" x2="6" y2="18"/>
						<line x1="6" y1="6" x2="18" y2="18"/>
					</svg>
				</button>
			</div>

			<div
				class="aicm-widget__messages"
				id="aicm-messages"
				role="log"
				aria-live="polite"
				aria-relevant="additions"
			></div>

			<div
				class="aicm-widget__history"
				id="aicm-history"
				role="region"
				aria-label="<?php esc_attr_e( 'Previous chats', 'ai-chatmate' ); ?>"
			>
				<div class="aicm-widget__history-head">
					<?php esc_html_e( 'Previous chats', 'ai-chatmate' ); ?>
				</div>
				<ul id="aicm-history-list" class="aicm-widget__history-list"></ul>
			</div>

			<div class="aicm-widget__footer">
				<textarea
					class="aicm-widget__input"
					id="aicm-input"
					rows="1"
					maxlength="2000"
					aria-label="<?php esc_attr_e( 'Your message', 'ai-chatmate' ); ?>"
				></textarea>
				<button
					type="button"
					class="aicm-widget__send"
					id="aicm-send"
					aria-label="<?php esc_attr_e( 'Send message', 'ai-chatmate' ); ?>"
				>
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
						viewBox="0 0 24 24" fill="none" stroke="currentColor"
						stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
						aria-hidden="true" focusable="false">
						<line x1="22" y1="2" x2="11" y2="13"/>
						<polygon points="22 2 15 22 11 13 2 9 22 2"/>
					</svg>
				</button>
			</div>
		</div>
		<!-- Conciera widget — end -->
		<?php
	}

	/**
	 * Handle the [ai_chatmate] shortcode.
	 *
	 * The floating widget is always rendered in wp_footer, so the shortcode
	 * only needs to ensure the assets are enqueued (relevant when a caching
	 * layer has stripped them on pages that don't normally load scripts).
	 * It intentionally outputs nothing.
	 *
	 * Usage: [ai_chatmate]
	 *
	 * @param array $atts Shortcode attributes (currently unused).
	 * @return string Empty string — widget is in the footer.
	 */
	public function shortcode( array $atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! wp_script_is( 'aicm-widget', 'enqueued' ) ) {
			$this->enqueue_assets();
		}

		return '';
	}
}
