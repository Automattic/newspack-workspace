<?php
/**
 * Boots WordPress without the plugin's main file, then loads the plugin's
 * classes through the Composer classmap alone.
 *
 * Another plugin's shared jetpack-autoloader can register this plugin's
 * classmap while the plugin is inactive, or mid-activation before its main
 * file runs. Run by Newsletters_Autoload_Without_Main_File_Test in a separate
 * PHP process, since PHPUnit's own bootstrap always loads the main file.
 *
 * @package Newspack_Newsletters
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// The plain Composer autoloader carries the plugin classmap and PHPUnit, which the WP test bootstrap needs.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';
require $_tests_dir . '/includes/bootstrap.php';

// Probe a class the way other plugins do, triggering the classmap autoload.
class_exists( 'Newspack_Newsletters' );
do_action( 'init' );

echo 'init-hooked:' . ( has_action( 'init', [ 'Newspack_Newsletters', 'load_textdomain' ] ) ? 'yes' : 'no' ) . PHP_EOL;
