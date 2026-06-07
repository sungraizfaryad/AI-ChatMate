<?php
/**
 * Plugin Activator
 *
 * Handles everything that needs to happen when the plugin is activated:
 * creating database tables, setting default options, and scheduling cron events.
 *
 * Uses dbDelta() for table creation so that future updates can ADD columns
 * safely without dropping existing data.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Activator
 */
class AICM_Activator {

	/**
	 * Run all activation routines.
	 *
	 * Called by register_activation_hook() in the main plugin file.
	 */
	public static function activate(): void {
		self::create_tables();
		self::set_default_options();
		self::schedule_cron_events();

		// Store the DB version so future updates can run migrations.
		update_option( 'aicm_db_version', AICM_VERSION );

		// Flush rewrite rules in case future phases register custom endpoints.
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Database tables
	// -------------------------------------------------------------------------

	/**
	 * Create (or upgrade) all custom database tables.
	 *
	 * dbDelta() will:
	 *  - Create the table if it does not exist.
	 *  - Add any missing columns to an existing table.
	 *  - It will NOT remove columns or change column types — safe for upgrades.
	 *
	 * Formatting rules dbDelta() requires (strictly followed below):
	 *  - Two spaces before PRIMARY KEY.
	 *  - No trailing comma on the last field before KEY definitions.
	 *  - Each KEY on its own line.
	 *  - Field types in lowercase.
	 */
	public static function create_tables(): void {
		global $wpdb;

		// Required for dbDelta().
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// -----------------------------------------------------------------
		// Table 1: aicm_chunks
		// Stores content chunks and their embedding vectors.
		//
		// embedding: packed binary (pack('f*', ...)) — 1536 floats × 4 bytes
		// = 6,144 bytes per row. Use LONGBLOB to future-proof for larger
		// embedding models. MEDIUMBLOB (16 MB limit) would also suffice today
		// but LONGBLOB ensures we never hit a wall.
		//
		// content_hash: MD5 of the chunk text — used to skip re-embedding
		// when content has not changed.
		// -----------------------------------------------------------------
		$table_chunks = $wpdb->prefix . 'aicm_chunks';
		$sql_chunks   = "CREATE TABLE {$table_chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			post_type varchar(50) NOT NULL DEFAULT '',
			chunk_index smallint(5) unsigned NOT NULL DEFAULT '0',
			chunk_text longtext NOT NULL,
			content_hash char(32) NOT NULL DEFAULT '',
			embedding longblob DEFAULT NULL,
			token_count smallint(5) unsigned NOT NULL DEFAULT '0',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY idx_post_id (post_id),
			KEY idx_content_hash (content_hash),
			KEY idx_post_type (post_type)
		) {$charset_collate};";

		// -----------------------------------------------------------------
		// Table 2: aicm_qa
		// Admin-managed custom Q&A pairs.
		// These are checked first — before RAG — so admins can guarantee
		// specific answers to specific questions without relying on AI.
		//
		// question_embedding: same binary format as chunks.embedding.
		// priority: 1 (highest) to 100 (lowest). Default 50.
		// -----------------------------------------------------------------
		$table_qa  = $wpdb->prefix . 'aicm_qa';
		$sql_qa    = "CREATE TABLE {$table_qa} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question text NOT NULL,
			answer longtext NOT NULL,
			question_embedding longblob DEFAULT NULL,
			priority tinyint(3) unsigned NOT NULL DEFAULT '50',
			is_active tinyint(1) NOT NULL DEFAULT '1',
			match_count int(10) unsigned NOT NULL DEFAULT '0',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY idx_is_active (is_active),
			KEY idx_priority (priority)
		) {$charset_collate};";

		// -----------------------------------------------------------------
		// Table 3: aicm_logs
		// Conversation log storage (disabled by default — GDPR compliance).
		//
		// user_id: 0 for guests. Never store IP addresses in plaintext —
		// rate limiting uses hashed IPs stored only in transients.
		//
		// tokens_input / tokens_output: used for cost calculation.
		// response_ms: milliseconds the OpenAI call took — useful for
		// identifying slow queries in the analytics dashboard.
		// -----------------------------------------------------------------
		$table_logs = $wpdb->prefix . 'aicm_logs';
		$sql_logs   = "CREATE TABLE {$table_logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id char(36) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT '0',
			role varchar(20) NOT NULL DEFAULT '',
			content text NOT NULL,
			function_name varchar(100) DEFAULT NULL,
			function_args text DEFAULT NULL,
			tokens_input smallint(5) unsigned NOT NULL DEFAULT '0',
			tokens_output smallint(5) unsigned NOT NULL DEFAULT '0',
			response_ms smallint(5) unsigned NOT NULL DEFAULT '0',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY idx_session_id (session_id),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		// -----------------------------------------------------------------
		// Table 4: aicm_queue
		// Background indexing job queue.
		//
		// Used when the admin triggers a full re-index. Posts are batched
		// here (50 at a time via WP Cron) so we never exhaust server memory
		// or execution time in a single request.
		//
		// attempts: incremented on failure. After 3 attempts the row is
		// marked 'failed' and skipped — prevents infinite retry loops.
		// -----------------------------------------------------------------
		$table_queue = $wpdb->prefix . 'aicm_queue';
		$sql_queue   = "CREATE TABLE {$table_queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			action varchar(20) NOT NULL DEFAULT 'index',
			status varchar(20) NOT NULL DEFAULT 'pending',
			error_message text DEFAULT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT '0',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_post_id (post_id)
		) {$charset_collate};";

		dbDelta( $sql_chunks );
		dbDelta( $sql_qa );
		dbDelta( $sql_logs );
		dbDelta( $sql_queue );
	}

	// -------------------------------------------------------------------------
	// Default options
	// -------------------------------------------------------------------------

	/**
	 * Set default plugin settings in wp_options.
	 *
	 * add_option() is a no-op when the option already exists, which means
	 * re-activation after update does NOT overwrite user-customised settings.
	 */
	private static function set_default_options(): void {
		add_option(
			'aicm_settings',
			array(
				// AI provider.
				'active_provider'   => 'openai',
				'chat_model'        => 'gpt-4o-mini',
				'embedding_model'   => 'text-embedding-3-small',
				'ai_personality'    => 'friendly',

				// Indexing.
				'index_post_types'  => array( 'post', 'page' ),
				'auto_sync'         => true,
				'cron_schedule'     => 'weekly',
				'batch_size'        => 50,

				// Rate limiting.
				'rate_limit_msgs'   => 20,    // messages per minute per IP.
				'session_token_cap' => 5000,  // max tokens per session.

				// Budget.
				'monthly_budget'    => 0.00,  // 0 = unlimited.

				// Privacy / GDPR.
				'logging_enabled'   => false, // disabled by default.

				// Widget.
				'widget_position'   => 'bottom-right',
				'widget_color'      => '#0073aa',
				'welcome_message'   => __( 'Hi! How can I help you today?', 'ai-chatmate' ),

				// Results display.
				'results_display'   => 'plugin_page', // plugin_page | theme_archive | in_chat.
			)
		);

		// Separate option for API keys (encrypted). Never stored with other settings.
		add_option( 'aicm_api_key_openai',    '' );
		add_option( 'aicm_api_key_anthropic', '' );
		add_option( 'aicm_api_key_google',    '' );

		// Index status — updated by the background processor.
		add_option(
			'aicm_index_status',
			array(
				'total_chunks'  => 0,
				'pending'       => 0,
				'is_running'    => false,
				'last_indexed'  => null,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Cron events
	// -------------------------------------------------------------------------

	/**
	 * Schedule recurring cron events.
	 *
	 * We check wp_next_scheduled() before scheduling to prevent duplicate
	 * entries when the plugin is activated on a site that already has the
	 * event scheduled (e.g. after a plugin update deactivation/reactivation).
	 */
	private static function schedule_cron_events(): void {
		// Weekly schema re-scan — discovers new post types, taxonomies, fields.
		if ( ! wp_next_scheduled( 'aicm_weekly_schema_scan' ) ) {
			wp_schedule_event( time(), 'weekly', 'aicm_weekly_schema_scan' );
		}

		// Indexing queue processor — runs every 5 minutes when there are
		// pending items in the queue.
		if ( ! wp_next_scheduled( 'aicm_process_index_queue' ) ) {
			wp_schedule_event( time(), 'aicm_five_minutes', 'aicm_process_index_queue' );
		}
	}
}
