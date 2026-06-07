<?php
/**
 * Content Fetcher
 *
 * Retrieves WordPress post data in a safe, batched manner for the
 * content indexing pipeline.
 *
 * Design principles:
 *  - Never load all posts into memory at once — batched IDs only.
 *  - Uses WP_Query (not $wpdb directly) so WP object caches and caching
 *    plugins work correctly.
 *  - Only 'publish' status posts are indexed — drafts and private posts
 *    are excluded to prevent non-public content leaking via the chatbot.
 *  - Meta values that are serialized (arrays/objects) are excluded because
 *    they are not human-readable and pollute the embedding content.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Content_Fetcher
 */
class AICM_Content_Fetcher {

	// -------------------------------------------------------------------------
	// Post ID retrieval
	// -------------------------------------------------------------------------

	/**
	 * Get a batch of published post IDs for a given post type.
	 *
	 * Used when seeding the indexing queue for a full re-index. Returns IDs
	 * in ascending order so pagination is stable across calls.
	 *
	 * WP_Query arguments explained:
	 *  - no_found_rows: true    — Skip SQL_CALC_FOUND_ROWS; we don't need
	 *                             the total count here, just the current page.
	 *  - update_post_meta_cache — We fetch meta separately when we need it,
	 *  - update_post_term_cache   so disabling these avoids a wasted cache fill.
	 *
	 * @param string $post_type  Post type slug.
	 * @param int    $batch_size Number of IDs to return per page.
	 * @param int    $offset     Row offset for pagination.
	 * @return int[]             Array of post IDs (may be empty).
	 */
	public static function get_post_ids(
		string $post_type,
		int    $batch_size,
		int    $offset = 0
	): array {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => $batch_size,
				'offset'                 => $offset,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		// WP_Query->posts is array<int|string> when fields='ids'; cast to int[].
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Count the total number of published posts for a post type.
	 *
	 * Used to populate the queue size display in the admin UI and to
	 * calculate overall indexing progress.
	 *
	 * @param string $post_type Post type slug.
	 * @return int
	 */
	public static function count_published( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		// wp_count_posts() returns a stdClass; 'publish' may be absent on
		// custom post types with zero published posts.
		return (int) ( $counts->publish ?? 0 );
	}

	// -------------------------------------------------------------------------
	// Post data retrieval
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a single post's data ready for the indexing pipeline.
	 *
	 * Returns null when:
	 *  - The post ID does not exist.
	 *  - The post is not published (draft, private, pending, etc.).
	 *  - The post type is not in the admin-configured index_post_types list.
	 *    This last check prevents orphan queue items from indexing content
	 *    the admin has since disabled.
	 *
	 * Meta handling:
	 *  - get_post_meta() returns each value as a single-element array.
	 *    We flatten it to a scalar using $values[0].
	 *  - Serialized values (PHP arrays/objects stored by meta boxes) are
	 *    silently skipped — they are not useful text for embedding.
	 *  - Empty string values are skipped.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array|null {
	 *   'post'      => WP_Post  The post object.
	 *   'meta'      => array    Flat key => string meta (scalars only).
	 *   'post_type' => string   The post type slug.
	 * }
	 */
	public static function get_post_data( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return null;
		}

		// Only index publicly visible content.
		if ( 'publish' !== $post->post_status ) {
			return null;
		}

		// Respect the admin's post type selection.
		$configured_types = (array) AI_ChatMate::get_setting(
			'index_post_types',
			array( 'post', 'page' )
		);

		if ( ! in_array( $post->post_type, $configured_types, true ) ) {
			return null;
		}

		// Fetch all meta in one DB call. Each key maps to an array of values
		// (WordPress allows multiple values per meta key); we take the first.
		$raw_meta = get_post_meta( $post_id );
		$meta     = array();

		foreach ( $raw_meta as $key => $values ) {
			$value = $values[0] ?? null;

			// Skip missing or empty values.
			if ( null === $value || '' === (string) $value ) {
				continue;
			}

			// Skip PHP-serialized values (arrays, objects stored as meta).
			// is_serialized() is a WordPress core function.
			if ( is_serialized( $value ) ) {
				continue;
			}

			$meta[ (string) $key ] = (string) $value;
		}

		return array(
			'post'      => $post,
			'meta'      => $meta,
			'post_type' => $post->post_type,
		);
	}
}
