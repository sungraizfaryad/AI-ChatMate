<?php
/**
 * RAG Retriever
 *
 * Performs semantic similarity search against the aicm_chunks table to find
 * the most relevant content for a user query.
 *
 * ── Algorithm ────────────────────────────────────────────────────────────────
 *  1. Embed the user query text using the configured embedding provider.
 *  2. Fetch stored chunk vectors from the DB in batches of BATCH_SIZE rows.
 *  3. For each chunk, unpack the binary vector with unpack('f*', $blob)
 *     and compute cosine similarity against the query vector.
 *  4. Maintain a running list of high-scoring chunks; return the top_k best.
 *
 * ── Memory control ───────────────────────────────────────────────────────────
 * Processing in batches of BATCH_SIZE (500 rows) caps in-memory blob data.
 * On a typical WP site with ~1,000 indexed chunks the total blob data is
 * approximately 6 MB — well within PHP's default memory_limit.
 * MAX_ROWS_EXAMINED (10,000) prevents runaway processing on very large sites.
 *
 * ── Cosine similarity ────────────────────────────────────────────────────────
 * sim(A, B) = dot(A, B) / (|A| × |B|)
 *
 * OpenAI normalises its embedding vectors (L2 norm ≈ 1.0), so in practice
 * sim(A, B) ≈ dot(A, B). We still divide by both magnitudes for safety in
 * case a future provider does not normalise its output.
 *
 * ── Dimension mismatch guard ─────────────────────────────────────────────────
 * If the embedding model is changed after some chunks are stored, the stored
 * vectors will have a different dimension than the query vector. Those chunks
 * are silently skipped (they will be re-indexed on the next cron run).
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_RAG_Retriever
 */
class AICM_RAG_Retriever {

	/** Rows fetched from the DB per loop iteration to cap memory usage. */
	private const BATCH_SIZE = 500;

	/**
	 * Absolute maximum number of rows examined in a single find_similar() call.
	 * Prevents excessive processing time on very large sites.
	 */
	private const MAX_ROWS_EXAMINED = 10_000;

	/**
	 * Minimum cosine similarity score required to include a chunk in results.
	 * Chunks below this threshold are considered too semantically distant to
	 * be useful context. Range: [−1, 1]; 0.70 is a reasonable starting point.
	 */
	private const MIN_SIMILARITY = 0.70;

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Find the most semantically similar chunks for a user query.
	 *
	 * Embeds the query text, then scans the aicm_chunks table in batches
	 * to find the stored chunks with the highest cosine similarity.
	 *
	 * @param AICM_LLM_Provider $provider   Provider instance used to embed the query.
	 * @param string            $query      Raw user query text.
	 * @param int               $top_k      Maximum number of chunks to return (1–20).
	 * @param string[]          $post_types Optional post type slugs to restrict search.
	 *                                      Empty array = all indexed types.
	 * @return array[] Chunk records sorted by similarity (highest first).
	 *                 Each record: {
	 *                   'post_id'     => int,
	 *                   'post_type'   => string,
	 *                   'chunk_index' => int,
	 *                   'chunk_text'  => string,
	 *                   'similarity'  => float,
	 *                 }
	 */
	public static function find_similar(
		AICM_LLM_Provider $provider,
		string $query,
		int $top_k = 5,
		array $post_types = array()
	): array {
		$query = trim( $query );

		if ( '' === $query ) {
			return array();
		}

		// ── Step 1: embed the query ────────────────────────────────────────
		$query_vector = $provider->generate_embedding( $query );

		if ( empty( $query_vector ) ) {
			return array();
		}

		// Pre-compute the query magnitude once — reused for every chunk.
		$query_magnitude = self::magnitude( $query_vector );

		if ( 0.0 === $query_magnitude ) {
			return array();
		}

		$query_dim = count( $query_vector );

		// ── Step 2: scan DB in batches + compute similarity ───────────────
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_chunks';

		// Build the SQL and its argument list incrementally to support an
		// optional post_type IN (...) clause without double-preparing.
		$sql_where = 'WHERE embedding IS NOT NULL AND LENGTH(embedding) > 0';
		$sql_args  = array();

		if ( ! empty( $post_types ) ) {
			$sanitized = array_map( 'sanitize_key', (array) $post_types );
			$sanitized = array_filter( $sanitized ); // Remove empty strings.
			if ( ! empty( $sanitized ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $sanitized ), '%s' ) );
				$sql_where   .= " AND post_type IN ({$placeholders})";
				$sql_args     = array_merge( $sql_args, array_values( $sanitized ) );
			}
		}

		// LIMIT and OFFSET placeholders are appended last.
		$sql_args[] = self::BATCH_SIZE; // %d LIMIT
		// OFFSET is appended per iteration below.

		$top_results   = array();
		$offset        = 0;
		$rows_examined = 0;

		while ( $rows_examined < self::MAX_ROWS_EXAMINED ) {

			// Build full args for this iteration: static args + current offset.
			$iter_args   = $sql_args;
			$iter_args[] = $offset; // %d OFFSET

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, post_type, chunk_index, chunk_text, embedding
					 FROM `{$table}`
					 {$sql_where}
					 ORDER BY id ASC
					 LIMIT %d OFFSET %d",
					...$iter_args
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$blob = $row['embedding'];

				// Unpack the binary LONGBLOB into a float array.
				// unpack('f*') produces a 1-indexed array; array_values() re-indexes to 0.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				$stored_vector = array_values( (array) unpack( 'f*', $blob ) );

				if ( $query_dim !== count( $stored_vector ) ) {
					// Dimension mismatch — model changed after this chunk was stored.
					// Skip silently; the next full re-index will fix these rows.
					continue;
				}

				$sim = self::cosine_similarity( $query_vector, $stored_vector, $query_magnitude );

				if ( $sim < self::MIN_SIMILARITY ) {
					continue;
				}

				$top_results[] = array(
					'post_id'     => (int) $row['post_id'],
					'post_type'   => (string) $row['post_type'],
					'chunk_index' => (int) $row['chunk_index'],
					'chunk_text'  => (string) $row['chunk_text'],
					'similarity'  => $sim,
				);
			}

			$rows_examined += count( $rows );
			$offset        += self::BATCH_SIZE;

			// No more rows available.
			if ( count( $rows ) < self::BATCH_SIZE ) {
				break;
			}
		}

		// ── Step 3: sort descending by similarity, return top_k ───────────
		usort(
			$top_results,
			static fn( array $a, array $b ): int => $b['similarity'] <=> $a['similarity']
		);

		return array_slice( $top_results, 0, max( 1, (int) $top_k ) );
	}

	// ── Private math helpers ─────────────────────────────────────────────────

	/**
	 * Compute cosine similarity between two equal-length float vectors.
	 *
	 * Accepts a pre-computed magnitude for vector $a to avoid repeating the
	 * sqrt() for every stored chunk in the loop.
	 *
	 * @param float[] $a           Query vector.
	 * @param float[] $b           Stored chunk vector (same dimension as $a).
	 * @param float   $magnitude_a Pre-computed L2 magnitude of $a.
	 * @return float Similarity score in [−1, 1]. Returns 0.0 if $b is a zero vector.
	 */
	private static function cosine_similarity(
		array $a,
		array $b,
		float $magnitude_a
	): float {
		$magnitude_b = self::magnitude( $b );

		if ( 0.0 === $magnitude_b ) {
			return 0.0;
		}

		$dot = 0.0;
		$len = count( $a );

		for ( $i = 0; $i < $len; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
		}

		return $dot / ( $magnitude_a * $magnitude_b );
	}

	/**
	 * Compute the L2 (Euclidean) magnitude of a float vector.
	 *
	 * @param float[] $vector Dense float array.
	 * @return float Non-negative magnitude. Returns 0.0 for empty input.
	 */
	private static function magnitude( array $vector ): float {
		$sum = 0.0;

		foreach ( $vector as $v ) {
			$sum += $v * $v;
		}

		return (float) sqrt( $sum );
	}
}
