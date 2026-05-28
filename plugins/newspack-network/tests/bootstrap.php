<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Newspack_Network_Hub
 */

$newspack_network_hub_test_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $newspack_network_hub_test_dir ) {
	$newspack_network_hub_test_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$newspack_network_hub_test_dir}/includes/functions.php" ) ) {
	echo "Could not find {$newspack_network_hub_test_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'IS_TEST_ENV', 1 );

// Give access to tests_add_filter() function.
require_once "{$newspack_network_hub_test_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function newspack_network_hub_manually_load_plugin() {
	require dirname( __DIR__ ) . '/newspack-network.php';
}

tests_add_filter( 'muplugins_loaded', 'newspack_network_hub_manually_load_plugin' );

require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Start up the WP testing environment.
require "{$newspack_network_hub_test_dir}/includes/bootstrap.php";
