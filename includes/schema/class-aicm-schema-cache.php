<?php
/**
 * Schema Cache
 *
 * A thin wrapper around wp_options for storing and retrieving the
 * auto-discovered site schema.
 *
 * Why wp_options (not a transient)?
 *  - We want the schema to persist indefinitely until explicitly invalidated.
 *  - Transients auto-expire, which would trigger a re-scan on every expiry
 *    and could block a REST request mid-flow.
 *  - The weekly re-scan cron keeps the data fresh without silent expiry.
 *
 * Autoload = false:
 *  - The schema can be a large serialised array (tens of KB for complex sites).
 *  - Setting autoload to false means WordPress does NOT load it on every page
 *    request — only when explicitly asked. This is the correct pattern for
 *    large, infrequently-needed options.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Schema_Cache
 */
class AICM_Schema_Cache {

	/**
	 * The wp_options key where the schema is stored.
	 */
	private const OPTION_KEY = 'aicm_schema';

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the cached schema.
	 *
	 * Returns null if no schema has been discovered yet, or if the stored
	 * value is not a valid array (e.g. corrupted).
	 *
	 * @return array|null The schema array, or null if not available.
	 */
	public static function get(): ?array {
		$stored = get_option( self::OPTION_KEY );

		if ( empty( $stored ) || ! is_array( $stored ) ) {
			return null;
		}

		return $stored;
	}

	/**
	 * Check whether a valid schema is cached.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		return null !== self::get();
	}

	/**
	 * Get the timestamp when the schema was last generated.
	 *
	 * @return string|null MySQL datetime string, or null if no schema exists.
	 */
	public static function last_generated_at(): ?string {
		$schema = self::get();
		return $schema['generated_at'] ?? null;
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Store the schema in wp_options.
	 *
	 * Passing false as the third argument to update_option() prevents
	 * WordPress from marking this option for autoloading. On large sites
	 * this option can be 50–200 KB; we should not load it on every request.
	 *
	 * @param array $schema The full schema array produced by AICM_Schema_Discovery.
	 */
	public static function set( array $schema ): void {
		update_option( self::OPTION_KEY, $schema, false );
	}

	/**
	 * Remove the cached schema.
	 *
	 * After calling this, the next call to get() returns null, and
	 * the REST /schema endpoint will indicate that schema is not yet available.
	 */
	public static function invalidate(): void {
		delete_option( self::OPTION_KEY );
	}
}
