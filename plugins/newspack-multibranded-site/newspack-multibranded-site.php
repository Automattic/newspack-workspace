<?php
/**
 * Plugin Name: Newspack Multibranded Site
 * Description: Brand different content and sections of your site with unique colors and navigation.
 * Version: 2.2.0
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: newspack-multibranded-site
 * Domain Path: /languages/
 *
 * @package newspack-multibranded-site
 */

defined( 'ABSPATH' ) || exit;

// Define NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR.
if ( ! defined( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR' ) ) {
	define( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Define NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE.
if ( ! defined( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE', __FILE__ );
}

require_once __DIR__ . '/vendor/autoload.php';

Newspack_Multibranded_Site\Initializer::init();

// Load language files.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'newspack-multibranded-site', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'Newspack_Manager\\Updater' ) ) {
			new Newspack_Manager\Updater(
				'newspack-multibranded-site/newspack-multibranded-site.php',
				NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE,
				'Automattic/newspack-multibranded-site'
			);
		}
	}
);
