<?php
/**
 * Schema Catalog
 *
 * Turns the auto-discovered schema array into compact, token-budgeted text the
 * chat model can read: a system-prompt block and short hint strings for the
 * search_posts function definition. Pure functions over arrays — no WordPress
 * calls — so the model is always handed real slugs, term slugs, and meta keys
 * instead of guessing values that silently match nothing.
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Schema_Catalog
 */
class AICM_Schema_Catalog {

	/** Maximum term slugs listed per taxonomy (most-used first). */
	private const MAX_TERMS_PER_TAX = 25;

	/** Maximum meta fields listed per post type. */
	private const MAX_META_PER_TYPE = 40;

	/** Hard cap on the prompt block size to keep token cost predictable. */
	private const MAX_BLOCK_CHARS = 4000;

	/**
	 * Build the human-readable catalog block injected into the system prompt.
	 *
	 * @param array    $schema Cached schema (AICM_Schema_Cache::get()).
	 * @param string[] $types  Admin-configured post types to expose.
	 * @return string Catalog text, or '' when nothing to expose.
	 */
	public static function build_prompt_block( array $schema, array $types ): string {
		$post_types = $schema['post_types'] ?? array();
		if ( empty( $post_types ) || empty( $types ) ) {
			return '';
		}

		$parts = array();

		foreach ( $types as $pt ) {
			if ( ! isset( $post_types[ $pt ] ) ) {
				continue;
			}
			$data  = $post_types[ $pt ];
			$label = (string) ( $data['label'] ?? $pt );
			$count = (int) ( $data['count'] ?? 0 );

			$lines   = array();
			$lines[] = "### Post type: {$pt} ({$label}, {$count} published)";

			$tax_lines = self::taxonomy_lines( $data['taxonomies'] ?? array() );
			if ( ! empty( $tax_lines ) ) {
				$lines[] = 'Taxonomies (use in taxonomy_filters, term values are slugs):';
				$lines   = array_merge( $lines, $tax_lines );
			}

			$meta_lines = self::meta_lines( $data['meta_fields'] ?? array() );
			if ( ! empty( $meta_lines ) ) {
				$lines[] = 'Meta fields (use in meta_filters by key):';
				$lines   = array_merge( $lines, $meta_lines );
			}

			$parts[] = implode( "\n", $lines );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		$block = implode( "\n\n", $parts );

		if ( mb_strlen( $block ) > self::MAX_BLOCK_CHARS ) {
			$block = mb_substr( $block, 0, self::MAX_BLOCK_CHARS ) . "\n… (catalog truncated)";
		}

		return $block;
	}

	/**
	 * Compact hint strings used to enrich the search_posts function definition.
	 *
	 * @param array    $schema Cached schema.
	 * @param string[] $types  Admin-configured post types.
	 * @return array{post_types: string[], taxonomy_hint: string, meta_hint: string}
	 */
	public static function function_hints( array $schema, array $types ): array {
		$post_types = $schema['post_types'] ?? array();
		$present    = array();
		$tax_bits   = array();
		$meta_bits  = array();

		foreach ( $types as $pt ) {
			if ( ! isset( $post_types[ $pt ] ) ) {
				continue;
			}
			$present[] = $pt;

			$taxes = array();
			foreach ( (array) ( $post_types[ $pt ]['taxonomies'] ?? array() ) as $tax => $info ) {
				$terms   = array_slice( (array) ( $info['terms'] ?? array() ), 0, 8 );
				$taxes[] = $tax . '(' . implode( ',', $terms ) . ')';
			}
			if ( ! empty( $taxes ) ) {
				$tax_bits[] = $pt . ': ' . implode( '; ', $taxes );
			}

			$metas = array();
			foreach ( (array) ( $post_types[ $pt ]['meta_fields'] ?? array() ) as $key => $info ) {
				$metas[] = $key . '(' . (string) ( $info['type'] ?? 'text' ) . ')';
			}
			if ( ! empty( $metas ) ) {
				$meta_bits[] = $pt . ': ' . implode( ', ', array_slice( $metas, 0, self::MAX_META_PER_TYPE ) );
			}
		}

		return array(
			'post_types'    => $present,
			'taxonomy_hint' => implode( ' | ', $tax_bits ),
			'meta_hint'     => implode( ' | ', $meta_bits ),
		);
	}

	/**
	 * Format taxonomy lines for the prompt block.
	 *
	 * @param array $taxonomies Map of tax_slug => info.
	 * @return string[] Lines.
	 */
	private static function taxonomy_lines( array $taxonomies ): array {
		$lines = array();
		foreach ( $taxonomies as $tax => $info ) {
			$all   = (array) ( $info['terms'] ?? array() );
			$label = (string) ( $info['label'] ?? $tax );
			$shown = array_slice( $all, 0, self::MAX_TERMS_PER_TAX );
			$line  = "- {$tax} ({$label}): " . implode( ', ', $shown );

			$extra = count( $all ) - count( $shown );
			if ( $extra > 0 ) {
				$line .= " (+{$extra} more)";
			} elseif ( ! empty( $info['truncated'] ) ) {
				$line .= ' (+ more)';
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Format meta-field lines for the prompt block.
	 *
	 * @param array $meta_fields Map of meta_key => info.
	 * @return string[] Lines.
	 */
	private static function meta_lines( array $meta_fields ): array {
		$lines = array();
		$i     = 0;
		foreach ( $meta_fields as $key => $info ) {
			if ( $i >= self::MAX_META_PER_TYPE ) {
				$lines[] = '- … (more fields available)';
				break;
			}
			$type = (string) ( $info['type'] ?? 'text' );
			$desc = $type;

			if ( 'numeric' === $type && isset( $info['min'], $info['max'] ) ) {
				$desc .= ' ' . self::num( $info['min'] ) . '–' . self::num( $info['max'] );
			}
			if ( ! empty( $info['choices'] ) && is_array( $info['choices'] ) ) {
				$desc .= ': ' . implode( ', ', array_slice( $info['choices'], 0, 12 ) );
			}

			$lines[] = "- {$key} [{$desc}]";
			++$i;
		}
		return $lines;
	}

	/**
	 * Format a numeric value without trailing decimals for whole numbers.
	 *
	 * @param mixed $n Numeric value.
	 * @return string
	 */
	private static function num( $n ): string {
		$f = (float) $n;
		return ( floor( $f ) === $f ) ? (string) (int) $f : (string) $f;
	}
}
