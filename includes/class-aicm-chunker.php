<?php
/**
 * Chunker
 *
 * Splits extracted post text into overlapping chunks suitable for
 * embedding and semantic search retrieval.
 *
 * ── Token estimation ──────────────────────────────────────────────────────
 * We use the standard "1 token ≈ 4 characters" rule for English text.
 * This approximation is used by OpenAI's own tokenizer documentation
 * and is standard practice in WordPress plugins that lack a native PHP
 * tokenizer (tiktoken is Python-only). The estimate is conservative —
 * it slightly overestimates tokens, so chunks stay safely below the
 * embedding model's 8,192-token hard limit.
 *
 * ── Chunk size ────────────────────────────────────────────────────────────
 * Target: 400 tokens (1,600 chars) per chunk.
 * Reason: sweet spot between retrieval precision (shorter = more specific)
 * and retrieval context (longer = more surrounding detail). LlamaIndex and
 * LangChain both default to 512 tokens; we use 400 to be even more precise
 * because our dataset is structured WordPress content, not generic prose.
 *
 * ── Overlap ───────────────────────────────────────────────────────────────
 * Adjacent chunks share ~50 tokens (200 chars) of text at their boundary.
 * This ensures that a user's question spanning two chunk boundaries will
 * find context in at least one of those chunks.
 *
 * ── Break-point priority ──────────────────────────────────────────────────
 * We never break in the middle of a word. The algorithm searches backwards
 * from the target end position for a natural language boundary in this order:
 *   1. Paragraph break  (\n\n)   — best split location
 *   2. Line break       (\n)     — good split
 *   3. Sentence end     (. ? ! ) — acceptable split
 *   4. Word boundary    ( )      — fallback
 *   5. Hard cut         (none found) — last resort, still within MAX_CHARS
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Chunker
 */
class AICM_Chunker {

	// ── Character-based constants (tokens × 4 chars/token) ───────────────────

	/**
	 * Target chunk size in characters (~400 tokens).
	 */
	private const TARGET_CHARS = 1600;

	/**
	 * Overlap between adjacent chunks in characters (~50 tokens).
	 */
	private const OVERLAP_CHARS = 200;

	/**
	 * Backward search window for finding a break point.
	 * We look up to this many characters before the target end position.
	 * 400 chars = ~100 tokens; wide enough to find a sentence break.
	 */
	private const SEARCH_WINDOW = 400;

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Split plain text into overlapping chunks.
	 *
	 * Returns an empty array when $text is blank.
	 * Returns a single-element array when the text is short enough to fit
	 * in one chunk without splitting.
	 *
	 * Each returned chunk array has three keys:
	 *  - 'chunk_index'  int    Zero-based position within this post.
	 *  - 'chunk_text'   string Clean text (whitespace-trimmed).
	 *  - 'token_count'  int    Estimated token count (ceil(mb_strlen / 4)).
	 *
	 * @param string $text Plain text from AICM_Text_Extractor::extract().
	 * @return array[] Array of chunk arrays.
	 */
	public static function chunk( string $text ): array {
		$text = trim( $text );

		if ( '' === $text ) {
			return array();
		}

		$text_len = mb_strlen( $text );

		// ── Short text: one chunk, no splitting needed ─────────────────────
		if ( $text_len <= self::TARGET_CHARS ) {
			return array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => $text,
					'token_count' => self::estimate_tokens( $text ),
				),
			);
		}

		// ── Long text: split into overlapping chunks ───────────────────────
		$chunks      = array();
		$start       = 0;
		$chunk_index = 0;

		while ( $start < $text_len ) {
			$ideal_end = $start + self::TARGET_CHARS;

			// Reached end of text — take the remainder as the final chunk.
			if ( $ideal_end >= $text_len ) {
				$chunk_text = mb_substr( $text, $start );
				$chunk_text = trim( $chunk_text );

				if ( '' !== $chunk_text ) {
					$chunks[] = array(
						'chunk_index' => $chunk_index,
						'chunk_text'  => $chunk_text,
						'token_count' => self::estimate_tokens( $chunk_text ),
					);
				}
				break;
			}

			// Find a natural language break point near the target end.
			$break_at = self::find_break_point( $text, $ideal_end, $start );

			$chunk_text = mb_substr( $text, $start, $break_at - $start );
			$chunk_text = trim( $chunk_text );

			if ( '' !== $chunk_text ) {
				$chunks[] = array(
					'chunk_index' => $chunk_index,
					'chunk_text'  => $chunk_text,
					'token_count' => self::estimate_tokens( $chunk_text ),
				);
				++$chunk_index;
			}

			// ── Calculate next chunk's start position (with overlap) ───────
			// Go back OVERLAP_CHARS from the break point so adjacent chunks
			// share context. Then snap to the nearest word boundary so the
			// new chunk never starts in the middle of a word.
			$next_start = $break_at - self::OVERLAP_CHARS;
			$next_start = max( $start + 1, $next_start ); // Must advance forward.
			$next_start = self::snap_to_word_start( $text, $next_start, $break_at );

			// Absolute safety: if something above returns a position that has
			// not advanced past $start, force progress by 1 character.
			if ( $next_start <= $start ) {
				$next_start = $start + 1;
			}

			$start = $next_start;
		}

		return $chunks;
	}

	/**
	 * Estimate the number of tokens in a string.
	 *
	 * Public so that AICM_Embedder (and tests) can use it without
	 * duplicating the formula.
	 *
	 * Formula: ceil(mb_strlen / 4)
	 * Rationale: "~4 characters per token" is the approximation OpenAI
	 * publishes for average English text. ceil() ensures we never
	 * under-count, keeping chunks safely within model limits.
	 *
	 * @param string $text
	 * @return int
	 */
	public static function estimate_tokens( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / 4 );
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Find the best break point in $text near $ideal_end.
	 *
	 * Searches BACKWARDS from $ideal_end within a window of SEARCH_WINDOW
	 * characters, looking for natural language boundaries in priority order:
	 *   1. \n\n  — paragraph break
	 *   2. \n    — line break
	 *   3. . ? ! — sentence-ending punctuation followed by a space
	 *   4. " "   — word boundary (space)
	 *   5. hard cut at $ideal_end (fallback — breaks mid-word if unavoidable)
	 *
	 * We always search backwards (from ideal_end toward the start of the
	 * window) so the break point never exceeds ideal_end and chunks stay
	 * within the TARGET_CHARS limit.
	 *
	 * @param string $text      Full text being chunked.
	 * @param int    $ideal_end Preferred end position (TARGET_CHARS from start).
	 * @param int    $min_start Do not search before this position.
	 * @return int              Character offset for the break (exclusive end of chunk).
	 */
	private static function find_break_point( string $text, int $ideal_end, int $min_start ): int {
		$text_len   = mb_strlen( $text );
		$search_end = min( $ideal_end, $text_len );

		// Search window: [search_start, search_end).
		// Clamp so we never look before $min_start + 1 (prevents zero-length chunks).
		$search_start = max( $min_start + 1, $search_end - self::SEARCH_WINDOW );

		// Extract only the search window to avoid scanning the full text.
		$window      = mb_substr( $text, $search_start, $search_end - $search_start );
		$window_len  = mb_strlen( $window );

		if ( 0 === $window_len ) {
			return $search_end;
		}

		// ── Priority 1: paragraph break (\n\n) ────────────────────────────
		$pos = mb_strrpos( $window, "\n\n" );
		if ( false !== $pos ) {
			// +2 to position AFTER the second newline (start of next paragraph).
			return $search_start + $pos + 2;
		}

		// ── Priority 2: single line break (\n) ────────────────────────────
		$pos = mb_strrpos( $window, "\n" );
		if ( false !== $pos ) {
			return $search_start + $pos + 1;
		}

		// ── Priority 3: sentence-ending punctuation + space ───────────────
		// We require a trailing space so we don't split on decimal points
		// like "3.14" or domain names like "example.com".
		foreach ( array( '. ', '? ', '! ', '… ' ) as $sentence_end ) {
			$pos = mb_strrpos( $window, $sentence_end );
			if ( false !== $pos ) {
				// +1 to include the punctuation mark in this chunk, then
				// the space becomes the start of the next chunk.
				return $search_start + $pos + 1;
			}
		}

		// ── Priority 4: word boundary (space) ─────────────────────────────
		$pos = mb_strrpos( $window, ' ' );
		if ( false !== $pos ) {
			// +1 to place the break after the space (next chunk starts clean).
			return $search_start + $pos + 1;
		}

		// ── Priority 5: hard cut (no break found) ─────────────────────────
		// This happens on continuous strings (e.g., a very long URL).
		// The chunk may break mid-word, but this is extremely rare.
		return $search_end;
	}

	/**
	 * Advance $pos forward until it sits at the start of a word.
	 *
	 * After applying the overlap step, $pos may land in the middle of a word
	 * (e.g., pointing at the 'e' in "example"). This method advances forward
	 * until $pos points to a position immediately after a space or newline
	 * (i.e., the first character of a word).
	 *
	 * We advance forward (not backward) because we want to ensure the
	 * overlap chunk contains COMPLETE words — truncating the start of a word
	 * would create a broken token in the embedding.
	 *
	 * @param string $text    Full text.
	 * @param int    $pos     Starting position (may be mid-word).
	 * @param int    $max_pos Do not advance past this position.
	 * @return int            Position at the start of the next word, or $max_pos.
	 */
	private static function snap_to_word_start( string $text, int $pos, int $max_pos ): int {
		// Position 0 is always a valid start.
		if ( $pos <= 0 ) {
			return 0;
		}

		// Check the character immediately before $pos. If it is a separator,
		// we are already at a word boundary.
		$char_before = mb_substr( $text, $pos - 1, 1 );
		if ( ' ' === $char_before || "\n" === $char_before ) {
			return $pos;
		}

		// Advance forward until we find a space or newline.
		while ( $pos < $max_pos ) {
			$char = mb_substr( $text, $pos, 1 );
			if ( ' ' === $char || "\n" === $char ) {
				// Return $pos + 1 so the chunk starts on the character AFTER
				// the separator, not on the separator itself.
				return $pos + 1;
			}
			++$pos;
		}

		// Could not find a word boundary before $max_pos — return $max_pos.
		return $max_pos;
	}
}
