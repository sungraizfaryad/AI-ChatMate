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

		// Sanitizers / unslash used by build(). sanitize_text_field must mimic
		// the real WP behaviour of eating '<…' as a partial HTML tag — the old
		// pass-through stub HID the bug where '<=' became '=' in production
		// while tests stayed green.
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
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

	public function test_less_than_or_equal_operator_survives_and_gets_numeric_type(): void {
		// Regression: sanitize_text_field() strips '<' as a partial HTML tag,
		// so '<=' silently became '=' — every "under X" search matched only
		// the exact value. The operator must bypass that sanitiser.
		$args = AICM_Query_Builder::build( array(
			'meta_filters' => array(
				array(
					'key'     => 'price',
					'value'   => '2000000',
					'compare' => '<=',
				),
			),
		) );

		$clause = $args['meta_query'][0];
		$this->assertSame( '<=', $clause['compare'], "'<=' was mangled by sanitisation." );
		$this->assertSame( 'NUMERIC', $clause['type'] ?? null, 'Range compare on a number must use NUMERIC type.' );
	}

	public function test_human_style_amounts_are_normalised_to_plain_numbers(): void {
		$cases = array(
			'2 million'   => '2000000',
			'€1,500,000'  => '1500000',
			'950k'        => '950000',
			'1.5M'        => '1500000',
			// European decimal comma must mean 1.5, never 15.
			'1,5 million' => '1500000',
			'2,75M'       => '2750000',
		);

		foreach ( $cases as $input => $expected ) {
			$args = AICM_Query_Builder::build( array(
				'meta_filters' => array(
					array(
						'key'     => 'price',
						'value'   => $input,
						'compare' => '<=',
					),
				),
			) );

			$clause = $args['meta_query'][0];
			$this->assertSame( $expected, $clause['value'], "'{$input}' not normalised." );
			$this->assertSame( 'NUMERIC', $clause['type'] ?? null, "'{$input}' did not get NUMERIC type." );
		}
	}

	public function test_protected_underscore_meta_keys_are_rejected(): void {
		// The public /chat endpoint must not be usable as an existence or
		// range oracle on hidden meta (_edit_lock, plugin internals, …).
		$args = AICM_Query_Builder::build( array(
			'meta_filters' => array(
				array(
					'key'     => '_secret_internal',
					'compare' => 'EXISTS',
				),
			),
		) );

		$this->assertArrayNotHasKey( 'meta_query', $args );
	}

	public function test_unparseable_value_is_left_alone_without_numeric_type(): void {
		$args = AICM_Query_Builder::build( array(
			'meta_filters' => array(
				array(
					'key'     => 'price',
					'value'   => 'cheap',
					'compare' => '<=',
				),
			),
		) );

		$clause = $args['meta_query'][0];
		$this->assertSame( 'cheap', $clause['value'] );
		$this->assertArrayNotHasKey( 'type', $clause );
	}
}
