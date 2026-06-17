<?php
/**
 * Plugin Name: BotCreds Agent Access
 * Plugin URI:  https://botcreds.com/
 * Description: Scoped, per-agent application passwords for AI agents, MCP clients, and automation tools.
 * Version:     2.2.6
 * Author:      Joe Boydston
 * Author URI:  https://botcreds.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: botcreds-agent-access
 * Requires at least: 5.7
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENT_ACCESS_VERSION', '2.2.6' );
define( 'AGENT_ACCESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENT_ACCESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENT_ACCESS_APP_PASSWORD_NAME', 'BotCreds' );

require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-role.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-api.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-admin.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-tracker.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-mentions.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-activity-log.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-pro-auth.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-scope.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-rate-limiter.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-approval-queue.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-rollback.php';
require_once AGENT_ACCESS_PLUGIN_DIR . 'includes/class-agent-access-content-policy.php';

/**
 * Initialize the plugin.
 */
function agent_access_init() {
	$api          = new Agent_Access_API();
	$admin        = new Agent_Access_Admin( $api );
	$tracker      = new Agent_Access_Tracker();
	$mentions     = new Agent_Access_Mentions();
	$activity_log = new Agent_Access_Activity_Log();
	$admin->init();
	$tracker->init();
	$mentions->init();
	$activity_log->init();

	Agent_Access_Scope::init();
	Agent_Access_Rate_Limiter::init();
	Agent_Access_Approval_Queue::init();
	Agent_Access_Rollback::init();
	Agent_Access_Content_Policy::init();

	if ( Agent_Access_Pro_Auth::is_enabled() ) {
		Agent_Access_Pro_Auth::init();
	}
}
add_action( 'plugins_loaded', 'agent_access_init' );

/**
 * Provide rich plugin details for the "View details" modal in wp-admin/plugins.php.
 *
 * Intercepts the WordPress.org API request for this plugin's slug and returns
 * local data so the modal is populated even without a WP.org API call.
 *
 * @param false|object|WP_Error $res    The result object or WP_Error.
 * @param string                $action The type of information being requested.
 * @param object                $args   Plugin API arguments.
 * @return false|object
 */
function agent_access_plugins_api( $res, $action, $args ) {
	if ( 'plugin_information' !== $action ) {
		return $res;
	}
	if ( empty( $args->slug ) || 'botcreds-agent-access' !== $args->slug ) {
		return $res;
	}

	$res = new stdClass();

	$res->name             = 'BotCreds Agent Access';
	$res->slug             = 'botcreds-agent-access';
	$res->version          = AGENT_ACCESS_VERSION;
	$res->author           = '<a href="https://botcreds.com">Joe Boydston</a>';
	$res->author_profile   = 'https://profiles.wordpress.org/jboydston/';
	$res->requires         = '5.6';
	$res->tested           = '6.8';
	$res->requires_php     = '7.4';
	$res->last_updated     = '2026-05-28';
	$res->homepage         = 'https://botcreds.com/';
	$res->download_link    = 'https://downloads.wordpress.org/plugin/botcreds-agent-access.latest-stable.zip';
	$res->short_description = 'Scoped, per-agent application passwords for AI agents, MCP clients, and automation tools.';

	$res->sections = array(
		'description' =>
			'<p><strong>BotCreds Agent Access</strong> gives your AI agent, MCP client, or automation tool a secure, scoped credential to interact with your site — no code required.</p>' .
			'<p>Whether you\'re connecting Claude, ChatGPT, a custom MCP server, or an OpenClaw agent, BotCreds gives you a one-click setup wizard that generates a properly scoped WordPress Application Password and logs every action the agent takes.</p>' .
			'<h3>Why BotCreds?</h3>' .
			'<p>Most AI agent setups require digging into wp-config, creating users manually, or sharing admin credentials. BotCreds removes all of that. Install, click, copy, paste — your agent is connected in under a minute.</p>' .
			'<h3>Features</h3>' .
			'<ul>' .
				'<li>One-click connection setup under <strong>Settings → BotCreds</strong></li>' .
				'<li>Generates a secure, scoped Application Password for your AI agent or MCP client</li>' .
				'<li>Works with any agent that supports the WordPress REST API: Claude, ChatGPT, OpenClaw, n8n, Zapier, custom MCP servers, and more</li>' .
				'<li>User-level and site-level logging of agent actions — see exactly what your agent did and when</li>' .
				'<li>Displays credentials in ready-to-paste format (table and JSON)</li>' .
				'<li>Shows connection status, creation date, and last used date</li>' .
				'<li>One-click revoke with confirmation</li>' .
				'<li>Clean, modern admin UI using native WordPress styles</li>' .
				'<li>Proper security: nonces, capability checks, password shown only once</li>' .
			'</ul>' .
			'<h3>Compatibility</h3>' .
			'<ul>' .
				'<li>Works with the Model Context Protocol (MCP)</li>' .
				'<li>Compatible with all major AI agent frameworks</li>' .
				'<li>No third-party services or accounts required — everything stays on your site</li>' .
			'</ul>',

		'installation' =>
			'<ol>' .
				'<li>Upload the <code>botcreds-agent-access</code> folder to <code>/wp-content/plugins/</code>.</li>' .
				'<li>Activate the plugin through the <strong>Plugins</strong> menu in WordPress.</li>' .
				'<li>Go to <strong>Settings → BotCreds</strong>.</li>' .
				'<li>Click <strong>Connect Agent</strong> to generate your credentials.</li>' .
				'<li>Copy the credentials and paste them into your agent\'s config.</li>' .
			'</ol>',

		'faq' =>
			'<h3>What is an Application Password?</h3>' .
			'<p>Application Passwords are a built-in WordPress feature (since 5.6) that let external tools authenticate against the REST API without using your main account password. They can be revoked at any time without affecting your login.</p>' .

			'<h3>What agents and tools does this work with?</h3>' .
			'<p>Any tool that supports HTTP Basic Auth against the WordPress REST API. This includes Claude (via MCP), ChatGPT plugins, OpenClaw, n8n, Zapier, Make, custom Python scripts, and more.</p>' .

			'<h3>What is MCP?</h3>' .
			'<p>The Model Context Protocol (MCP) is an open standard for connecting AI agents to external tools and data sources. BotCreds makes it easy to connect an MCP client to your site.</p>' .

			'<h3>Is the password stored anywhere?</h3>' .
			'<p>The plain-text password is shown once when created. WordPress stores only a hash, so the password cannot be retrieved later. If you lose it, revoke the old one and create a new connection.</p>' .

			'<h3>Can I have multiple agent connections?</h3>' .
			'<p>BotCreds manages one Application Password per user. Revoke the existing one before creating a new connection, or create additional passwords directly in your WordPress profile.</p>',

		'changelog' =>
			'<h4>2.2.2</h4>' .
		'<ul><li>Fix: Credential settings (Scope, Rate limit, Content policy) now render as a proper form table on profile pages — one setting per row, no cramped inline layout.</li></ul>' .

		'<h4>2.2.1</h4>' .
			'<ul><li>New: Users → Add Agent page in wp-admin. Create a dedicated agent user account in one step — Agent role pre-selected, no role picker clutter. After creation, you land directly on the new agent\'s profile page to connect their BotCreds credential.</li></ul>' .

			'<h4>2.1.20</h4>' .
			'<ul><li>New: Built-in <strong>Agent</strong> WordPress role. Create dedicated AI agent user accounts and assign them the Agent role — they appear with a clear Agent badge in the Connections dashboard. Default capabilities: publish posts/pages, upload media, manage categories. No access to site settings or user management. Capabilities can be further tuned with any role-management plugin.</li></ul>' .

			'<h4>2.1.18</h4>' .
			'<ul><li>Connections tab: role badges now show a tooltip on hover with a plain-English description of what that role can do. Custom roles derive their tooltip from actual capabilities.</li></ul>' .

			'<h4>2.1.17</h4>' .
			'<ul><li>Rebrand UI labels from "BotCreds" to "Agent Access" in Tools menu, settings page title, profile sections, and admin JS. Underlying credential name unchanged for backwards compatibility.</li></ul>' .

			'<h4>2.1.16</h4>' .
			'<ul><li>Activity log now records write methods only (POST, PUT, PATCH, DELETE) by default. Read requests (GET/HEAD) are skipped. Add <code>add_filter( \'agent_access_log_reads\', \'__return_true\' )</code> to re-enable read logging.</li></ul>' .

			'<h4>2.1.9</h4>' .
			'<ul>' .
				'<li>Security: Uninstall now correctly revokes all credentials minted as "BotCreds" (and all legacy names from prior rebrands).</li>' .
				'<li>Security: Activity log IP recorded from REMOTE_ADDR only — forwarded headers are no longer trusted.</li>' .
				'<li>Security: Removed unverified Jetpack signature header as a source-detection signal.</li>' .
				'<li>Fix: Admin page assets now load correctly on the Tools page.</li>' .
				'<li>Fix: Uninstall now drops the activity log table and version option.</li>' .
				'<li>Fix: Windowed pagination for the activity log using core <code>paginate_links()</code>.</li>' .
				'<li>Fix: @mention notifications no longer fire for pending/unapproved comments.</li>' .
				'<li>Fix: @mention regex now ignores email domains.</li>' .
			'</ul>' .

			'<h4>2.1.0</h4>' .
			'<ul>' .
				'<li>Added activity log for Agent Access app passwords and WP.com MCP connections.</li>' .
				'<li>Activity Log tab in Tools → Agent Access — filterable by source and HTTP method, paginated.</li>' .
				'<li>Removed ClawPress references — rebranded to BotCreds Agent Access.</li>' .
			'</ul>' .

			'<h4>2.0.0</h4>' .
			'<ul>' .
				'<li>Rebranded and relaunched as BotCreds Agent Access.</li>' .
				'<li>Removed provisioner and theme-bridge (available as separate add-ons).</li>' .
				'<li>Security hardening (escaping, nonces, input validation).</li>' .
				'<li>Added object caching for content stats.</li>' .
			 '</ul>' .

			'<h4>1.1.0</h4>' .
			'<ul>' .
				'<li>New: @mentions in comments — type @username to mention any site user.</li>' .
				'<li>New: Mentioned users receive email notifications automatically.</li>' .
				'<li>New: Mentions render as styled links in comment display.</li>' .
			 '</ul>' .

			'<h4>1.0.0</h4>' .
			'<ul><li>Initial release. Create and revoke agent Application Passwords. Connection status display with creation and last-used dates.</li></ul>',
	);

	return $res;
}
add_filter( 'plugins_api', 'agent_access_plugins_api', 10, 3 );

/**
 * Create/upgrade the activity log table and register the Agent role on activation.
 */
function agent_access_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-agent-access-activity-log.php';
	Agent_Access_Activity_Log::install_table();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-agent-access-role.php';
	Agent_Access_Role::register();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-agent-access-pro-auth.php';
	Agent_Access_Pro_Auth::install_table();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-agent-access-rollback.php';
	Agent_Access_Rollback::install_table();
}
register_activation_hook( __FILE__, 'agent_access_activate' );

// Deactivation intentionally preserves all data and the Agent role so that
// upgrades and reactivations restore cleanly. Role removal only happens on
// full uninstall (uninstall.php).
register_deactivation_hook( __FILE__, '__return_null' );

/**
 * Defensive role re-registration on every load.
 *
 * Handles edge cases where the role was dropped without going through the
 * deactivation hook (e.g. manual DB reset, site migration). add_role() is a
 * no-op when the role already exists, so this costs effectively nothing.
 */
add_action( 'plugins_loaded', function () {
	Agent_Access_Role::register();
} );
