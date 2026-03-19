<?php
/**
 * Plugin Name: Agent Access
 * Plugin URI:  https://agentaccess.io
 * Description: Connect AI agents to WordPress — manage Application Passwords, track agent content, and configure themes via REST API.
 * Version:     1.1.0
 * Author:      Agent Access
 * Author URI:  https://agentaccess.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: agent-access
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENT_ACCESS_VERSION', '1.1.0' );
define( 'AGENT_ACCESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENT_ACCESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENT_ACCESS_APP_PASSWORD_NAME', 'Agent Access' );

require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-api.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-admin.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-tracker.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-theme-bridge.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-mentions.php';

/**
 * Initialize the plugin.
 */
function agent_access_init() {
	$api          = new Agent_Access_API();
	$admin        = new Agent_Access_Admin( $api );
	$tracker      = new Agent_Access_Tracker();
	$theme_bridge = new Agent_Access_Theme_Bridge();
	$mentions     = new Agent_Access_Mentions();
	$admin->init();
	$tracker->init();
	$theme_bridge->init();
	$mentions->init();
}
add_action( 'plugins_loaded', 'agent_access_init' );
