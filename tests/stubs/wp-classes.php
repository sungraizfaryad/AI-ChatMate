<?php
// Minimal WordPress class stubs + an AI_ChatMate test double for unit tests.
// Real classes are never loaded in unit tests (they need a running WordPress).

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_id = 0;
		public $name    = '';
		public $slug    = '';
		public function __construct( array $props = array() ) {
			foreach ( $props as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID           = 0;
		public $post_title   = '';
		public $post_content = '';
		public $post_date    = '';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
	}
}

if ( ! class_exists( 'AI_ChatMate' ) ) {
	// Test double: tests set AI_ChatMate::$test_settings before calling code under test.
	class AI_ChatMate {
		public static array $test_settings = array();
		public static function get_setting( ?string $key = null, $default = null ) {
			if ( null === $key ) {
				return self::$test_settings;
			}
			return self::$test_settings[ $key ] ?? $default;
		}
	}
}
