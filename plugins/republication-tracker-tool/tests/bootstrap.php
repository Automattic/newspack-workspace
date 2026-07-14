<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Creative_Commons_Sharing
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Load the composer autoloader (provides the PHPUnit Polyfills required by the WP test suite).
// Required unconditionally so a missing vendor/ fails loudly instead of surfacing as a
// confusing "class not found" later in the run.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

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
