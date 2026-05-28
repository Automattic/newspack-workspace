<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Newspack_Listings
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	$_SERVER['HTTP_REFERER'] = 'https://' . $_SERVER['HTTP_HOST']; // phpcs:ignore
	$_SERVER['HTTP_USER_AGENT'] = 'Mozilla\/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit\/537.36 (KHTML, like Gecko) Chrome\/89.0.4389.90 Safari\/537.36'; // phpcs:ignore

	require dirname( __DIR__ ) . '/newspack-listings.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );


/**
 * Set the option flags required for testing listing archive queries.
 */
function _update_required_options() {
	// Ensure listing posts can appear on archive pages.
	update_option( 'newspack_listings_enable_post_type_archives', true );
	update_option( 'newspack_listings_enable_term_archives', true );
}
tests_add_filter( 'init', '_update_required_options' );

define( 'IS_TEST_ENV', 1 );
define( 'NEWSPACK_LISTINGS_SELF_SERVE_ENABLED', true );

// Load the composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
