<?php
/**
 * Admin Controller
 *
 * Handles all WordPress admin area integration:
 *  - Registers the admin menu and submenu pages.
 *  - Enqueues admin scripts and styles ONLY on plugin pages.
 *  - Bootstraps the settings page.
 *
 * Performance note: scripts and styles are conditional. We check the
 * current admin screen before enqueueing — zero overhead on pages that
 * have nothing to do with this plugin.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Admin
 */
class AICM_Admin {

	/**
	 * Admin menu slug — used to identify our pages.
	 */
	private const MENU_SLUG = 'ai-chatmate';

	/**
	 * Single instance of this class.
	 *
	 * @var AICM_Admin|null
	 */
	private static ?AICM_Admin $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return AICM_Admin
	 */
	public static function instance(): AICM_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — private to enforce singleton.
	 */
	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level admin menu and all submenu pages.
	 */
	public function register_menus(): void {
		// Top-level menu — points to the Dashboard page.
		add_menu_page(
			__( 'AI ChatMate', 'ai-chatmate' ),        // Page title (browser tab).
			__( 'AI ChatMate', 'ai-chatmate' ),        // Menu label.
			'manage_options',                           // Capability required.
			self::MENU_SLUG,                            // Menu slug.
			array( $this, 'render_settings_page' ),    // Callback — Phase 1 shows settings.
			'dashicons-format-chat',                    // Icon.
			80                                          // Position (after Settings).
		);

		// Submenu: Settings (same page as top-level, avoids duplicate label).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings — AI ChatMate', 'ai-chatmate' ),
			__( 'Settings', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);

		// Submenu: Content Indexing — fully implemented in Phase 3.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Content Indexing — AI ChatMate', 'ai-chatmate' ),
			__( 'Content Indexing', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG . '-indexing',
			array( $this, 'render_indexing_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Schema — AI ChatMate', 'ai-chatmate' ),
			__( 'Schema', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG . '-schema',
			array( $this, 'render_schema_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Q&A Manager — AI ChatMate', 'ai-chatmate' ),
			__( 'Q&amp;A Manager', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG . '-qa',
			array( $this, 'render_qa_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Analytics — AI ChatMate', 'ai-chatmate' ),
			__( 'Analytics', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG . '-analytics',
			array( $this, 'render_analytics_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin CSS and JS — only on plugin admin pages.
	 *
	 * We check the current screen's base against our menu slug to avoid
	 * loading any assets on unrelated admin pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Only enqueue on our own admin pages.
		if ( ! $this->is_plugin_page( $hook_suffix ) ) {
			return;
		}

		// Pass the REST API base URL and a nonce to JavaScript.
		// The nonce uses 'wp_rest' action — this is what X-WP-Nonce expects.
		wp_add_inline_script(
			'wp-api',  // wp-api is always loaded on admin pages — safe to depend on it.
			sprintf(
				'var aicmAdmin = %s;',
				wp_json_encode(
					array(
						'restUrl'   => esc_url_raw( rest_url( 'aicm/v1' ) ),
						'nonce'     => wp_create_nonce( 'wp_rest' ),
						'version'   => AICM_VERSION,
						'i18n'      => array(
							'saving'          => __( 'Saving…', 'ai-chatmate' ),
							'saved'           => __( 'Settings saved.', 'ai-chatmate' ),
							'testing'         => __( 'Testing connection…', 'ai-chatmate' ),
							'connected'       => __( 'Connected!', 'ai-chatmate' ),
							'error'           => __( 'Something went wrong. Please try again.', 'ai-chatmate' ),
						),
					)
				)
			),
			'before'
		);
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the Settings page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-chatmate' ) );
		}

		include AICM_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Render the Content Indexing page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_indexing_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-chatmate' ) );
		}

		include AICM_PLUGIN_DIR . 'admin/views/indexing.php';
	}

	/**
	 * Render the Schema discovery page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_schema_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-chatmate' ) );
		}

		include AICM_PLUGIN_DIR . 'admin/views/schema.php';
	}

	/**
	 * Render the Q&A Manager page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_qa_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-chatmate' ) );
		}

		include AICM_PLUGIN_DIR . 'admin/views/qa.php';
	}

	/**
	 * Render the Analytics page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_analytics_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-chatmate' ) );
		}

		include AICM_PLUGIN_DIR . 'admin/views/analytics.php';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current admin page belongs to this plugin.
	 *
	 * @param string $hook_suffix The hook suffix from admin_enqueue_scripts.
	 * @return bool
	 */
	private function is_plugin_page( string $hook_suffix ): bool {
		// WordPress generates hook suffixes like:
		//   toplevel_page_ai-chatmate
		//   ai-chatmate_page_ai-chatmate-indexing
		// Both contain our menu slug.
		return str_contains( $hook_suffix, self::MENU_SLUG );
	}
}
