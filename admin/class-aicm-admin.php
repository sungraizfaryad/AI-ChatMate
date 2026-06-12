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
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_aicm_download_log', array( $this, 'download_chat_log' ) );
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
			__( 'Conciera', 'ai-chatmate' ),        // Page title (browser tab).
			__( 'Conciera', 'ai-chatmate' ),        // Menu label.
			'manage_options',                           // Capability required.
			self::MENU_SLUG,                            // Menu slug.
			array( $this, 'render_settings_page' ),    // Callback — Phase 1 shows settings.
			'dashicons-format-chat',                    // Icon.
			80                                          // Position (after Settings).
		);

		// Submenu: Content Indexing — fully implemented in Phase 3.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Content Indexing — Conciera', 'ai-chatmate' ),
			__( 'Content Indexing', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG . '-indexing',
			array( $this, 'render_indexing_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Schema — Conciera', 'ai-chatmate' ),
			__( 'Schema', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG . '-schema',
			array( $this, 'render_schema_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Q&A Manager — Conciera', 'ai-chatmate' ),
			__( 'Q&amp;A Manager', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG . '-qa',
			array( $this, 'render_qa_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Analytics — Conciera', 'ai-chatmate' ),
			__( 'Analytics', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG . '-analytics',
			array( $this, 'render_analytics_page' )
		);

		// Submenu: Settings — registered LAST so it sits at the bottom of the
		// submenu list. Uses the parent slug, so the top-level menu item still
		// lands on Settings (and the setup wizard on first run).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings — Conciera', 'ai-chatmate' ),
			__( 'Settings', 'ai-chatmate' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
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
						'restUrl' => esc_url_raw( rest_url( 'aicm/v1' ) ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
						'version' => AICM_VERSION,
						'i18n'    => array(
							'saving'    => __( 'Saving…', 'ai-chatmate' ),
							'saved'     => __( 'Settings saved.', 'ai-chatmate' ),
							'testing'   => __( 'Testing connection…', 'ai-chatmate' ),
							'connected' => __( 'Connected!', 'ai-chatmate' ),
							'error'     => __( 'Something went wrong. Please try again.', 'ai-chatmate' ),
						),
					)
				)
			),
			'before'
		);

		// Wizard assets — only on the top-level page (where the wizard renders).
		if ( str_contains( $hook_suffix, 'toplevel_page_' . self::MENU_SLUG ) ) {
			wp_enqueue_style(
				'aicm-wizard',
				AICM_PLUGIN_URL . 'admin/css/aicm-wizard.css',
				array(),
				AICM_VERSION
			);
			wp_enqueue_script(
				'aicm-wizard',
				AICM_PLUGIN_URL . 'admin/js/aicm-wizard.js',
				array( 'wp-api' ),
				AICM_VERSION,
				true
			);
			wp_add_inline_script(
				'wp-api',
				sprintf( 'aicmAdmin.settingsUrl = %s;', wp_json_encode( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) ),
				'after'
			);
		}

		// ── Settings page (top-level) ─────────────────────────────────────
		if ( str_contains( $hook_suffix, 'toplevel_page_' . self::MENU_SLUG ) ) {
			wp_enqueue_style(
				'aicm-settings',
				AICM_PLUGIN_URL . 'admin/css/aicm-settings.css',
				array(),
				AICM_VERSION
			);
			wp_enqueue_script(
				'aicm-settings',
				AICM_PLUGIN_URL . 'admin/js/aicm-settings.js',
				array( 'wp-api' ),
				AICM_VERSION,
				true
			);
		}

		// ── Content Indexing page ─────────────────────────────────────────
		if ( str_contains( $hook_suffix, self::MENU_SLUG . '-indexing' ) ) {
			wp_enqueue_style(
				'aicm-indexing',
				AICM_PLUGIN_URL . 'admin/css/aicm-indexing.css',
				array(),
				AICM_VERSION
			);
			wp_enqueue_script(
				'aicm-indexing',
				AICM_PLUGIN_URL . 'admin/js/aicm-indexing.js',
				array( 'wp-api' ),
				AICM_VERSION,
				true
			);
			$index_status = get_option( 'aicm_index_status', array() );
			wp_localize_script(
				'aicm-indexing',
				'aicmIndexing',
				array(
					'isRunning' => ! empty( $index_status['is_running'] ),
					'mode'      => (string) AI_ChatMate::get_setting( 'indexing_mode', 'frontend' ),
					'i18n'      => array(
						'inProgress'        => __( 'Indexing in progress…', 'ai-chatmate' ),
						'complete'          => __( 'Indexing complete', 'ai-chatmate' ),
						'couldNotStart'     => __( 'Could not start indexing. Please try again.', 'ai-chatmate' ),
						'requestFailed'     => __( 'Request failed. Please check your connection and try again.', 'ai-chatmate' ),
						'stopped'           => __( 'Indexing stopped. Pending items have been cleared.', 'ai-chatmate' ),
						'couldNotStop'      => __( 'Could not stop indexing. Please try again.', 'ai-chatmate' ),
						'finished'          => __( 'Indexing finished. All queued content has been processed.', 'ai-chatmate' ),
						'startedBackground' => __( 'Indexing started in the background — you can close this page. Progress updates below while you stay.', 'ai-chatmate' ),
						'actIndexed'        => __( 'Indexed ✓', 'ai-chatmate' ),
						'actRemoved'        => __( 'Removed', 'ai-chatmate' ),
						'actFailed'         => __( 'Failed ✕', 'ai-chatmate' ),
						'stalledKicking'    => __( 'Indexing has not made progress for a while — it may have timed out. Resuming it automatically now…', 'ai-chatmate' ),
						'resumed'           => __( 'Indexing resumed and is making progress again.', 'ai-chatmate' ),
						'stalledFailed'     => __( 'Indexing appears stalled and the automatic resume failed. Click "Start Indexing" to resume, or switch the processing mode to "While the Indexing page is open" in Settings → Indexing.', 'ai-chatmate' ),
					),
				)
			);
		}

		// ── Schema page ───────────────────────────────────────────────────
		if ( str_contains( $hook_suffix, self::MENU_SLUG . '-schema' ) ) {
			wp_enqueue_style(
				'aicm-schema',
				AICM_PLUGIN_URL . 'admin/css/aicm-schema.css',
				array(),
				AICM_VERSION
			);
			wp_enqueue_script(
				'aicm-schema',
				AICM_PLUGIN_URL . 'admin/js/aicm-schema.js',
				array( 'wp-api' ),
				AICM_VERSION,
				true
			);
			wp_localize_script(
				'aicm-schema',
				'aicmSchema',
				array(
					'i18n' => array(
						'scanning'      => __( 'Scanning…', 'ai-chatmate' ),
						'doneReloading' => __( 'Done! Reloading…', 'ai-chatmate' ),
						'errorRetry'    => __( 'Error. Please try again.', 'ai-chatmate' ),
						'networkError'  => __( 'Network error. Please try again.', 'ai-chatmate' ),
					),
				)
			);
		}

		// ── Q&A Manager page ──────────────────────────────────────────────
		if ( str_contains( $hook_suffix, self::MENU_SLUG . '-qa' ) ) {
			wp_enqueue_style(
				'aicm-qa',
				AICM_PLUGIN_URL . 'admin/css/aicm-qa.css',
				array(),
				AICM_VERSION
			);
			wp_enqueue_script(
				'aicm-qa',
				AICM_PLUGIN_URL . 'admin/js/aicm-qa.js',
				array( 'wp-api' ),
				AICM_VERSION,
				true
			);
			wp_localize_script(
				'aicm-qa',
				'aicmQA',
				array(
					'i18n' => array(
						'editPair'      => __( 'Edit Q&A Pair', 'ai-chatmate' ),
						'addPair'       => __( 'Add New Q&A Pair', 'ai-chatmate' ),
						'active'        => __( 'Active', 'ai-chatmate' ),
						'inactive'      => __( 'Inactive', 'ai-chatmate' ),
						'edit'          => __( 'Edit', 'ai-chatmate' ),
						'delete'        => __( 'Delete', 'ai-chatmate' ),
						'confirmDelete' => __( 'Delete this Q&A pair? This cannot be undone.', 'ai-chatmate' ),
						'required'      => __( 'Question and answer are required.', 'ai-chatmate' ),
						'saving'        => __( 'Saving…', 'ai-chatmate' ),
						'saved'         => __( 'Saved.', 'ai-chatmate' ),
					),
				)
			);
		}
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

		// First-run (or ?onboarding=1): show the setup wizard instead of settings.
		// This is a read-only view toggle, no state change, so no nonce is needed.
		$force_wizard = isset( $_GET['onboarding'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view toggle.
		if ( $force_wizard || ! AICM_Onboarding::is_complete() ) {
			include AICM_PLUGIN_DIR . 'admin/views/onboarding.php';
			return;
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

	/**
	 * Stream a chat log file to an administrator (admin-post.php endpoint).
	 *
	 * Security: manage_options capability + nonce; the filename is validated
	 * by AICM_Chat_Log::resolve_download() against a strict whitelist pattern,
	 * so traversal or arbitrary-file reads are impossible.
	 */
	public function download_chat_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download logs.', 'ai-chatmate' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'aicm_download_log' );

		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['file'] ) ) : '';
		$path = AICM_Chat_Log::resolve_download( $file );

		if ( '' === $path ) {
			wp_die( esc_html__( 'Log file not found.', 'ai-chatmate' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="conciera-chat-' . basename( $path ) . '"' );
		// Content-Length lies (truncates the download) when zlib output
		// compression rewrites the body — only send it when that is off.
		if ( ! ini_get( 'zlib.output_compression' ) ) {
			header( 'Content-Length: ' . (string) filesize( $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
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
		// toplevel_page_ai-chatmate
		// ai-chatmate_page_ai-chatmate-indexing
		// Both contain our menu slug.
		return str_contains( $hook_suffix, self::MENU_SLUG );
	}
}
