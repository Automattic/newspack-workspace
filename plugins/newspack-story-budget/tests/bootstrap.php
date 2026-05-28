<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Newspack_Story_Budget
 */

$test_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $test_dir ) {
	$test_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$test_dir}/includes/functions.php" ) ) {
	echo "Could not find {$test_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'IS_TEST_ENV', 1 );

// Give access to tests_add_filter() function.
require_once "{$test_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function newspack_story_budget_manually_load_plugin() {
	require dirname( __DIR__ ) . '/newspack-story-budget.php';
}

tests_add_filter( 'muplugins_loaded', 'newspack_story_budget_manually_load_plugin' );

require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Start up the WP testing environment.
require "{$test_dir}/includes/bootstrap.php";
