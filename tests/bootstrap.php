<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
}
if ( ! defined( 'AICM_PLUGIN_DIR' ) ) {
	define( 'AICM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

require_once __DIR__ . '/stubs/wp-classes.php';
