<?php
/**
 * Plugin Name: ClawPress
 * Plugin URI:  https://openclaw.com/clawpress
 * Description: One-click wizard to connect OpenClaw to your WordPress site via Application Passwords.
 * Version:     0.2.1
 * Author:      OpenClaw
 * Author URI:  https://openclaw.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clawpress
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CLAWPRESS_VERSION', '0.2.1' );
define( 'CLAWPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLAWPRESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CLAWPRESS_APP_PASSWORD_NAME', 'OpenClaw' );

require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-api.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-admin.php';

/**
 * Initialize the plugin.
 */
function clawpress_init() {
	$api   = new ClawPress_API();
	$admin = new ClawPress_Admin( $api );
	$admin->init();
}
add_action( 'plugins_loaded', 'clawpress_init' );
