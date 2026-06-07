<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once AICM_PLUGIN_DIR . 'includes/class-aicm-field-config.php';

final class FieldConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function schema(): array {
		return array(
			'post_types' => array(
				'listing' => array(
					'label' => 'Listings',
					'taxonomies' => array(
						'location' => array( 'label' => 'Location', 'terms' => array( 'lisbon' ) ),
						'mood'     => array( 'label' => 'Mood', 'terms' => array( 'calm' ) ),
					),
					'meta_fields' => array(
						'price'  => array( 'label' => 'Price', 'type' => 'numeric' ),
						'secret' => array( 'label' => 'Secret', 'type' => 'text' ),
					),
				),
			),
		);
	}

	public function test_sanitize_normalizes_keys_bools_and_labels(): void {
		$raw = array(
			'List!ing' => array(
				'taxonomies' => array( 'Location' => 1, 'bad' => 0 ),
				'meta'       => array(
					'price' => array( 'included' => 'yes', 'label' => 'Asking Price' ),
					'junk'  => array( 'included' => false, 'label' => '' ),
				),
			),
		);

		$clean = AICM_Field_Config::sanitize( $raw );

		$this->assertArrayHasKey( 'listing', $clean );                     // post type key sanitized
		$this->assertTrue( $clean['listing']['taxonomies']['location'] );  // truthy -> true
		$this->assertFalse( $clean['listing']['taxonomies']['bad'] );      // 0 -> false
		$this->assertTrue( $clean['listing']['meta']['price']['included'] );
		$this->assertSame( 'Asking Price', $clean['listing']['meta']['price']['label'] );
		$this->assertFalse( $clean['listing']['meta']['junk']['included'] );
		$this->assertSame( '', $clean['listing']['meta']['junk']['label'] );
	}

	public function test_sanitize_ignores_non_array_input(): void {
		$this->assertSame( array(), AICM_Field_Config::sanitize( 'not-an-array' ) );
		$this->assertSame( array(), AICM_Field_Config::sanitize( array( 'pt' => 'nope' ) ) );
	}

	public function test_apply_drops_excluded_and_overrides_labels(): void {
		$config = array(
			'listing' => array(
				'taxonomies' => array( 'mood' => false ),
				'meta'       => array(
					'price'  => array( 'included' => true, 'label' => 'Asking Price' ),
					'secret' => array( 'included' => false, 'label' => '' ),
				),
			),
		);

		$out = AICM_Field_Config::apply( $this->schema(), $config );
		$pt  = $out['post_types']['listing'];

		$this->assertArrayHasKey( 'location', $pt['taxonomies'] );
		$this->assertArrayNotHasKey( 'mood', $pt['taxonomies'] );       // excluded
		$this->assertArrayHasKey( 'price', $pt['meta_fields'] );
		$this->assertArrayNotHasKey( 'secret', $pt['meta_fields'] );    // excluded
		$this->assertSame( 'Asking Price', $pt['meta_fields']['price']['label'] ); // override
	}

	public function test_apply_is_noop_without_config(): void {
		$out = AICM_Field_Config::apply( $this->schema(), array() );
		$this->assertArrayHasKey( 'mood', $out['post_types']['listing']['taxonomies'] );
	}
}
