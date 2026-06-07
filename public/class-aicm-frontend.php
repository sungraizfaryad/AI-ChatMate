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

	// ── Hooks ─────────────────────────────────────────────────────────────────

	/**
	 * Enqueue the widget stylesheet and script; inject settings into JS.
	 *
	 * Callback for the `wp_enqueue_scripts` action.
	 */
	public function enqueue_assets(): void {
		// Opt-in gate: do nothing unless the admin enabled the widget.
		if ( ! self::is_enabled() ) {
			return;
		}

		// ── Stylesheet ────────────────────────────────────────────────────
		wp_enqueue_style(
			'aicm-widget',
			AICM_PLUGIN_URL . 'public/css/aicm-widget.css',
			array(),
			AICM_VERSION
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
			AICM_VERSION,
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
				// Site name shown in the widget header.
				'siteName'       => get_bloginfo( 'name' ),
				// First message displayed when the widget is opened (optional).
				'welcomeMessage' => (string) AI_ChatMate::get_setting( 'welcome_message', '' ),
				// Input placeholder — localised so it can be translated.
				'placeholder'    => __( 'Ask a question…', 'ai-chatmate' ),
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
		// Opt-in gate: render nothing unless the admin enabled the widget.
		if ( ! self::is_enabled() ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		?>
		<!-- AI ChatMate widget — start -->
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
		<!-- AI ChatMate widget — end -->
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
