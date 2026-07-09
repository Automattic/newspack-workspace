<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Newspack_Popups
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

	require dirname( __DIR__ ) . '/newspack-popups.php';
	require dirname( __DIR__ ) . '/src/blocks/custom-placement/view.php';
	require dirname( __DIR__ ) . '/includes/class-newspack-popups-exporter.php';
	require dirname( __DIR__ ) . '/includes/class-newspack-popups-importer.php';

	// The CTA intent classifier lives in newspack-plugin (NPPD-1887), which this
	// suite does not load. Pull in just that one dependency-free class when the
	// sibling checkout is present (always, in the monorepo and in CI) so the
	// block-less CTA tests exercise the real classifier.
	//
	// When it is absent — e.g. running this suite from a standalone newspack-popups
	// checkout — Data_Api degrades to "no inferred intent" by design, and DataApiTest
	// SKIPS entirely (see its require_classifier()). The degradation itself is
	// guaranteed by the class_exists guards in Data_Api, not by a test: it cannot be
	// asserted in the same process once the class is loaded.
	$classifier = dirname( __DIR__, 2 ) . '/newspack-plugin/includes/class-cta-intent-classifier.php';
	if ( file_exists( $classifier ) ) {
		require_once $classifier;
	}
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

define( 'IS_TEST_ENV', 1 );

// Load the composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
require dirname( __DIR__ ) . '/tests/wp-unittestcase-pagewithpopups.php';

ini_set( 'error_log', 'php://stdout' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
