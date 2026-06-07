<?php
use PHPUnit\Framework\TestCase;

require_once AICM_PLUGIN_DIR . 'includes/schema/class-aicm-schema-catalog.php';

final class SchemaCatalogTest extends TestCase {

	private function schema(): array {
		return array(
			'post_types' => array(
				'listing' => array(
					'label' => 'Listings',
					'count' => 320,
					'taxonomies' => array(
						'location' => array(
							'label' => 'Location',
							'terms' => array( 'lisbon', 'porto', 'algarve' ),
							'term_count' => 41,
							'truncated' => false,
						),
					),
					'meta_fields' => array(
						'price'    => array( 'label' => 'Price', 'type' => 'numeric', 'source' => 'acf', 'min' => 50000.0, 'max' => 2500000.0 ),
						'has_pool' => array( 'label' => 'Has Pool', 'type' => 'boolean', 'source' => 'acf' ),
						'energy'   => array( 'label' => 'Energy', 'type' => 'text', 'source' => 'acf', 'choices' => array( 'A', 'B', 'C' ) ),
					),
				),
				'post' => array( 'label' => 'Posts', 'count' => 12, 'taxonomies' => array(), 'meta_fields' => array() ),
			),
		);
	}

	public function test_block_lists_only_configured_types_with_slugs(): void {
		$block = AICM_Schema_Catalog::build_prompt_block( $this->schema(), array( 'listing' ) );

		$this->assertStringContainsString( 'listing', $block );
		$this->assertStringContainsString( 'location', $block );      // taxonomy slug
		$this->assertStringContainsString( 'lisbon', $block );        // term slug
		$this->assertStringContainsString( 'price', $block );         // meta key
		$this->assertStringContainsString( 'numeric', $block );
		$this->assertStringContainsString( '50000', $block );         // min
		$this->assertStringContainsString( 'A', $block );             // choice
		$this->assertStringNotContainsString( 'Posts', $block );      // 'post' not in configured types
	}

	public function test_block_is_empty_when_no_schema(): void {
		$this->assertSame( '', AICM_Schema_Catalog::build_prompt_block( array(), array( 'listing' ) ) );
		$this->assertSame( '', AICM_Schema_Catalog::build_prompt_block( $this->schema(), array( 'nonexistent' ) ) );
	}

	public function test_block_truncates_long_term_lists(): void {
		$schema = $this->schema();
		$schema['post_types']['listing']['taxonomies']['location']['terms'] = array_map(
			static fn( $i ) => 'term' . $i,
			range( 1, 100 )
		);
		$block = AICM_Schema_Catalog::build_prompt_block( $schema, array( 'listing' ) );
		$this->assertStringContainsString( 'more', $block ); // notes the truncation
		$this->assertStringNotContainsString( 'term99', $block );
	}
}
