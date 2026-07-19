<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Creative_Commons_Sharing
 */

// Load the composer autoloader.
$rtt_autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $rtt_autoload ) ) {
	fwrite( STDERR, "Composer autoloader not found. Run `composer install` before running the test suite.\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
	exit( 1 );
}
require_once $rtt_autoload;

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/republication-tracker-tool.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
