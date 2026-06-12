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

				$key = sanitize_key( (string) ( $filter['key'] ?? '' ) );

				// IMPORTANT: do NOT pass the operator through sanitize_text_field()
				// — it strips '<' as a partial HTML tag, silently turning '<=' into
				// '=' so every "under X" search became "exactly X". The whitelist
				// below IS the sanitiser: anything not on it collapses to '='.
				$compare = strtoupper( trim( (string) ( $filter['compare'] ?? '=' ) ) );

				// Reject empty keys and protected meta (underscore-prefixed,
				// e.g. _edit_lock, plugin internals). The schema only ever
				// advertises public keys to the AI, but the /chat endpoint is
				// public — without this, a crafted conversation could probe
				// hidden meta via EXISTS / range comparisons.
				if ( '' === $key || str_starts_with( $key, '_' ) ) {
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
					$value = sanitize_text_field(
						wp_unslash( (string) ( $filter['value'] ?? '' ) )
					);

					$is_range = in_array( $compare, array( '<', '<=', '>', '>=' ), true );

					// For range comparisons, normalise human-style numbers the AI
					// may pass through from the visitor ("2 million", "€1,500,000",
					// "950k") into plain numerics — otherwise MySQL falls back to
					// string comparison and the filter is meaningless.
					if ( $is_range && ! is_numeric( $value ) ) {
						$normalised = self::normalize_numeric( $value );
						if ( null !== $normalised ) {
							$value = $normalised;
						}
					}

					$meta_clause['value'] = $value;

					// Use NUMERIC type for numeric comparators with numeric values —
					// this allows MySQL to compare numbers correctly (e.g. 500 > 99).
					if ( $is_range && is_numeric( $value ) ) {
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
	 * Normalise a human-style number into a plain numeric string.
	 *
	 * Visitors say "2 million", "€1,500,000", "950k", "1.5M" — and the AI may
	 * pass those through verbatim. MySQL cannot range-compare them, so we
	 * convert: strip currency symbols, thousands separators and whitespace,
	 * then apply k / m(illion) / b(illion) multipliers.
	 *
	 * @param string $value Raw value from the AI function call.
	 * @return string|null Numeric string, or null if it cannot be parsed.
	 */
	private static function normalize_numeric( string $value ): ?string {
		$v = strtolower( trim( $value ) );

		// Strip currency symbols/codes and spaces.
		$v = (string) preg_replace( '/(usd|eur|gbp|[$€£])/', '', $v );
		$v = (string) str_replace( ' ', '', $v );

		// Commas: a trailing ",dd" (1–2 digits) is a European DECIMAL comma
		// ("1,5 million" means 1.5, not 15) — convert it to a dot. All other
		// commas are thousands separators ("1,500,000") — remove them.
		if ( preg_match( '/,\d{1,2}$/', preg_replace( '/(thousand|million|billion|k|m|b)$/', '', $v ) ) ) {
			$v = str_replace( ',', '.', $v );
		} else {
			$v = str_replace( ',', '', $v );
		}

		if ( ! preg_match( '/^([0-9]*\.?[0-9]+)(thousand|million|billion|k|m|b)?$/', $v, $match ) ) {
			return null;
		}

		$number     = (float) $match[1];
		$multiplier = 1;

		switch ( $match[2] ?? '' ) {
			case 'k':
			case 'thousand':
				$multiplier = 1000;
				break;
			case 'm':
			case 'million':
				$multiplier = 1000000;
				break;
			case 'b':
			case 'billion':
				$multiplier = 1000000000;
				break;
		}

		$result = $number * $multiplier;

		// Integers stay integers ("2 million" → "2000000", not "2000000.0").
		return ( $result === floor( $result ) )
			? (string) (int) $result
			: (string) $result;
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
			$result = array(
				'found'    => 0,
				'posts'    => array(),
				'post_ids' => array(),
			);

			// Zero results with filters applied: tell the AI what DOES exist
			// nearby so it can guide the visitor instead of dead-ending.
			$help = self::zero_results_help( $query_args );
			if ( ! empty( $help ) ) {
				$result['zero_results_help'] = $help;
			}

			return $result;
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

	// ── Zero-result guidance ─────────────────────────────────────────────────

	/**
	 * Maximum number of relax-probe COUNT queries per zero-result search.
	 * Keeps the worst case bounded regardless of how many filters the AI sent.
	 */
	private const MAX_RELAX_PROBES = 5;

	/**
	 * Build "what exists nearby" data for a search that matched nothing.
	 *
	 * For each applied filter (meta clause, taxonomy clause, keyword), runs ONE
	 * cheap count query with that single filter removed, and collects the real
	 * terms available in each filtered taxonomy. The AI uses this to guide the
	 * visitor with facts ("nothing under 2M with 3 baths, but 27 exist up to
	 * 2.5M") instead of a dead-end apology.
	 *
	 * FALSE-POSITIVE SAFETY: this data is returned in a SEPARATE structure with
	 * an explicit instruction — relaxed counts are never merged into 'posts',
	 * so the model cannot mistake near-misses for actual matches.
	 *
	 * @param array $query_args The exact WP_Query args that returned nothing.
	 * @return array Empty when no filters were applied (nothing to relax).
	 */
	private static function zero_results_help( array $query_args ): array {
		$relaxed = array();
		$probes  = 0;

		// ── One probe per meta clause ──────────────────────────────────────
		$meta_clauses = array();
		foreach ( (array) ( $query_args['meta_query'] ?? array() ) as $idx => $clause ) {
			if ( 'relation' !== $idx && is_array( $clause ) ) {
				$meta_clauses[ $idx ] = $clause;
			}
		}

		foreach ( $meta_clauses as $idx => $clause ) {
			if ( $probes >= self::MAX_RELAX_PROBES ) {
				break;
			}
			++$probes;

			$variant = $query_args;
			unset( $variant['meta_query'][ $idx ] );
			if ( count( $variant['meta_query'] ) <= 1 ) {
				unset( $variant['meta_query'] ); // Only 'relation' left.
			}

			$relaxed[] = array(
				'removed_filter' => trim(
					( $clause['key'] ?? '' ) . ' ' . ( $clause['compare'] ?? '=' ) . ' ' . ( $clause['value'] ?? '' )
				),
				'matches'        => self::count_matches( $variant ),
			);
		}

		// ── One probe per taxonomy clause + the real terms available ──────
		$alternatives = array();

		$tax_clauses = array();
		foreach ( (array) ( $query_args['tax_query'] ?? array() ) as $idx => $clause ) {
			if ( 'relation' !== $idx && is_array( $clause ) ) {
				$tax_clauses[ $idx ] = $clause;
			}
		}

		foreach ( $tax_clauses as $idx => $clause ) {
			$taxonomy = (string) ( $clause['taxonomy'] ?? '' );

			if ( $probes < self::MAX_RELAX_PROBES ) {
				++$probes;

				$variant = $query_args;
				unset( $variant['tax_query'][ $idx ] );
				if ( count( $variant['tax_query'] ) <= 1 ) {
					unset( $variant['tax_query'] );
				}

				$terms     = $clause['terms'] ?? '';
				$relaxed[] = array(
					'removed_filter' => trim( $taxonomy . ' = ' . ( is_array( $terms ) ? implode( ',', $terms ) : (string) $terms ) ),
					'matches'        => self::count_matches( $variant ),
				);
			}

			// Real sibling terms (by post count) the visitor could pick instead.
			if ( '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
				$siblings = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'number'     => 8,
						'orderby'    => 'count',
						'order'      => 'DESC',
						'hide_empty' => true,
					)
				);

				if ( is_array( $siblings ) && ! empty( $siblings ) ) {
					$alternatives[ $taxonomy ] = array_map(
						static fn( $t ) => $t->name . ' (' . (int) $t->count . ')',
						$siblings
					);
				}
			}
		}

		// ── One probe without the keyword search ───────────────────────────
		if ( '' !== (string) ( $query_args['s'] ?? '' ) && $probes < self::MAX_RELAX_PROBES ) {
			$variant = $query_args;
			unset( $variant['s'] );

			$relaxed[] = array(
				'removed_filter' => 'keyword "' . (string) $query_args['s'] . '"',
				'matches'        => self::count_matches( $variant ),
			);
		}

		if ( empty( $relaxed ) && empty( $alternatives ) ) {
			return array(); // No filters were applied — nothing useful to say.
		}

		return array(
			'instruction'            => 'No content matched ALL the requested filters at once. '
				. 'Each entry in relaxed_filters shows how many items match when that ONE filter is removed. '
				. 'NEVER present these as matching the original request. Tell the visitor honestly that nothing '
				. 'matched everything, then use these counts and available_alternatives to suggest the closest '
				. 'real options or ask ONE clarifying question. Always say explicitly which requirement would '
				. 'need to change.',
			'relaxed_filters'        => $relaxed,
			'available_alternatives' => $alternatives,
		);
	}

	/**
	 * Exact match count for a WP_Query args variant, as cheaply as possible.
	 *
	 * @param array $query_args WP_Query args.
	 * @return int
	 */
	private static function count_matches( array $query_args ): int {
		$query_args['fields']                 = 'ids';
		$query_args['posts_per_page']         = 1;
		$query_args['no_found_rows']          = false;
		$query_args['update_post_meta_cache'] = false;
		$query_args['update_post_term_cache'] = false;

		$query = new WP_Query( $query_args );

		return (int) $query->found_posts;
	}
}
