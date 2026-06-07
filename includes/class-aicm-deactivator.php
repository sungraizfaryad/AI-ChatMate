<?php
/**
 * Plugin Deactivator
 *
 * Handles cleanup when the plugin is deactivated (NOT uninstalled).
 *
 * Deactivation vs Uninstall distinction:
 *  - Deactivation: plugin is turned off but data is kept. A user may
 *    reactivate and expect their settings and indexed content to still work.
 *    We only stop background processes here.
 *  - Uninstall (uninstall.php): plugin is deleted. All data is removed.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Deactivator
 */
class AICM_Deactivator {

	/**
	 * Run all deactivation routines.
	 *
	 * Called by register_deactivation_hook() in the main plugin file.
	 */
	public static function deactivate(): void {
		self::clear_cron_events();
		self::clear_transients();

		// Mark the index as not running — prevents a stale "in progress" state
		// from showing in the admin after reactivation.
		$status             = get_option( 'aicm_index_status', array() );
		$status['is_running'] = false;
		update_option( 'aicm_index_status', $status );

		// Flush rewrite rules on deactivation (good practice).
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Cron events
	// -------------------------------------------------------------------------

	/**
	 * Unschedule all cron events registered by this plugin.
	 *
	 * Using wp_clear_scheduled_hook() removes ALL instances of the hook,
	 * not just the next one.
	 */
	private static function clear_cron_events(): void {
		$hooks = array(
			'aicm_weekly_schema_scan',
			'aicm_process_index_queue',
		);

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	// -------------------------------------------------------------------------
	// Transients
	// -------------------------------------------------------------------------

	/**
	 * Delete all transients created by this plugin.
	 *
	 * Transients are ephemeral by design and will expire on their own, but
	 * cleaning them on deactivation ensures a clean state on reactivation
	 * and avoids stale cache data confusing the admin.
	 *
	 * We delete by LIKE pattern using a direct query — the only safe way to
	 * bulk-delete transients in WordPress without iterating thousands of keys.
	 */
	private static function clear_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_aicm_%'
			   OR option_name LIKE '_transient_timeout_aicm_%'"
		);

		// Also clear any site transients (used in multisite context).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_site_transient_aicm_%'
			   OR option_name LIKE '_site_transient_timeout_aicm_%'"
		);
	}
}
