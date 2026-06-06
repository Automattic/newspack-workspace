<?php
/**
 * Agent Access role — registers and manages the built-in "Agent" WordPress role.
 *
 * The Agent role gives dedicated AI agent user accounts a clearly-labeled
 * identity in the Agent Access Connections dashboard. Capabilities are set at a
 * reasonable content-creation baseline; administrators can further tune them
 * with any role-management plugin.
 *
 * Lifecycle:
 *  - Role is added on plugin activation (stored in wp_options → wp_user_roles).
 *  - Role is removed on plugin deactivation.
 *  - On uninstall, WordPress drops it along with the rest of the options cleanup.
 *
 * @package BotCreds Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Role {

	/** @var string WordPress role slug. */
	const ROLE_SLUG = 'agent';

	/** @var string Human-readable role display name. */
	const ROLE_NAME = 'Agent';

	/**
	 * Default capabilities for the Agent role.
	 *
	 * Baseline: content creation and management across posts, pages, and media.
	 * No access to site settings, plugins, theme, or user management.
	 *
	 * Admins can widen or narrow these at any time using a role-management plugin
	 * (e.g. Members, User Role Editor). Changes persist across plugin updates
	 * because WordPress stores role capabilities in wp_options, not in the plugin.
	 *
	 * @var array<string,true>
	 */
	const DEFAULT_CAPS = array(
		'read'                     => true,
		'edit_posts'               => true,
		'edit_published_posts'     => true,
		'publish_posts'            => true,
		'delete_posts'             => true,
		'delete_published_posts'   => true,
		'edit_pages'               => true,
		'edit_published_pages'     => true,
		'publish_pages'            => true,
		'delete_pages'             => true,
		'upload_files'             => true,
		'manage_categories'        => true,
		'moderate_comments'        => true,
	);

	/**
	 * Register the Agent role if it does not already exist.
	 *
	 * Safe to call on activation and as a defensive check on plugins_loaded —
	 * add_role() is a no-op when the role is already present.
	 *
	 * @return void
	 */
	public static function register() {
		if ( get_role( self::ROLE_SLUG ) ) {
			return;
		}
		add_role( self::ROLE_SLUG, self::ROLE_NAME, self::DEFAULT_CAPS );
	}

	/**
	 * Remove the Agent role on plugin deactivation.
	 *
	 * Users who had the Agent role will have no role after this. WordPress
	 * handles that gracefully (they become roleless users). Admins should
	 * reassign or delete agent user accounts before deactivating if needed.
	 *
	 * @return void
	 */
	public static function remove() {
		remove_role( self::ROLE_SLUG );
	}
}
