<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Newspack_Newsletters
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/newspack-newsletters.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Load the composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Trait used to test Subscription Lists.
require_once 'trait-lists-setup.php';

// Trait used to test Send Lists.
require_once 'trait-send-lists-setup.php';

// Trait used to test WC Memberships.
require_once 'trait-wc-memberships-setup.php';

// MailChimp mock.
require_once 'mocks/class-mailchimp-mock.php';

// WC Memberships mock.
require_once 'mocks/wc-memberships.php';

// WC CLI mock.
require_once 'mocks/wp-cli.php';

// Stubs for RDB methods.
if ( ! class_exists( 'BlockBindings' ) ) {
	require_once __DIR__ . '/mocks/class-blockbindings.php';
}

// Abstract ESP tests.
require_once 'abstract-esp-tests.php';

ini_set( 'error_log', 'php://stdout' ); // phpcs:ignore WordPress.PHP.IniSet.Risky


/**
 * Exception to be thrown when wp_die is called.
 *
 * @param string $message The error message.
 * @throws WPDieException The exception.
 */
function handle_wpdie_in_tests( $message ) {
	throw new WPDieException( $message ); // phpcs:ignore
}

define( 'IS_TEST_ENV', 1 );
