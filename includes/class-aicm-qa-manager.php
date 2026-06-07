<?php
/**
 * Q&A Manager
 *
 * CRUD and semantic-matching for the admin-managed Q&A pairs stored in aicm_qa.
 *
 * ── When matching fires ──────────────────────────────────────────────────────
 * The conversation handler calls find_match() at the very start of each turn,
 * before any RAG retrieval or LLM call. When a pair is found above
 * QA_MATCH_THRESHOLD the stored answer is returned immediately — zero API
 * cost, guaranteed accuracy, and no latency from an LLM round-trip.
 *
 * ── Matching algorithm ───────────────────────────────────────────────────────
 *  1. Embed the user query via the configured provider.
 *  2. Fetch all active rows that have a stored question_embedding.
 *  3. Unpack each binary BLOB with unpack('f*', $blob) → array_values().
 *  4. Compute cosine similarity; keep the highest-scoring row.
 *  5. Return that row only if score >= QA_MATCH_THRESHOLD; null otherwise.
 *
 * Where two pairs have equal similarity, the one with the lower priority
 * number (higher priority) is preferred — rows are fetched ORDER BY
 * priority ASC so the first maximally-scored row wins.
 *
 * ── Threshold rationale ──────────────────────────────────────────────────────
 * 0.92 is intentionally strict: Q&A pairs should only fire on near-identical
 * questions. A loose threshold would intercept general queries the admin
 * did not configure a specific answer for.
 *
 * ── Embedding storage format ─────────────────────────────────────────────────
 * Same as aicm_chunks.embedding: pack('f*', ...$floats) packed binary stored
 * in LONGBLOB. Retrieved with array_values(unpack('f*', $blob)) for 0-indexing.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_QA_Manager
 */
class AICM_QA_Manager {

	/**
	 * Cosine similarity threshold for Q&A matching.
	 * Must be reached or exceeded for a pair to be considered a match.
	 */
	private const QA_MATCH_THRESHOLD = 0.92;

	// ── CRUD ──────────────────────────────────────────────────────────────────

	/**
	 * Fetch all Q&A rows for the admin list view.
	 *
	 * Embeddings are excluded — large binary columns are not needed for display.
	 *
	 * @return array[] Rows ordered by priority ASC, id ASC.
	 *                 Each: { id, question, answer, priority, is_active, match_count, created_at, updated_at }
	 */
	public static function get_all(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_qa';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, question, answer, priority, is_active, match_count, created_at, updated_at
			   FROM `{$table}`
			  ORDER BY priority ASC, id ASC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Fetch a single Q&A row by ID (without embedding).
	 *
	 * @param int $id Row ID.
	 * @return array|null Row data array, or null if not found.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_qa';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, question, answer, priority, is_active, match_count, created_at, updated_at
				   FROM `{$table}`
				  WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update a Q&A row, then (re-)embed the question when it changes.
	 *
	 * When $data['id'] > 0 the row is updated; otherwise a new row is inserted.
	 * The question is re-embedded only when its text has changed (or on insert)
	 * to avoid unnecessary embedding API calls.
	 *
	 * Embedding is attempted as a best-effort step after the DB write. If the
	 * provider is not configured or the API call fails, the row is still saved
	 * without an embedding — it will not participate in matching until embedded.
	 *
	 * @param array $data {
	 *   'id'        int|null  (optional) Row ID for updates; omit/0 for inserts.
	 *   'question'  string    (required) Question text.
	 *   'answer'    string    (required) Answer text (stored as plain text).
	 *   'priority'  int       1–100; default 50.
	 *   'is_active' int|bool  1 = active, 0 = inactive; default 1.
	 * }
	 * @return int|WP_Error Saved row ID on success, WP_Error on validation/DB failure.
	 */
	public static function save( array $data ): int|WP_Error {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_qa';

		$question  = sanitize_text_field( wp_unslash( (string) ( $data['question'] ?? '' ) ) );
		$answer    = sanitize_textarea_field( wp_unslash( (string) ( $data['answer']   ?? '' ) ) );
		$priority  = max( 1, min( 100, (int) ( $data['priority']  ?? 50 ) ) );
		$is_active = (int) (bool) ( $data['is_active'] ?? 1 );

		if ( '' === $question || '' === $answer ) {
			return new WP_Error(
				'aicm_qa_invalid',
				__( 'Question and answer are required.', 'ai-chatmate' )
			);
		}

		$row_id     = (int) ( $data['id'] ?? 0 );
		$now        = current_time( 'mysql', true );
		$need_embed = true;

		if ( $row_id > 0 ) {
			// ── Update existing row ────────────────────────────────────────
			$existing = self::get( $row_id );

			if ( null === $existing ) {
				return new WP_Error(
					'aicm_qa_not_found',
					__( 'Q&A entry not found.', 'ai-chatmate' )
				);
			}

			// Only re-embed when the question text actually changed.
			$need_embed = ( $existing['question'] !== $question );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->update(
				$table,
				array(
					'question'   => $question,
					'answer'     => $answer,
					'priority'   => $priority,
					'is_active'  => $is_active,
					'updated_at' => $now,
				),
				array( 'id' => $row_id ),
				array( '%s', '%s', '%d', '%d', '%s' ),
				array( '%d' )
			);

			if ( false === $ok ) {
				return new WP_Error(
					'aicm_qa_db_error',
					__( 'Failed to update Q&A entry.', 'ai-chatmate' )
				);
			}
		} else {
			// ── Insert new row ─────────────────────────────────────────────
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert(
				$table,
				array(
					'question'    => $question,
					'answer'      => $answer,
					'priority'    => $priority,
					'is_active'   => $is_active,
					'match_count' => 0,
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
			);

			if ( false === $ok ) {
				return new WP_Error(
					'aicm_qa_db_error',
					__( 'Failed to insert Q&A entry.', 'ai-chatmate' )
				);
			}

			$row_id = (int) $wpdb->insert_id;
		}

		// ── Embed the question (best-effort; failure is non-fatal) ─────────
		if ( $need_embed ) {
			$provider = self::get_provider();

			if ( null !== $provider ) {
				$blob = self::make_embedding_blob( $provider, $question );

				if ( null !== $blob ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->update(
						$table,
						array( 'question_embedding' => $blob ),
						array( 'id' => $row_id ),
						array( '%s' ),
						array( '%d' )
					);
				}
			}
		}

		return $row_id;
	}

	/**
	 * Delete a Q&A row by ID.
	 *
	 * @param int $id Row ID.
	 * @return bool True if exactly one row was deleted.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_qa';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete(
			$table,
			array( 'id' => $id ),
			array( '%d' )
		);

		return ( false !== $deleted && $deleted > 0 );
	}

	// ── Matching ──────────────────────────────────────────────────────────────

	/**
	 * Find the best-matching Q&A pair for a user query.
	 *
	 * Returns null when:
	 *  - No active Q&A pairs with stored embeddings exist.
	 *  - The query cannot be embedded (API error).
	 *  - No pair reaches $threshold similarity.
	 *
	 * Side effect: increments match_count on the returned row (non-critical;
	 * a DB error here does not affect the return value).
	 *
	 * @param AICM_LLM_Provider $provider  Configured LLM provider (embeds the query).
	 * @param string            $query     The user's message.
	 * @param float             $threshold Cosine similarity threshold (0–1).
	 *                                     Defaults to QA_MATCH_THRESHOLD (0.92).
	 * @return array|null { id, question, answer, priority } on match; null otherwise.
	 */
	public static function find_match(
		AICM_LLM_Provider $provider,
		string            $query,
		float             $threshold = self::QA_MATCH_THRESHOLD
	): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_qa';

		// Fetch all active rows that have a stored embedding.
		// ORDER BY priority ASC so the highest-priority (lowest number) pair
		// wins when two rows have equal similarity.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, question, answer, priority, question_embedding
			   FROM `{$table}`
			  WHERE is_active = 1
			    AND question_embedding IS NOT NULL
			  ORDER BY priority ASC, id ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return null;
		}

		// Embed the user query.
		$query_vector = $provider->generate_embedding( $query );

		if ( empty( $query_vector ) ) {
			return null;
		}

		$query_mag = self::magnitude( $query_vector );

		if ( $query_mag < 1e-10 ) {
			return null;
		}

		$best_score = -1.0;
		$best_row   = null;

		foreach ( $rows as $row ) {
			$blob = $row['question_embedding'];

			if ( ! is_string( $blob ) || '' === $blob ) {
				continue;
			}

			// array_values() is required: unpack() returns a 1-indexed array.
			$stored_vector = array_values( unpack( 'f*', $blob ) );

			if ( count( $stored_vector ) !== count( $query_vector ) ) {
				// Dimension mismatch — stored embedding is from a different model.
				// Skip silently; it will be re-embedded on next save.
				continue;
			}

			$score = self::cosine_similarity( $query_vector, $stored_vector, $query_mag );

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_row   = $row;
			}
		}

		if ( null === $best_row || $best_score < $threshold ) {
			return null;
		}

		// Increment the match counter (non-critical — a DB error here is ignored).
		self::increment_match_count( (int) $best_row['id'] );

		return array(
			'id'       => (int) $best_row['id'],
			'question' => (string) $best_row['question'],
			'answer'   => (string) $best_row['answer'],
			'priority' => (int) $best_row['priority'],
		);
	}

	/**
	 * Increment the match_count for a Q&A row.
	 *
	 * @param int $id Row ID.
	 */
	public static function increment_match_count( int $id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aicm_qa';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET match_count = match_count + 1 WHERE id = %d",
				$id
			)
		);
	}

	// ── Private: provider ─────────────────────────────────────────────────────

	/**
	 * Instantiate the configured embedding provider.
	 *
	 * Returns null when no API key is stored (embedding would fail anyway).
	 *
	 * @return AICM_LLM_Provider|null
	 */
	private static function get_provider(): ?AICM_LLM_Provider {
		$active     = (string) AI_ChatMate::get_setting( 'active_provider', 'openai' );
		$option_key = "aicm_api_key_{$active}";

		if ( '' === (string) get_option( $option_key, '' ) ) {
			return null;
		}

		if ( 'openai' === $active ) {
			require_once AICM_PLUGIN_DIR . 'includes/providers/class-aicm-openai-provider.php';
			return new AICM_OpenAI_Provider();
		}

		return null;
	}

	// ── Private: embedding ────────────────────────────────────────────────────

	/**
	 * Generate an embedding for a text string and return it as a packed binary blob.
	 *
	 * @param AICM_LLM_Provider $provider Provider instance.
	 * @param string            $text     Text to embed.
	 * @return string|null Binary blob (pack('f*', ...)) on success, null on failure.
	 */
	private static function make_embedding_blob( AICM_LLM_Provider $provider, string $text ): ?string {
		$floats = $provider->generate_embedding( $text );

		if ( empty( $floats ) ) {
			return null;
		}

		return pack( 'f*', ...$floats );
	}

	// ── Private: vector math ──────────────────────────────────────────────────

	/**
	 * Compute cosine similarity between two float vectors.
	 *
	 * @param float[] $a           Query vector (0-indexed).
	 * @param float[] $b           Stored vector (0-indexed).
	 * @param float   $magnitude_a Pre-computed L2 norm of $a (avoids recomputing per row).
	 * @return float Similarity in [−1, 1]; −2.0 when $b has zero magnitude.
	 */
	private static function cosine_similarity( array $a, array $b, float $magnitude_a ): float {
		$mag_b = self::magnitude( $b );

		if ( $mag_b < 1e-10 ) {
			return -2.0;
		}

		$dot = 0.0;
		$len = count( $a );

		for ( $i = 0; $i < $len; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
		}

		return $dot / ( $magnitude_a * $mag_b );
	}

	/**
	 * Compute the L2 norm (Euclidean magnitude) of a float vector.
	 *
	 * @param float[] $vector Dense float array.
	 * @return float
	 */
	private static function magnitude( array $vector ): float {
		$sum = 0.0;

		foreach ( $vector as $v ) {
			$sum += $v * $v;
		}

		return sqrt( $sum );
	}
}
