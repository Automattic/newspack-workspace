<?php
/**
 * Plugin Name: Newspack Network
 * Description: The Newspack Network plugin.
 * Version: 2.20.4
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.1
 * Text Domain: newspack-network
 * Domain Path: /languages/
 *
 * @package newspack-network
 */

defined( 'ABSPATH' ) || exit;

// Path to the Newspack Network plugin directory.
if ( ! defined( 'NEWSPACK_NETWORK_PLUGIN_DIR' ) ) {
	define( 'NEWSPACK_NETWORK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Path to the main Newspack Network plugin file.
if ( ! defined( 'NEWSPACK_NETWORK_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_NETWORK_PLUGIN_FILE', __FILE__ );
}

/**
 * The role added by Newspack Network plugin for readers propagated from other sites.
 */
define( 'NEWSPACK_NETWORK_READER_ROLE', 'network_reader' );

// Load language files.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'newspack-network', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

require_once __DIR__ . '/vendor/autoload.php';

Newspack_Network\Initializer::init();
