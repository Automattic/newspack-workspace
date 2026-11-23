<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Newspack_Content_Blocks
 */

$newspack_content_blocks_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $newspack_content_blocks_tests_dir ) {
	$newspack_content_blocks_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $newspack_content_blocks_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $newspack_content_blocks_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $newspack_content_blocks_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function newspack_content_blocks_manually_load_plugin() {
	require dirname( __DIR__ ) . '/newspack-content-blocks.php';
}
tests_add_filter( 'muplugins_loaded', 'newspack_content_blocks_manually_load_plugin' );

// Print errors to stdout.
ini_set( 'error_log', 'php://stdout' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

// Load the composer autoloader. Use the plugin's own vendor directory if
// available, otherwise fall back to the monorepo root autoloader.
$autoloader_paths = [
	__DIR__ . '/../vendor/autoload.php', // Plugin vendor.
	dirname( dirname( dirname( __DIR__ ) ) ) . '/vendor/autoload.php', // Monorepo root.
];
$autoloader_loaded = false;
foreach ( $autoloader_paths as $autoloader_path ) {
	if ( file_exists( $autoloader_path ) ) {
		require_once $autoloader_path;
		$autoloader_loaded = true;
		break;
	}
}
if ( ! $autoloader_loaded ) {
	fwrite( STDERR, "Composer autoloader not found. Run `composer install` in plugins/newspack-content-blocks or at the monorepo root.\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite
	exit( 1 );
}

/**
 * Load test stubs for dependencies not available in the isolated test environment.
 *
 * After the autoloader deliberately: each class stub guards on class_exists(), which
 * can only find a real implementation once autoloading is available. The sponsors
 * stub defines namespaced functions, which nothing can autoload ahead of it, so it
 * needs no guard.
 */
require_once __DIR__ . '/stubs/class-newspack-tag-labels-stub.php';
require_once __DIR__ . '/stubs/class-newspack-block-visibility-stub.php';
require_once __DIR__ . '/stubs/class-newspack-sponsors-stub.php';
require_once __DIR__ . '/stubs/class-jetpack-memberships-stub.php';

// Start up the WP testing environment.
require $newspack_content_blocks_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/wp-unittestcase-blocks.php';
