<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Creative_Commons_Sharing
 */

// Load the composer autoloader (provides the PHPUnit Polyfills required by the WP test suite).
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

// Loaded here, not per-test, since compatibility-co-authors-plus.php gates
// its own registration on function_exists( 'coauthors' ) at plugin-load time.
require_once __DIR__ . '/mocks/coauthors-plus-mock.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/republication-tracker-tool.php';

	// The CAP filter replaces whatever byline it's given, so registering it
	// suite-wide would hide the WP post author path from every test. Tests that
	// cover CAP add it back themselves.
	remove_filter( 'republication_tracker_tool_byline', 'republication_tracker_tool_byline_filter_cap', 10 );
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
