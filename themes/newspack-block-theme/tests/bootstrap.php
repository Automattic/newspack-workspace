<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Newspack_Block_Theme
 */

if ( ! file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
	echo 'Could not find the Composer autoloader, have you run "composer install" in the theme directory?' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once __DIR__ . '/../vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Activate the theme under test, so its functions.php and theme.json load.
 */
function _newspack_block_theme_activate() {
	register_theme_directory( dirname( __DIR__, 2 ) );
	search_theme_directories( true );
	switch_theme( basename( dirname( __DIR__ ) ) );
}
tests_add_filter( 'setup_theme', '_newspack_block_theme_activate' );

require $_tests_dir . '/includes/bootstrap.php';
