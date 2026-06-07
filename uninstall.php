<?php
/**
 * AI ChatMate — Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin (Plugins → Delete).
 * This is NOT called on deactivation — only on permanent removal.
 *
 * Removes ALL plugin data:
 *  - Custom database tables
 *  - wp_options entries
 *  - Transients
 *  - Scheduled cron events
 *  - Post meta (if any was written)
 *
 * Multisite: we iterate every sub-site and clean each one individually.
 *
 * @package AIChatMate
 */

// WordPress sets this constant before calling uninstall.php.
// If it is not set, someone accessed this file directly — exit immediately.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Remove all data for the current site.
 *
 * Extracted as a function so it can be called for each sub-site in multisite.
 */
function aicm_uninstall_single_site(): void {
	global $wpdb;

	// -----------------------------------------------------------------
	// 1. Drop custom tables.
	//
	// Table names come from $wpdb->prefix (server-controlled) and the
	// plugin's own suffix — not user input — so interpolation is safe
	// after esc_sql().
	// -----------------------------------------------------------------
	$tables = array(
		$wpdb->prefix . 'aicm_chunks',
		$wpdb->prefix . 'aicm_qa',
		$wpdb->prefix . 'aicm_logs',
		$wpdb->prefix . 'aicm_queue',
	);

	foreach ( $tables as $table ) {
		$safe_table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is from $wpdb->prefix + fixed suffix, escaped with esc_sql().
		$wpdb->query( "DROP TABLE IF EXISTS `{$safe_table}`" );
	}

	// -----------------------------------------------------------------
	// 2. Delete wp_options entries.
	// -----------------------------------------------------------------
	$options = array(
		'aicm_settings',
		'aicm_db_version',
		'aicm_index_status',
		'aicm_schema',
		'aicm_api_key_openai',
		'aicm_api_key_anthropic',
		'aicm_api_key_google',
		'aicm_monthly_usage',
		'aicm_field_config',
		'aicm_onboarded',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// -----------------------------------------------------------------
	// 3. Delete all transients created by this plugin.
	//
	// LIKE queries on wp_options are the only practical way to bulk-delete
	// transients by prefix — WordPress has no built-in API for this.
	// The pattern '_transient_aicm_%' catches all plugin transients.
	// -----------------------------------------------------------------
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '_transient_aicm_%'
		   OR option_name LIKE '_transient_timeout_aicm_%'
		   OR option_name LIKE '_site_transient_aicm_%'
		   OR option_name LIKE '_site_transient_timeout_aicm_%'"
	);

	// -----------------------------------------------------------------
	// 4. Clear scheduled cron events.
	// -----------------------------------------------------------------
	$cron_hooks = array(
		'aicm_weekly_schema_scan',
		'aicm_process_index_queue',
	);

	foreach ( $cron_hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	// -----------------------------------------------------------------
	// 5. Delete any post meta written by this plugin.
	// (None in Phase 1, but the placeholder keeps uninstall complete
	// for future phases that may add post meta.)
	// -----------------------------------------------------------------
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->postmeta}
		WHERE meta_key LIKE '_aicm_%'"
	);
}

// -----------------------------------------------------------------
// Run for a standard (single) site install.
// -----------------------------------------------------------------
aicm_uninstall_single_site();

// -----------------------------------------------------------------
// Multisite: also clean every sub-site's own tables and options.
//
// switch_to_blog() changes $wpdb->prefix so the same helper function
// correctly targets each sub-site's tables.
// -----------------------------------------------------------------
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		aicm_uninstall_single_site();
		restore_current_blog();
	}
}
