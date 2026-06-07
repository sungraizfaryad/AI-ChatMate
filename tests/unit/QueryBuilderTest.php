<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once AICM_PLUGIN_DIR . 'includes/class-aicm-query-builder.php';

final class QueryBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		AI_ChatMate::$test_settings = array( 'index_post_types' => array( 'post', 'page', 'listing' ) );

		// Pass-through sanitizers / unslash used by build().
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'taxonomy_exists' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_taxonomy_uses_slug_field_when_term_is_an_existing_slug(): void {
		// 'lisbon' resolves as a slug in taxonomy 'location'.
		Functions\when( 'get_term_by' )->alias(
			static fn( $field, $value, $tax ) => ( 'slug' === $field && 'lisbon' === $value )
				? new WP_Term( array( 'term_id' => 5, 'slug' => 'lisbon' ) )
				: false
		);

		$args = AICM_Query_Builder::build( array(
			'taxonomy_filters' => array( array( 'taxonomy' => 'location', 'term' => 'lisbon' ) ),
		) );

		$this->assertArrayHasKey( 'tax_query', $args );
		$this->assertSame( 'location', $args['tax_query'][0]['taxonomy'] );
		$this->assertSame( 'slug', $args['tax_query'][0]['field'] );
		$this->assertSame( 'lisbon', $args['tax_query'][0]['terms'] );
	}

	public function test_taxonomy_falls_back_to_name_when_term_is_not_a_slug(): void {
		// No slug match -> use display name matching.
		Functions\when( 'get_term_by' )->justReturn( false );

		$args = AICM_Query_Builder::build( array(
			'taxonomy_filters' => array( array( 'taxonomy' => 'location', 'term' => 'Greater Lisbon' ) ),
		) );

		$this->assertSame( 'name', $args['tax_query'][0]['field'] );
		$this->assertSame( 'Greater Lisbon', $args['tax_query'][0]['terms'] );
	}

	public function test_per_page_is_capped_at_ten(): void {
		// Regression: proves the harness exercises the real class.
		$args = AICM_Query_Builder::build( array( 'per_page' => 999 ) );
		$this->assertSame( 10, $args['posts_per_page'] );
	}
}
