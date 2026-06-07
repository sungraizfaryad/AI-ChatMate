<?php
/**
 * Schema Discovery Engine
 *
 * Scans the WordPress site and builds a complete JSON map of:
 *  - Public post types (with post count)
 *  - Taxonomies per post type (with available terms)
 *  - Custom meta fields per post type (with inferred data type + range)
 *  - ACF fields (via AICM_Field_Detector)
 *  - MetaBox fields (via AICM_Field_Detector)
 *  - WooCommerce product fields (via AICM_Field_Detector)
 *
 * Performance design:
 *  - Meta key detection samples the most recent 50 published posts per type.
 *    On any site using ACF/MetaBox, all posts of a type share the same fields,
 *    so 50 posts is sufficient and keeps the query fast.
 *  - Data type inference samples up to 100 values per meta key — enough for
 *    accurate inference without loading the full database.
 *  - Terms are limited to 300 per taxonomy — beyond that, we truncate and note
 *    the count so the AI knows the field exists.
 *  - This class should NEVER be instantiated on a regular page request.
 *    It is called by: activation, weekly cron, or admin "Rescan" button only.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load dependencies (safe to load multiple times — PHP guards).
require_once AICM_PLUGIN_DIR . 'includes/schema/class-aicm-schema-cache.php';
require_once AICM_PLUGIN_DIR . 'includes/schema/class-aicm-field-detector.php';

/**
 * Class AICM_Schema_Discovery
 */
class AICM_Schema_Discovery {

	/**
	 * Post types excluded from scanning regardless of their public status.
	 * These are WordPress internal types that users never query.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_POST_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'scheduled-action', // Action Scheduler
	);

	/**
	 * Meta key prefixes that indicate WordPress internal fields.
	 * These are excluded when scanning for user-defined meta keys.
	 * ACF fields are detected via ACF's PHP API, not by meta key scanning.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_META_PREFIXES = array(
		'_wp_',
		'_edit_',
		'_encloseme',
		'_pingme',
		'_menu_item_',
		'_oembed_',
		'_transient_',
		// WooCommerce internal flags (we capture the useful WC fields via AICM_Field_Detector).
		'_wc_',
		'_download_',
		'_sold_individually',
	);

	/**
	 * Maximum number of posts to sample when detecting meta keys.
	 * Lower = faster. 50 is sufficient for ACF/MetaBox sites (same schema per type).
	 */
	private const META_SAMPLE_POSTS = 50;

	/**
	 * Maximum number of distinct meta keys to return per post type
	 * (after exclusions). Prevents runaway arrays on sites with chaos in postmeta.
	 */
	private const MAX_META_KEYS = 60;

	/**
	 * Number of meta values to sample for data type inference.
	 */
	private const TYPE_SAMPLE_SIZE = 100;

	/**
	 * Maximum number of taxonomy terms to store per taxonomy.
	 */
	private const MAX_TERMS = 300;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Run the full schema discovery and persist the result.
	 *
	 * This is the only public method — everything else is internal.
	 *
	 * @return array The generated schema array.
	 */
	public static function run( bool $persist = true ): array {
		$schema = array(
			'site_name'            => get_bloginfo( 'name' ),
			'site_url'             => home_url(),
			'generated_at'         => current_time( 'mysql' ),
			'post_types'           => array(),
			'has_woocommerce'      => AICM_Field_Detector::has_woocommerce(),
			'has_acf'              => AICM_Field_Detector::has_acf(),
			'has_metabox'          => AICM_Field_Detector::has_metabox(),
			// Filled in at the end after all post types are scanned.
			'search_enabled_types' => array(),
			'rag_enabled_types'    => array(),
		);

		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $pt_name => $pt_object ) {
			if ( in_array( $pt_name, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}

			$schema['post_types'][ $pt_name ] = self::scan_post_type( $pt_name, $pt_object );
		}

		// Determine which post types are suitable for Structured Search mode
		// (must have at least 2 searchable meta fields or 1 taxonomy with terms).
		foreach ( $schema['post_types'] as $pt_name => $pt_data ) {
			$has_meta       = count( $pt_data['meta_fields'] ) >= 2;
			$has_taxonomies = ! empty( $pt_data['taxonomies'] );

			if ( $has_meta || $has_taxonomies ) {
				$schema['search_enabled_types'][] = $pt_name;
			}

			// All post types are eligible for RAG Q&A.
			$schema['rag_enabled_types'][] = $pt_name;
		}

		// Cache the result for immediate use + weekly cron re-use.
		// Preview mode (wizard "Detect") returns the schema without persisting,
		// so a Rescan never silently changes the live config mid-wizard.
		if ( $persist ) {
			AICM_Schema_Cache::set( $schema );
		}

		/**
		 * Fires after the schema has been discovered and cached.
		 *
		 * @param array $schema The full schema array.
		 */
		do_action( 'aicm_schema_discovered', $schema );

		return $schema;
	}

	// -------------------------------------------------------------------------
	// Post type scanning
	// -------------------------------------------------------------------------

	/**
	 * Collect all schema information for a single post type.
	 *
	 * @param string       $pt_name   Post type slug.
	 * @param WP_Post_Type $pt_object Post type object.
	 * @return array Post type schema data.
	 */
	private static function scan_post_type( string $pt_name, WP_Post_Type $pt_object ): array {
		$data = array(
			'label'       => $pt_object->label,
			'count'       => self::get_post_count( $pt_name ),
			'taxonomies'  => self::get_taxonomies( $pt_name ),
			'meta_fields' => self::get_meta_fields( $pt_name ),
		);

		return $data;
	}

	/**
	 * Get the number of published posts for a post type.
	 *
	 * wp_count_posts() is cached by WordPress — safe to call many times.
	 *
	 * @param string $pt_name Post type slug.
	 * @return int
	 */
	private static function get_post_count( string $pt_name ): int {
		$counts = wp_count_posts( $pt_name );
		return (int) ( $counts->publish ?? 0 );
	}

	// -------------------------------------------------------------------------
	// Taxonomy scanning
	// -------------------------------------------------------------------------

	/**
	 * Get all public taxonomies for a post type, with their published terms.
	 *
	 * @param string $pt_name Post type slug.
	 * @return array<string, array> Map of taxonomy_slug => taxonomy_info.
	 */
	private static function get_taxonomies( string $pt_name ): array {
		$result     = array();
		$taxonomies = get_object_taxonomies( $pt_name, 'objects' );

		if ( empty( $taxonomies ) ) {
			return $result;
		}

		foreach ( $taxonomies as $tax_name => $tax_object ) {
			// Only include public taxonomies.
			if ( ! $tax_object->public ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $tax_name,
					'hide_empty' => true,
					'number'     => self::MAX_TERMS,
					'fields'     => 'slugs', // Only slugs — we don't need full term objects.
					'orderby'    => 'count',
					'order'      => 'DESC',  // Most-used terms first.
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$result[ $tax_name ] = array(
				'label'      => $tax_object->label,
				'terms'      => $terms,
				'term_count' => wp_count_terms(
					array(
						'taxonomy'   => $tax_name,
						'hide_empty' => true,
					)
				),
				'truncated'  => count( $terms ) >= self::MAX_TERMS,
			);
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Meta field scanning
	// -------------------------------------------------------------------------

	/**
	 * Get all meaningful meta fields for a post type.
	 *
	 * Strategy:
	 * 1. If ACF is active — use ACF's PHP API (authoritative, clean field definitions).
	 * 2. If MetaBox is active — use MetaBox's filter (authoritative, clean).
	 * 3. If WooCommerce product — use WooCommerce's API (price, stock, attributes).
	 * 4. Always also scan raw postmeta for any fields not covered by the above.
	 *
	 * @param string $pt_name Post type slug.
	 * @return array<string, array> Map of meta_key => field_info.
	 */
	private static function get_meta_fields( string $pt_name ): array {
		$fields = array();

		// --- ACF fields (most structured, highest priority).
		$acf_fields = AICM_Field_Detector::get_acf_fields( $pt_name );
		foreach ( $acf_fields as $key => $info ) {
			$fields[ $key ] = $info;
		}

		// --- MetaBox fields.
		$mb_fields = AICM_Field_Detector::get_metabox_fields( $pt_name );
		foreach ( $mb_fields as $key => $info ) {
			if ( ! isset( $fields[ $key ] ) ) { // ACF takes precedence.
				$fields[ $key ] = $info;
			}
		}

		// --- WooCommerce product fields.
		$wc_fields = AICM_Field_Detector::get_woocommerce_fields( $pt_name );
		foreach ( $wc_fields as $key => $info ) {
			if ( ! isset( $fields[ $key ] ) ) {
				$fields[ $key ] = $info;
			}
		}

		// --- Raw postmeta scan (for custom fields not registered with ACF/MetaBox).
		$raw_keys = self::get_raw_meta_keys( $pt_name );

		foreach ( $raw_keys as $meta_key ) {
			// Skip if already discovered via a plugin API (those are richer).
			if ( isset( $fields[ $meta_key ] ) ) {
				continue;
			}

			// Stop when we hit the per-post-type cap.
			if ( count( $fields ) >= self::MAX_META_KEYS ) {
				break;
			}

			$type_info = self::infer_field_type( $meta_key, $pt_name );

			$fields[ $meta_key ] = array_merge(
				array(
					'label'  => ucwords( str_replace( array( '_', '-' ), ' ', $meta_key ) ),
					'source' => 'postmeta',
				),
				$type_info
			);
		}

		return $fields;
	}

	/**
	 * Retrieve distinct meta keys from postmeta for a post type.
	 *
	 * Samples the most recent N published posts — fast and sufficient.
	 * Only returns keys NOT starting with `_` (internal/hidden keys) unless
	 * they're from WooCommerce (handled by get_woocommerce_fields separately).
	 *
	 * @param string $pt_name Post type slug.
	 * @return string[] Array of meta_key strings.
	 */
	private static function get_raw_meta_keys( string $pt_name ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- table names are from $wpdb properties; the NOT LIKE pattern is a fixed literal (excludes underscore-prefixed keys), not user input.
		$raw_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				WHERE pm.post_id IN (
					SELECT p.ID
					FROM {$wpdb->posts} p
					WHERE p.post_type   = %s
					  AND p.post_status = 'publish'
					ORDER BY p.post_date DESC
					LIMIT %d
				)
				AND pm.meta_key NOT LIKE '\_%%'
				ORDER BY pm.meta_key
				LIMIT %d",
				$pt_name,
				self::META_SAMPLE_POSTS,
				self::MAX_META_KEYS
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $raw_keys ) ) {
			return array();
		}

		// PHP-level filter for any internal prefixes the SQL couldn't catch.
		return array_values(
			array_filter(
				$raw_keys,
				static fn( string $key ): bool => ! self::is_excluded_meta_key( $key )
			)
		);
	}

	/**
	 * Determine whether a meta key should be excluded from the schema.
	 *
	 * @param string $key Meta key to check.
	 * @return bool True if the key should be excluded.
	 */
	private static function is_excluded_meta_key( string $key ): bool {
		foreach ( self::EXCLUDED_META_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Data type inference
	// -------------------------------------------------------------------------

	/**
	 * Infer the data type of a custom meta field by sampling stored values.
	 *
	 * Sampling strategy:
	 *  - Fetch up to TYPE_SAMPLE_SIZE non-empty, non-serialized values.
	 *  - If ≥ 80% are numeric  → 'numeric' (with min/max).
	 *  - If ≥ 80% are boolean-like ('0', '1', 'yes', 'no', 'true', 'false') → 'boolean'.
	 *  - If ≥ 80% look like dates (parseable by strtotime) → 'date'.
	 *  - Otherwise → 'text'.
	 *
	 * We skip serialised values — they are complex objects (arrays, ACF groups)
	 * and not directly searchable as scalar fields.
	 *
	 * @param string $meta_key  Meta key to inspect.
	 * @param string $pt_name   Post type (to narrow the sample).
	 * @return array Type info: { type, ?min, ?max }
	 */
	private static function infer_field_type( string $meta_key, string $pt_name ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb properties.
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key  = %s
				  AND p.post_type  = %s
				  AND p.post_status = 'publish'
				  AND pm.meta_value != ''
				  AND pm.meta_value IS NOT NULL
				LIMIT %d",
				$meta_key,
				$pt_name,
				self::TYPE_SAMPLE_SIZE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $values ) || ! is_array( $values ) ) {
			return array( 'type' => 'text' );
		}

		// Discard serialised values — they are complex nested data.
		$scalar_values = array_filter(
			$values,
			static fn( string $v ): bool => ! is_serialized( $v )
		);

		if ( empty( $scalar_values ) ) {
			return array( 'type' => 'text' );
		}

		$total           = count( $scalar_values );
		$numeric_values  = array();
		$boolean_count   = 0;
		$date_count      = 0;
		$boolean_strings = array( '0', '1', 'yes', 'no', 'true', 'false', 'on', 'off' );

		foreach ( $scalar_values as $value ) {
			if ( is_numeric( $value ) ) {
				$numeric_values[] = (float) $value;
			}
			if ( in_array( strtolower( (string) $value ), $boolean_strings, true ) ) {
				++$boolean_count;
			}
			// strtotime() returns false for non-date strings — use strict check.
			// Only flag as date if the value looks like a date, not a plain number.
			if ( ! is_numeric( $value ) && false !== strtotime( (string) $value ) ) {
				++$date_count;
			}
		}

		$threshold = 0.80; // 80% of sampled values must match for a type to be inferred.

		// Numeric — highest priority (most useful for search range queries).
		if ( count( $numeric_values ) / $total >= $threshold ) {
			$result = array( 'type' => 'numeric' );
			if ( ! empty( $numeric_values ) ) {
				$result['min'] = min( $numeric_values );
				$result['max'] = max( $numeric_values );
			}
			return $result;
		}

		// Boolean.
		if ( $boolean_count / $total >= $threshold ) {
			return array( 'type' => 'boolean' );
		}

		// Date.
		if ( $date_count / $total >= $threshold ) {
			return array( 'type' => 'date' );
		}

		return array( 'type' => 'text' );
	}
}
