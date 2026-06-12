<?php
/**
 * Plugin Name: Conciera - AI Site Search & Content Finder
 * Plugin URI:  https://wordpress.org/plugins/ai-chatmate/
 * Description: Conciera is an AI search chatbot that helps your visitors find content on your website. It turns plain-language questions into a safe search of your own posts, pages, products, and listings, then answers right in a chat widget. Uses your OpenAI API key.
 * Version:     2.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      Sungraiz Faryad
 * Author URI:
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-chatmate
 * Domain Path: /languages
 *
 * @package AIChatMate
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version and path constants.
define( 'AICM_VERSION', '2.0.0' );
define( 'AICM_PLUGIN_FILE', __FILE__ );
define( 'AICM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AICM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum requirements.
 *
 * We declare these as constants so they can be checked programmatically
 * and referenced in admin notices without hard-coded strings.
 */
define( 'AICM_REQUIRED_PHP', '8.0' );
define( 'AICM_REQUIRED_WP', '6.0' );

// -------------------------------------------------------------------------
// Activation / deactivation hooks — registered before any class is loaded.
// -------------------------------------------------------------------------

register_activation_hook( __FILE__, 'aicm_activate_plugin' );
register_deactivation_hook( __FILE__, 'aicm_deactivate_plugin' );

/**
 * Plugin activation callback.
 *
 * Loads only the activator class (keeps the memory footprint small) and
 * delegates all setup work to it.
 */
function aicm_activate_plugin(): void {
	require_once AICM_PLUGIN_DIR . 'includes/class-aicm-activator.php';
	AICM_Activator::activate();
}

/**
 * Plugin deactivation callback.
 */
function aicm_deactivate_plugin(): void {
	require_once AICM_PLUGIN_DIR . 'includes/class-aicm-deactivator.php';
	AICM_Deactivator::deactivate();
}

// -------------------------------------------------------------------------
// Requirement check — show an admin notice and bail if requirements unmet.
// Runs as early as possible so we never load plugin code on an incompatible
// environment.
// -------------------------------------------------------------------------

/**
 * Check minimum PHP and WordPress version requirements.
 *
 * @return bool True if requirements are met.
 */
function aicm_requirements_met(): bool {
	return version_compare( PHP_VERSION, AICM_REQUIRED_PHP, '>=' )
		&& version_compare( get_bloginfo( 'version' ), AICM_REQUIRED_WP, '>=' );
}

if ( ! aicm_requirements_met() ) {
	add_action(
		'admin_notices',
		function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: 1: Required PHP version, 2: Required WP version */
						__(
							'<strong>Conciera</strong> requires PHP %1$s and WordPress %2$s or higher. Please upgrade your environment.',
							'ai-chatmate'
						),
						esc_html( AICM_REQUIRED_PHP ),
						esc_html( AICM_REQUIRED_WP )
					)
				)
			);
		}
	);
	return; // Do not load any further plugin code.
}

// -------------------------------------------------------------------------
// Main plugin class — singleton.
// Loaded only after requirements are confirmed.
// -------------------------------------------------------------------------

/**
 * Main plugin class.
 *
 * Responsible only for bootstrapping: loading dependencies and registering
 * top-level WordPress hooks. Business logic lives in dedicated classes.
 */
final class AI_ChatMate {

	/**
	 * Single instance of this class.
	 *
	 * @var AI_ChatMate|null
	 */
	private static ?AI_ChatMate $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return AI_ChatMate
	 */
	public static function instance(): AI_ChatMate {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — private to enforce singleton.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Prevent cloning of the singleton instance.
	 */
	private function __clone() {}

	/**
	 * Load all required class files.
	 *
	 * Admin-only and CLI-only files are loaded conditionally to avoid
	 * loading dead code on the frontend or in non-CLI contexts.
	 */
	private function load_dependencies(): void {
		// Core utilities (always needed).
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-encryption.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-field-config.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-onboarding.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-billing.php';

		// Schema classes — needed by REST API and cron handlers.
		require_once AICM_PLUGIN_DIR . 'includes/schema/class-aicm-schema-cache.php';
		require_once AICM_PLUGIN_DIR . 'includes/schema/class-aicm-field-detector.php';
		require_once AICM_PLUGIN_DIR . 'includes/schema/class-aicm-schema-discovery.php';
		require_once AICM_PLUGIN_DIR . 'includes/schema/class-aicm-schema-catalog.php';

		// Indexing pipeline — loaded on every request because:
		// a) WP-Cron fires via HTTP on any page load.
		// b) Auto-sync hooks (save_post, before_delete_post) fire everywhere.
		// c) REST /index/start calls AICM_Index_Manager from any origin.
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-content-fetcher.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-text-extractor.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-chunker.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-embedder.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-index-manager.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-auto-sync.php';

		// Chat engine — loaded on every request because REST is always active.
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-rag-retriever.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-query-builder.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-qa-manager.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-conversation-handler.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-chat-log.php';
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-leads.php';

		// REST API (always needed — REST is active on all requests).
		require_once AICM_PLUGIN_DIR . 'includes/class-aicm-rest-api.php';

		// Admin-only classes.
		if ( is_admin() ) {
			require_once AICM_PLUGIN_DIR . 'admin/class-aicm-admin.php';
		}

		// Frontend-only class (not needed in admin or WP-Cron context).
		if ( ! is_admin() ) {
			require_once AICM_PLUGIN_DIR . 'public/class-aicm-frontend.php';
		}
	}

	/**
	 * Register all WordPress action and filter hooks.
	 *
	 * Hooks that drive admin UI, REST registration, and background tasks.
	 * No business logic here — just hookup.
	 */
	private function register_hooks(): void {
		// Register our custom 5-minute cron interval for the indexing queue.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// REST API registration runs on every request (REST is stateless).
		add_action( 'rest_api_init', array( $this, 'init_rest_api' ) );

		// Admin initialisation only in the dashboard.
		if ( is_admin() ) {
			add_action( 'init', array( $this, 'init_admin' ) );
		}

		// Frontend widget — only on frontend requests (not admin, not WP-Cron).
		if ( ! is_admin() ) {
			add_action( 'init', array( $this, 'init_frontend' ) );
		}

		// Cron action handlers — must be registered on all requests so WP-Cron
		// can call them via HTTP when they are scheduled to fire.
		add_action( 'aicm_weekly_schema_scan', array( $this, 'run_weekly_schema_scan' ) );
		add_action( 'aicm_process_index_queue', array( $this, 'process_index_queue' ) );

		// Background-mode indexing loopback endpoint. Both hooks are required:
		// loopback requests carry no cookies, so they always arrive
		// unauthenticated (nopriv); the secret-key check happens inside.
		add_action( 'wp_ajax_aicm_async_index', array( 'AICM_Index_Manager', 'handle_async_request' ) );
		add_action( 'wp_ajax_nopriv_aicm_async_index', array( 'AICM_Index_Manager', 'handle_async_request' ) );

		// Post lifecycle hooks — auto-sync must fire on every request type
		// (admin, frontend, REST, WP-Cron) since post saves can happen anywhere.
		AICM_Auto_Sync::init();
	}

	/**
	 * Register custom WP-Cron intervals used by this plugin.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['aicm_five_minutes'] ) ) {
			$schedules['aicm_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (Conciera)', 'ai-chatmate' ),
			);
		}
		return $schedules;
	}

	/**
	 * Initialise the REST API controller.
	 *
	 * Callback for the `rest_api_init` action.
	 */
	public function init_rest_api(): void {
		$controller = new AICM_REST_API();
		$controller->register_routes();
	}

	/**
	 * Initialise the admin area.
	 *
	 * Callback for the `init` action (admin-only).
	 */
	public function init_admin(): void {
		AICM_Admin::instance();
	}

	/**
	 * Initialise the frontend widget.
	 *
	 * Callback for the `init` action (frontend-only).
	 * Bootstraps AICM_Frontend which registers enqueue and footer hooks.
	 */
	public function init_frontend(): void {
		AICM_Frontend::instance();
	}

	/**
	 * Run the weekly schema re-scan.
	 *
	 * Callback for the `aicm_weekly_schema_scan` WP-Cron action.
	 * Discovers all post types, taxonomies, and custom fields; caches
	 * the result in wp_options so the AI always has an up-to-date picture
	 * of the site's content structure.
	 */
	public function run_weekly_schema_scan(): void {
		AICM_Schema_Discovery::run();
	}

	/**
	 * Process the next batch of the content indexing queue.
	 *
	 * Callback for the `aicm_process_index_queue` WP-Cron action.
	 * Fires every 5 minutes. If the queue is empty or a concurrency lock
	 * is held, the method exits immediately without doing any work.
	 */
	public function process_index_queue(): void {
		AICM_Index_Manager::process_queue_batch();
	}

	/**
	 * Retrieve a plugin setting.
	 *
	 * @param string|null $key     Setting key, or null to return all settings.
	 * @param mixed       $default Default value when the key is absent.
	 * @return mixed
	 */
	public static function get_setting( ?string $key = null, mixed $default = null ): mixed {
		$settings = get_option( 'aicm_settings', array() );

		if ( null === $key ) {
			return $settings;
		}

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Persist a single plugin setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 */
	public static function update_setting( string $key, mixed $value ): void {
		$settings         = self::get_setting();
		$settings[ $key ] = $value;
		update_option( 'aicm_settings', $settings );
	}
}

/**
 * Global helper — returns the single plugin instance.
 *
 * Allows other code to call aicm() without using statics directly.
 *
 * @return AI_ChatMate
 */
function aicm(): AI_ChatMate {
	return AI_ChatMate::instance();
}

// Boot the plugin on `plugins_loaded` so that all plugins are available and
// WordPress is in a consistent state before we do anything.
add_action( 'plugins_loaded', 'aicm' );
