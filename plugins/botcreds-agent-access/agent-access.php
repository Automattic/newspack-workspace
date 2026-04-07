<?php
/**
 * Plugin Name: Agent Access
 * Plugin URI:  https://wearebob.blog/agent-access/
 * Description: Connect AI agents to WordPress — manage Application Passwords, track agent content, and @mentions.
 * Version:     2.0.0
 * Author:      Joe Boydston
 * Author URI:  https://wearebob.blog
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: agent-access
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENT_ACCESS_VERSION', '2.0.0' );
define( 'AGENT_ACCESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENT_ACCESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENT_ACCESS_APP_PASSWORD_NAME', 'Agent Access' );

require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-api.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-admin.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-tracker.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-mentions.php';

if ( file_exists( AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-chat.php' ) ) {
	require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-chat.php';
}

/**
 * Initialize the plugin.
 */
function agent_access_init() {
	$api          = new Agent_Access_API();
	$admin        = new Agent_Access_Admin( $api );
	$tracker  = new Agent_Access_Tracker();
	$mentions = new Agent_Access_Mentions();
	$admin->init();
	$tracker->init();
	$mentions->init();

	if ( class_exists( 'Agent_Access_Chat' ) ) {
		Agent_Access_Chat::init();
	}
}
add_action( 'plugins_loaded', 'agent_access_init' );
