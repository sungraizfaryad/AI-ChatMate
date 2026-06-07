<?php
/**
 * Text Extractor
 *
 * Converts a WordPress post and its meta into clean plain text suitable
 * for embedding and semantic search.
 *
 * What we STRIP:
 *  - Shortcode tags      — strip_shortcodes() removes them WITHOUT executing.
 *                          We never call do_shortcode() to avoid side effects
 *                          (DB queries, asset loading, output buffering).
 *  - Gutenberg comments  — <!-- wp:block-name --> stripped by regex.
 *  - HTML tags           — wp_strip_all_tags() is WordPress-safe (not strip_tags()).
 *  - HTML entities       — decoded to UTF-8 characters (&amp; → &, &nbsp; → space).
 *  - Excess whitespace   — collapsed to single spaces / max two newlines.
 *
 * What we INCLUDE (in priority order for the AI):
 *  1. Post title         — strongest relevance signal.
 *  2. Post excerpt       — often a hand-written summary; high quality signal.
 *  3. Post content body  — cleaned HTML / block content.
 *  4. Relevant meta      — searchable fields (price, SKU, custom field values).
 *
 * What we EXCLUDE from meta:
 *  - Private keys (_ prefix) unless in ALLOWED_PRIVATE_META allowlist.
 *  - URL-only values       — no semantic search value.
 *  - Values longer than 500 chars — likely a base64/serialized blob.
 *  - Empty / whitespace-only values.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Text_Extractor
 */
class AICM_Text_Extractor {

	/**
	 * Private meta keys that are explicitly allowed because they hold
	 * user-visible content relevant to search (WooCommerce product fields).
	 *
	 * @var string[]
	 */
	private const ALLOWED_PRIVATE_META = array(
		'_price',
		'_regular_price',
		'_sale_price',
		'_sku',
		'_stock',
		'_stock_status',
	);

	/**
	 * Maximum character length for a single meta value to be included.
	 * Longer values are likely serialized data or base64 blobs.
	 */
	private const META_VALUE_MAX_LENGTH = 500;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Extract clean plain text from a post and its meta.
	 *
	 * The returned string is UTF-8, HTML-free, and has normalised whitespace.
	 * It is ready to be passed directly to AICM_Chunker::chunk().
	 *
	 * @param WP_Post $post The post object.
	 * @param array   $meta Flat meta array: key => scalar string value.
	 *                      Produced by AICM_Content_Fetcher::get_post_data().
	 * @return string       Plain text. Empty string if the post has no content.
	 */
	public static function extract( WP_Post $post, array $meta = array() ): string {
		$parts = array();

		// 1. Title — always first so it appears at the top of the text
		// and in every chunk's context prefix.
		$title = trim( $post->post_title );
		if ( '' !== $title ) {
			$parts[] = $title;
		}

		// 2. Excerpt — skip empty or auto-generated excerpts.
		// post_excerpt is empty string when WordPress would auto-generate it.
		$excerpt = trim( $post->post_excerpt );
		if ( '' !== $excerpt ) {
			$parts[] = self::clean_html( $excerpt );
		}

		// 3. Post content body.
		$content = trim( $post->post_content );
		if ( '' !== $content ) {
			$parts[] = self::clean_html( $content );
		}

		// 4. Meta fields — human-readable key: value lines.
		$meta_text = self::extract_meta_text( $meta );
		if ( '' !== $meta_text ) {
			$parts[] = $meta_text;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		// Join sections with a blank line between them.
		$combined = implode( "\n\n", $parts );

		// Final whitespace normalisation.
		$combined = self::normalise_whitespace( $combined );

		return $combined;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Strip shortcodes and HTML from a raw content string, then decode entities.
	 *
	 * @param string $raw Raw post_content or post_excerpt string.
	 * @return string     Clean plain text.
	 */
	private static function clean_html( string $raw ): string {
		// Step 1: Remove shortcode tags without executing them.
		// strip_shortcodes() only removes registered shortcodes.
		// Unknown shortcodes are left in. A second pass of wp_strip_all_tags()
		// below will catch any remaining [tag] patterns as part of generic
		// tag stripping — actually it won't because [..] is not an HTML tag.
		// We use preg_replace as a fallback for unregistered shortcodes.
		$text = strip_shortcodes( $raw );

		// Step 2: Remove any remaining [unregistered_shortcode ...] tags.
		// Pattern: matches [ followed by a letter, any content, then ].
		// Limit to 200 chars between brackets to avoid catastrophic backtracking.
		$text = preg_replace( '/\[[a-zA-Z][^\[\]]{0,200}\]/s', '', $text ) ?? $text;

		// Step 3: Strip Gutenberg block comment delimiters.
		// These are HTML comments: <!-- wp:block-name {"attr":"val"} --> and
		// <!-- /wp:block-name -->. Standard strip_tags does not remove comments.
		$text = preg_replace( '/<!--.*?-->/s', '', $text ) ?? $text;

		// Step 4: Strip all remaining HTML tags.
		// wp_strip_all_tags() is the WordPress-approved function — it also
		// handles <script> and <style> blocks (removes them completely,
		// not just the tags but also their content).
		$text = wp_strip_all_tags( $text, true );

		// Step 5: Decode HTML entities.
		// ENT_HTML5 handles HTML5-specific entities (&apos;, &euro; etc.).
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return self::normalise_whitespace( $text );
	}

	/**
	 * Build a human-readable text block from relevant meta fields.
	 *
	 * Each included field becomes a "Label: value" line so the AI can
	 * understand what the value refers to (e.g. "Price: 29.99").
	 *
	 * @param array $meta Flat key => string value meta array.
	 * @return string     Multi-line string, or '' if nothing to include.
	 */
	private static function extract_meta_text( array $meta ): string {
		if ( empty( $meta ) ) {
			return '';
		}

		$lines = array();

		foreach ( $meta as $key => $value ) {
			$key   = (string) $key;
			$value = trim( (string) $value );

			// Skip empty values.
			if ( '' === $value ) {
				continue;
			}

			// Skip private meta keys unless explicitly allowed.
			if ( str_starts_with( $key, '_' ) && ! in_array( $key, self::ALLOWED_PRIVATE_META, true ) ) {
				continue;
			}

			// Skip URL-only values — no semantic value for search.
			// FILTER_VALIDATE_URL returns the URL on match, false otherwise.
			if ( false !== filter_var( $value, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			// Skip oversized values — likely a missed serialized blob.
			if ( mb_strlen( $value ) > self::META_VALUE_MAX_LENGTH ) {
				continue;
			}

			// Build a human-readable label from the meta key.
			// Examples: _sku → SKU, event_date → Event Date, pa_color → Color
			$label = ltrim( $key, '_' );          // Remove leading underscore.
			$label = str_replace( array( '_', '-' ), ' ', $label ); // Delimiters → spaces.
			$label = ucwords( $label );            // Capitalise each word.

			$lines[] = "{$label}: {$value}";
		}

		return implode( "\n", $lines );
	}

	/**
	 * Normalise whitespace in a string.
	 *
	 * - Collapses horizontal whitespace (spaces, tabs) to a single space.
	 * - Collapses 3+ consecutive newlines to 2 (preserves paragraph breaks).
	 * - Trims leading and trailing whitespace.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private static function normalise_whitespace( string $text ): string {
		// Collapse horizontal whitespace (spaces, tabs) to a single space.
		$text = preg_replace( '/[ \t]+/', ' ', $text ) ?? $text;

		// Collapse 3+ consecutive newlines to 2 (preserve paragraph breaks).
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}
}
