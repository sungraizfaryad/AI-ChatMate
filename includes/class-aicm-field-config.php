<?php
/**
 * Field Config
 *
 * Stores the admin's per-post-type include/exclude and friendly-label choices
 * separately from the machine-discovered schema, so a Rescan never overwrites
 * them. apply() filters a schema array down to the kept fields and applies
 * label overrides; the chat catalog calls it so the AI only ever sees the
 * fields the admin chose to expose.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Field_Config
 */
class AICM_Field_Config {

	/** wp_options key. */
	private const OPTION_KEY = 'aicm_field_config';

	/**
	 * Sanitize a raw config payload (from REST) into the stored shape.
	 *
	 * @param mixed $raw Raw payload.
	 * @return array Sanitized config.
	 */
	public static function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $pt => $cfg ) {
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$pt_key           = sanitize_key( (string) $pt );
			$clean[ $pt_key ] = array(
				'taxonomies' => array(),
				'meta'       => array(),
			);

			foreach ( (array) ( $cfg['taxonomies'] ?? array() ) as $tax => $included ) {
				$clean[ $pt_key ]['taxonomies'][ sanitize_key( (string) $tax ) ] = self::truthy( $included );
			}

			foreach ( (array) ( $cfg['meta'] ?? array() ) as $key => $info ) {
				$info = is_array( $info ) ? $info : array();
				$clean[ $pt_key ]['meta'][ sanitize_key( (string) $key ) ] = array(
					'included' => self::truthy( $info['included'] ?? true ),
					'label'    => sanitize_text_field( wp_unslash( (string) ( $info['label'] ?? '' ) ) ),
				);
			}
		}

		return $clean;
	}

	/**
	 * Coerce a mixed value to a strict boolean (handles '0','no','false','').
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function truthy( $v ): bool {
		if ( is_string( $v ) ) {
			return ! in_array( strtolower( $v ), array( '', '0', 'no', 'false', 'off' ), true );
		}
		return (bool) $v;
	}

	/**
	 * Read the stored config.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Sanitize and persist a config payload.
	 *
	 * @param mixed $raw Raw payload.
	 * @return array The sanitized config that was stored.
	 */
	public static function save( $raw ): array {
		$clean = self::sanitize( $raw );
		update_option( self::OPTION_KEY, $clean, false );
		return $clean;
	}

	/**
	 * Filter a discovered schema by the stored config: drop excluded
	 * taxonomies/meta and apply label overrides. Post types with no config
	 * entry are returned unchanged (default include).
	 *
	 * @param array      $schema Discovered schema (AICM_Schema_Cache::get()).
	 * @param array|null $config Config to apply, or null to load the stored one.
	 * @return array Filtered schema.
	 */
	public static function apply( array $schema, ?array $config = null ): array {
		$config = $config ?? self::get();
		if ( empty( $config ) || empty( $schema['post_types'] ) ) {
			return $schema;
		}

		foreach ( $schema['post_types'] as $pt => $data ) {
			if ( ! isset( $config[ $pt ] ) ) {
				continue;
			}
			$pt_cfg = $config[ $pt ];

			foreach ( array_keys( (array) ( $data['taxonomies'] ?? array() ) ) as $tax ) {
				if ( isset( $pt_cfg['taxonomies'][ $tax ] ) && false === $pt_cfg['taxonomies'][ $tax ] ) {
					unset( $schema['post_types'][ $pt ]['taxonomies'][ $tax ] );
				}
			}

			foreach ( (array) ( $data['meta_fields'] ?? array() ) as $key => $info ) {
				$mcfg = $pt_cfg['meta'][ $key ] ?? null;
				if ( is_array( $mcfg ) ) {
					if ( false === ( $mcfg['included'] ?? true ) ) {
						unset( $schema['post_types'][ $pt ]['meta_fields'][ $key ] );
						continue;
					}
					if ( ! empty( $mcfg['label'] ) ) {
						$schema['post_types'][ $pt ]['meta_fields'][ $key ]['label'] = $mcfg['label'];
					}
				}
			}
		}

		return $schema;
	}
}
