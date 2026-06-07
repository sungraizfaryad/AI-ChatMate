<?php
/**
 * Embedder
 *
 * Converts content chunks into embedding vectors and persists them to the
 * aicm_chunks database table.
 *
 * ── Embedding storage format ─────────────────────────────────────────────
 * Vectors are stored as packed binary using PHP's pack('f*', ...$floats).
 * Each float is 4 bytes, so a 1,536-dimension vector (text-embedding-3-small)
 * occupies exactly 6,144 bytes — 3× smaller than PHP's serialize() and
 * compatible with unpack('f*', $blob) on retrieval.
 *
 * ── Title prefix for embeddings ──────────────────────────────────────────
 * When calling the embedding API we prefix each chunk with the post title:
 *   "Title: {post_title}\n\n{chunk_text}"
 * This anchors every chunk's embedding in the topic of its parent post,
 * improving cosine similarity scores for topic-level queries. The title
 * prefix is NOT stored in chunk_text — only the clean chunk text is saved.
 *
 * ── Content-hash deduplication ───────────────────────────────────────────
 * Each chunk row stores an MD5 hash of its chunk_text. Before sending a
 * batch to the API we check whether any existing chunk rows for this post
 * already have the same hash. If the hash matches, the stored embedding is
 * still valid and we skip that chunk entirely — this avoids unnecessary
 * API calls (and cost) when a post is re-indexed with no content change.
 *
 * ── Batch API calls ──────────────────────────────────────────────────────
 * All chunks for a single post are sent to the provider in ONE batch call.
 * The OpenAI provider caps batches at 100 texts; since a typical post
 * produces 2–10 chunks, we never approach that limit.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Embedder
 */
class AICM_Embedder {

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Embed all chunks for a single post and persist them to the DB.
	 *
	 * Steps:
	 *  1. Build MD5 hash for every chunk text.
	 *  2. Load any existing chunk hashes from the DB for this post.
	 *  3. Filter out chunks whose hash already matches a stored row
	 *     (content unchanged → embedding still valid).
	 *  4. For chunks that HAVE changed (or are new): collect their texts,
	 *     call the embedding API in one batch, store the results.
	 *  5. Delete any surplus chunk rows (post was re-chunked into fewer chunks).
	 *
	 * Returns true on success, false if the API call failed.
	 *
	 * @param int               $post_id   WordPress post ID.
	 * @param string            $post_type Post type slug (stored on each row).
	 * @param string            $post_title Post title used as embedding prefix.
	 * @param array[]           $chunks    Output of AICM_Chunker::chunk().
	 *                                     Each: ['chunk_index', 'chunk_text', 'token_count']
	 * @param AICM_LLM_Provider $provider  Provider instance (must implement generate_embeddings_batch).
	 * @return bool
	 */
	public static function embed_post(
		int $post_id,
		string $post_type,
		string $post_title,
		array $chunks,
		AICM_LLM_Provider $provider
	): bool {
		if ( empty( $chunks ) ) {
			// No chunks means the post has no indexable content.
			// Remove any stale rows from a previous index run.
			self::delete_post_chunks( $post_id );
			return true;
		}

		// ── Step 1: compute MD5 hashes for all chunks ─────────────────────
		$new_hashes = array();
		foreach ( $chunks as $chunk ) {
			$new_hashes[ $chunk['chunk_index'] ] = md5( $chunk['chunk_text'] );
		}

		// ── Step 2: load existing hashes from DB ──────────────────────────
		$existing_hashes = self::get_existing_hashes( $post_id );
		// $existing_hashes: [ chunk_index => content_hash ]

		// ── Step 3: determine which chunks need a new embedding ───────────
		$needs_embed = array(); // chunk_index => chunk array

		foreach ( $chunks as $chunk ) {
			$idx = $chunk['chunk_index'];

			$already_stored = isset( $existing_hashes[ $idx ] )
				&& $existing_hashes[ $idx ] === $new_hashes[ $idx ];

			if ( ! $already_stored ) {
				$needs_embed[ $idx ] = $chunk;
			}
		}

		// ── Step 4: call API + store results for changed chunks ───────────
		if ( ! empty( $needs_embed ) ) {
			// Build the texts to embed: title prefix + chunk text.
			// We keep the chunk_index as the array KEY so we can map results back.
			$texts_to_embed = array();
			foreach ( $needs_embed as $idx => $chunk ) {
				$texts_to_embed[ $idx ] = self::build_embed_input( $post_title, $chunk['chunk_text'] );
			}

			// The provider expects a 0-indexed list, not an associative array.
			// Re-index for the API call and map results back via the saved order.
			$ordered_indices = array_keys( $texts_to_embed );
			$ordered_texts   = array_values( $texts_to_embed );

			$vectors = $provider->generate_embeddings_batch( $ordered_texts );

			// API failure — return false so the caller can mark this job failed.
			if ( empty( $vectors ) ) {
				return false;
			}

			// Map vectors back to their chunk indices and upsert.
			foreach ( $vectors as $batch_position => $vector ) {
				$chunk_index = $ordered_indices[ $batch_position ] ?? null;

				if ( null === $chunk_index || ! isset( $needs_embed[ $chunk_index ] ) ) {
					continue;
				}

				$chunk = $needs_embed[ $chunk_index ];

				self::upsert_chunk(
					$post_id,
					$post_type,
					$chunk_index,
					$chunk['chunk_text'],
					$new_hashes[ $chunk_index ],
					self::pack_vector( $vector ),
					$chunk['token_count']
				);
			}
		}

		// ── Step 5: remove surplus rows ───────────────────────────────────
		// If this re-index produced fewer chunks than the previous one
		// (e.g. post content was shortened), delete stale rows.
		$new_indices      = array_column( $chunks, 'chunk_index' );
		$existing_indices = array_keys( $existing_hashes );
		$surplus_indices  = array_diff( $existing_indices, $new_indices );

		if ( ! empty( $surplus_indices ) ) {
			self::delete_chunk_indices( $post_id, $surplus_indices );
		}

		return true;
	}

	/**
	 * Delete all chunk rows for a post.
	 *
	 * Called when a post is deleted or trashed, or before a full re-embed
	 * when we do not want to preserve any existing data.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public static function delete_post_chunks( int $post_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_chunks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array( 'post_id' => $post_id ),
			array( '%d' )
		);
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Build the string that is sent to the embedding API.
	 *
	 * We prepend the post title so every chunk's embedding includes topic
	 * context, improving similarity scores for broad topic queries.
	 * The title prefix is NOT stored in chunk_text — only clean text is saved.
	 *
	 * @param string $post_title Post title.
	 * @param string $chunk_text Chunk body text.
	 * @return string
	 */
	private static function build_embed_input( string $post_title, string $chunk_text ): string {
		$title = trim( $post_title );

		if ( '' === $title ) {
			return $chunk_text;
		}

		return "Title: {$title}\n\n{$chunk_text}";
	}

	/**
	 * Pack a float array into a binary string for storage in LONGBLOB.
	 *
	 * pack('f*', ...$floats) writes each float as a 4-byte IEEE 754
	 * single-precision value in machine byte order.
	 *
	 * On retrieval, unpack('f*', $blob) reconstructs the float array exactly.
	 *
	 * Size: 1,536 dimensions × 4 bytes = 6,144 bytes per row (text-embedding-3-small).
	 *
	 * @param float[] $vector Dense float array from the provider.
	 * @return string         Binary string.
	 */
	private static function pack_vector( array $vector ): string {
		if ( empty( $vector ) ) {
			return '';
		}

		return pack( 'f*', ...$vector );
	}

	/**
	 * Load the existing chunk_index → content_hash map from the DB for a post.
	 *
	 * Used by embed_post() to skip API calls for unchanged chunks.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array<int, string> Map of chunk_index => content_hash.
	 */
	private static function get_existing_hashes( int $post_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_chunks';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT chunk_index, content_hash FROM `{$table}` WHERE post_id = %d",
				$post_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['chunk_index'] ] = $row['content_hash'];
		}

		return $map;
	}

	/**
	 * Insert or update a single chunk row in the aicm_chunks table.
	 *
	 * We use INSERT ... ON DUPLICATE KEY UPDATE rather than wpdb::replace()
	 * because replace() DELETEs then INSERTs (which changes the row ID and
	 * can cause foreign-key issues in future). The UNIQUE KEY on
	 * (post_id, chunk_index) makes this possible.
	 *
	 * Note: the aicm_chunks table does not have a UNIQUE KEY on
	 * (post_id, chunk_index) in Phase 1. We work around this by selecting
	 * existing IDs first and choosing between INSERT and UPDATE.
	 *
	 * @param int    $post_id       WordPress post ID.
	 * @param string $post_type     Post type slug.
	 * @param int    $chunk_index   Zero-based chunk position within the post.
	 * @param string $chunk_text    Clean plain text for this chunk.
	 * @param string $content_hash  MD5 of $chunk_text.
	 * @param string $embedding     Packed binary vector.
	 * @param int    $token_count   Estimated token count.
	 */
	private static function upsert_chunk(
		int $post_id,
		string $post_type,
		int $chunk_index,
		string $chunk_text,
		string $content_hash,
		string $embedding,
		int $token_count
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_chunks';
		$now   = current_time( 'mysql' );

		// Check whether a row already exists for this (post_id, chunk_index).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE post_id = %d AND chunk_index = %d LIMIT 1",
				$post_id,
				$chunk_index
			)
		);

		if ( null !== $existing_id ) {
			// UPDATE existing row — preserves the original created_at.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'post_type'    => $post_type,
					'chunk_text'   => $chunk_text,
					'content_hash' => $content_hash,
					'embedding'    => $embedding,
					'token_count'  => $token_count,
					'updated_at'   => $now,
				),
				array( 'id' => (int) $existing_id ),
				array( '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			// INSERT new row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'post_id'      => $post_id,
					'post_type'    => $post_type,
					'chunk_index'  => $chunk_index,
					'chunk_text'   => $chunk_text,
					'content_hash' => $content_hash,
					'embedding'    => $embedding,
					'token_count'  => $token_count,
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Delete specific chunk rows by their chunk_index values.
	 *
	 * Used to remove surplus rows when a post is re-chunked into fewer pieces.
	 *
	 * @param int   $post_id         WordPress post ID.
	 * @param int[] $surplus_indices Chunk indices to delete.
	 */
	private static function delete_chunk_indices( int $post_id, array $surplus_indices ): void {
		if ( empty( $surplus_indices ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'aicm_chunks';

		// Build a safe placeholders list.
		$placeholders = implode( ', ', array_fill( 0, count( $surplus_indices ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE post_id = %d AND chunk_index IN ({$placeholders})",
				array_merge( array( $post_id ), $surplus_indices )
			)
		);
	}
}
