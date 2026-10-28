<?php
/**
 * PHPUnit bootstrap for the bundled copy of newspack-blocks.
 *
 * Adapted from newspack-blocks' own test bootstrap. These tests were written to
 * run without newspack-plugin or WooCommerce, against stubs of the classes they
 * touch, so they get their own suite (phpunit.bundled-blocks.xml.dist): loading
 * the rest of this plugin would put its real classes and its WooCommerce mocks
 * in the stubs' place. Only the bundled copy is loaded, through the same
 * Newspack\Bundled_Blocks guard the plugin uses.
 *
 * @package Newspack
 */

$newspack_bundled_blocks_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $newspack_bundled_blocks_tests_dir ) {
	$newspack_bundled_blocks_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $newspack_bundled_blocks_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $newspack_bundled_blocks_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $newspack_bundled_blocks_tests_dir . '/includes/functions.php';

/**
 * Load the bundled copy of newspack-blocks, without the rest of the plugin.
 */
function newspack_bundled_blocks_manually_load() {
	$plugin_dir = dirname( __DIR__, 2 ) . '/';
	define( 'NEWSPACK_ABSPATH', $plugin_dir ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- The plugin's own constant.
	define( 'NEWSPACK_PLUGIN_VERSION', '0.0.0-test' ); // phpcs:ignore phpcsSniffs.Constants.ConstantDocblock.Missing -- The plugin's own constant.
	require $plugin_dir . 'includes/class-bundled-blocks.php';
}
tests_add_filter( 'muplugins_loaded', 'newspack_bundled_blocks_manually_load' );

// Print errors to stdout.
ini_set( 'error_log', 'php://stdout' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

// Only the PHPUnit polyfills, not this plugin's Composer autoloader: its classmap
// would autoload the real classes the stubs below stand in for.
$newspack_bundled_blocks_polyfills = [
	dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php', // Plugin vendor.
	dirname( __DIR__, 4 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php', // Monorepo root vendor.
];
$newspack_bundled_blocks_polyfills_loaded = false;
foreach ( $newspack_bundled_blocks_polyfills as $newspack_bundled_blocks_polyfill ) {
	if ( file_exists( $newspack_bundled_blocks_polyfill ) ) {
		require_once $newspack_bundled_blocks_polyfill;
		$newspack_bundled_blocks_polyfills_loaded = true;
		break;
	}
}
if ( ! $newspack_bundled_blocks_polyfills_loaded ) {
	fwrite( STDERR, "PHPUnit polyfills not found. Run `composer install` in plugins/newspack-plugin or at the monorepo root.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

/**
 * Load test stubs for dependencies not available in the isolated test environment.
 *
 * Each class stub guards on class_exists(). Nothing here autoloads the real
 * classes, so the stubs always win.
 */
require_once __DIR__ . '/class-wc-order-stub.php';

// Start up the WP testing environment.
require $newspack_bundled_blocks_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/wp-unittestcase-blocks.php';
