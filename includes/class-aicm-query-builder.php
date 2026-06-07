<?php
/**
 * Query Builder
 *
 * Translates AI function-call arguments into a safe WP_Query argument array
 * that can be executed directly on the WordPress database.
 *
 * ── Security model ────────────────────────────────────────────────────────────
 * All values coming from the AI are treated as untrusted:
 *
 *  post_type  → must be in the admin-configured index_post_types list.
 *  per_page   → capped at MAX_PER_PAGE regardless of what the AI requests.
 *  orderby    → whitelisted against ALLOWED_ORDERBY.
 *  order      → whitelisted against ALLOWED_ORDER ('ASC' | 'DESC').
 *  compare    → whitelisted against ALLOWED_COMPARE.
 *  meta_key   → run through sanitize_key().
 *  taxonomy   → run through sanitize_key() and verified with taxonomy_exists().
 *  term       → run through sanitize_text_field().
 *  search     → run through sanitize_text_field().
 *
 * ── Output ───────────────────────────────────────────────────────────────────
 * build() returns a WP_Query args array.
 * execute() runs the query and returns simplified post data for the AI.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Query_Builder
 */
class AICM_Query_Builder {

	/** Hard cap on results returned per AI function call. */
	private const MAX_PER_PAGE = 10;

	/** Default result count when the AI does not specify per_page. */
	private const DEFAULT_PER_PAGE = 5;

	/**
	 * Allowed values for the WP_Query 'orderby' parameter.
	 *
	 * @var string[]
	 */
	private const ALLOWED_ORDERBY = array(
		'date',
		'modified',
		'title',
		'ID',
		'meta_value',
		'meta_value_num',
		'rand',
	);

	/**
	 * Allowed values for the WP_Query 'order' parameter.
	 *
	 * @var string[]
	 */
	private const ALLOWED_ORDER = array( 'ASC', 'DESC' );

	/**
	 * Allowed compare operators for meta_query clauses.
	 *
	 * This whitelist prevents SQL injection via the AI's function arguments.
	 *
	 * @var string[]
	 */
	private const ALLOWED_COMPARE = array(
		'=',
		'!=',
		'<',
		'<=',
		'>',
		'>=',
		'LIKE',
		'NOT LIKE',
		'EXISTS',
		'NOT EXISTS',
	);

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Build a safe WP_Query args array from AI function call arguments.
	 *
	 * Sanitizes and whitelists every value before including it in the
	 * query args. Unknown or invalid values are replaced with safe defaults.
	 *
	 * @param array $args JSON-decoded arguments from the AI's function_call.
	 *                    Recognised keys:
	 *                      post_type, search, taxonomy_filters, meta_filters,
	 *                      orderby, order, meta_key, per_page.
	 * @return array WP_Query args array, ready to pass to new WP_Query().
	 */
	public static function build( array $args ): array {
		$configured_types = (array) AI_ChatMate::get_setting(
			'index_post_types',
			array( 'post', 'page' )
		);

		// ── post_type ──────────────────────────────────────────────────────
		// Only allow post types the admin has chosen to index.
		// If the AI requests an unknown type (or 'any'), search all types.
		$raw_type  = sanitize_key( (string) ( $args['post_type'] ?? '' ) );
		$post_type = ( '' !== $raw_type && in_array( $raw_type, $configured_types, true ) )
			? $raw_type
			: $configured_types;

		// ── per_page ───────────────────────────────────────────────────────
		$per_page = max(
			1,
			min(
				self::MAX_PER_PAGE,
				(int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE )
			)
		);

		// ── orderby ────────────────────────────────────────────────────────
		$raw_orderby = sanitize_key( strtolower( (string) ( $args['orderby'] ?? 'date' ) ) );
		$orderby     = in_array( $raw_orderby, self::ALLOWED_ORDERBY, true )
			? $raw_orderby
			: 'date';

		// ── order ──────────────────────────────────────────────────────────
		$raw_order = strtoupper( sanitize_text_field( (string) ( $args['order'] ?? 'DESC' ) ) );
		$order     = in_array( $raw_order, self::ALLOWED_ORDER, true )
			? $raw_order
			: 'DESC';

		// ── base query args ────────────────────────────────────────────────
		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'orderby'                => $orderby,
			'order'                  => $order,
			// Performance: WP_Query does not need to count total found rows.
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		);

		// ── keyword search ─────────────────────────────────────────────────
		$search = sanitize_text_field( wp_unslash( (string) ( $args['search'] ?? '' ) ) );
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		// ── meta_key for ordering by custom field value ────────────────────
		// Required by WP_Query when orderby is 'meta_value' or 'meta_value_num'.
		if ( in_array( $orderby, array( 'meta_value', 'meta_value_num' ), true ) ) {
			$meta_key = sanitize_key( (string) ( $args['meta_key'] ?? '' ) );
			if ( '' !== $meta_key ) {
				$query_args['meta_key'] = $meta_key;
			} else {
				// Cannot order by meta_value without a key — fall back to date.
				$query_args['orderby'] = 'date';
			}
		}

		// ── taxonomy_filters ──────────────────────────────────────────────
		$tax_input = is_array( $args['taxonomy_filters'] ?? null )
			? $args['taxonomy_filters']
			: array();

		if ( ! empty( $tax_input ) ) {
			$tax_query = array( 'relation' => 'AND' );

			foreach ( $tax_input as $filter ) {
				if ( ! is_array( $filter ) ) {
					continue;
				}

				$taxonomy = sanitize_key( (string) ( $filter['taxonomy'] ?? '' ) );
				$term     = sanitize_text_field( wp_unslash( (string) ( $filter['term'] ?? '' ) ) );

				if ( '' === $taxonomy || '' === $term ) {
					continue;
				}

				// Reject taxonomies that do not exist on this WordPress install.
				// This prevents the AI from injecting arbitrary strings into
				// the SQL that WordPress eventually builds.
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}

				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => self::resolve_tax_field( $taxonomy, $term ),
					'terms'    => $term,
				);
			}

			if ( count( $tax_query ) > 1 ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				$query_args['tax_query'] = $tax_query;
			}
		}

		// ── meta_filters ──────────────────────────────────────────────────
		$meta_input = is_array( $args['meta_filters'] ?? null )
			? $args['meta_filters']
			: array();

		if ( ! empty( $meta_input ) ) {
			$meta_query = array( 'relation' => 'AND' );

			foreach ( $meta_input as $filter ) {
				if ( ! is_array( $filter ) ) {
					continue;
				}

				$key     = sanitize_key( (string) ( $filter['key'] ?? '' ) );
				$compare = strtoupper( sanitize_text_field( (string) ( $filter['compare'] ?? '=' ) ) );

				if ( '' === $key ) {
					continue;
				}

				if ( ! in_array( $compare, self::ALLOWED_COMPARE, true ) ) {
					$compare = '=';
				}

				$meta_clause = array(
					'key'     => $key,
					'compare' => $compare,
				);

				// EXISTS and NOT EXISTS queries do not take a value.
				if ( ! in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
					$value                = sanitize_text_field(
						wp_unslash( (string) ( $filter['value'] ?? '' ) )
					);
					$meta_clause['value'] = $value;

					// Use NUMERIC type for numeric comparators with numeric values —
					// this allows MySQL to compare numbers correctly (e.g. 500 > 99).
					if (
						in_array( $compare, array( '<', '<=', '>', '>=' ), true )
						&& is_numeric( $value )
					) {
						$meta_clause['type'] = 'NUMERIC';
					}
				}

				$meta_query[] = $meta_clause;
			}

			if ( count( $meta_query ) > 1 ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$query_args['meta_query'] = $meta_query;
			}
		}

		return $query_args;
	}

	/**
	 * Decide whether a taxonomy term value is a slug or a display name.
	 *
	 * Schema injection feeds the AI real term slugs, but admins/users may also
	 * type display names. Prefer 'slug' when the value resolves to an existing
	 * term slug; otherwise fall back to 'name'. Without this, AI-emitted slugs
	 * silently match nothing (WP_Query 'name' matches the display name only).
	 *
	 * @param string $taxonomy Validated taxonomy slug.
	 * @param string $term     Term value supplied by the AI.
	 * @return string 'slug' or 'name'.
	 */
	private static function resolve_tax_field( string $taxonomy, string $term ): string {
		$by_slug = get_term_by( 'slug', $term, $taxonomy );
		return ( $by_slug instanceof WP_Term ) ? 'slug' : 'name';
	}

	/**
	 * Execute a WP_Query and return simplified post data for the AI.
	 *
	 * The AI receives: post ID, title, permalink, excerpt, and date.
	 * This is enough context for the AI to reference posts accurately
	 * without sending the full post content back to the model.
	 *
	 * @param array $query_args WP_Query args array from build().
	 * @return array {
	 *   'found'    => int     Number of posts returned (0 to MAX_PER_PAGE).
	 *   'posts'    => array[] Simplified post records.
	 *   'post_ids' => int[]   Raw post IDs (used to populate the sources list).
	 * }
	 */
	public static function execute( array $query_args ): array {
		$query = new WP_Query( $query_args );

		if ( empty( $query->posts ) ) {
			return array(
				'found'    => 0,
				'posts'    => array(),
				'post_ids' => array(),
			);
		}

		$simplified = array();
		$post_ids   = array();

		foreach ( $query->posts as $post_data ) {
			$post = get_post( $post_data );

			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}

			$post_ids[]   = $post->ID;
			$simplified[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'url'     => get_permalink( $post->ID ),
				'excerpt' => wp_trim_words(
					wp_strip_all_tags( $post->post_content ),
					40,
					'…'
				),
				'date'    => $post->post_date,
			);
		}

		wp_reset_postdata();

		return array(
			'found'    => count( $simplified ),
			'posts'    => $simplified,
			'post_ids' => $post_ids,
		);
	}
}
