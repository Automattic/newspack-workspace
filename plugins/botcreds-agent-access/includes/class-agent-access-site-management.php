<?php
/**
 * Explicit, per-site, per-user site-management grants for dedicated agents.
 *
 * Grants are evaluated at runtime rather than copied into the Agent role or
 * the user's stored capabilities. Revocation/deactivation therefore preserves
 * pre-existing role and direct capabilities without leaving added caps behind.
 *
 * @package BotCreds Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Site_Management {
	const META_PREFIX = '_agent_access_site_management_';
	const CAPS = array( 'manage_options', 'edit_theme_options' );

	/** Register the runtime grant and role-change cleanup. */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'apply_grant' ), 10, 4 );
		add_action( 'set_user_role', array( __CLASS__, 'clear_if_not_agent' ) );
		add_action( 'remove_user_role', array( __CLASS__, 'clear_if_not_agent' ) );
	}

	/** @return string Site-specific metadata key; usermeta is shared on multisite. */
	public static function meta_key() {
		return self::META_PREFIX . get_current_blog_id();
	}

	/**
	 * Whether an explicit plugin-managed grant is enabled (not inherited caps).
	 *
	 * @param int $user_id Target user.
	 * @return bool
	 */
	public static function is_enabled( $user_id ) {
		return '1' === get_user_meta( $user_id, self::meta_key(), true );
	}

	/**
	 * Only human-admin cookie sessions with user-management authority may toggle.
	 * Never authorize from manage_options alone: agents may be granted that cap.
	 *
	 * @param int $user_id Dedicated agent user.
	 * @return bool
	 */
	public static function can_manage( $user_id ) {
		$target = get_userdata( $user_id );
		if ( ! $target || ! in_array( Agent_Access_Role::ROLE_SLUG, $target->roles, true ) || get_current_user_id() === (int) $user_id ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}
		if ( null !== Agent_Access_Scope::get_slugs_for_current_request() || ( function_exists( 'rest_get_authenticated_app_password' ) && rest_get_authenticated_app_password() ) ) {
			return false;
		}
		return (int) wp_validate_auth_cookie( '', 'logged_in' ) === get_current_user_id();
	}

	/**
	 * Validate an optional admin form value before ANY settings are mutated.
	 * Absence preserves the current grant for older clients and restricted actors.
	 *
	 * @param int $user_id Target agent.
	 * @return bool|null|WP_Error Enabled value, no change, or denied/invalid input.
	 */
	public static function requested_grant( $user_id ) {
		// Caller checks the AJAX nonce before invoking this method.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['site_management'] ) ) {
			return null;
		}
		$value = wp_unslash( $_POST['site_management'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict '0'/'1' allowlist below; never coerce malformed privilege grants.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! self::can_manage( $user_id ) ) {
			return new WP_Error( 'site_management_forbidden', __( 'Only a signed-in administrator with user-management permissions can change an agent\'s site-management access.', 'botcreds-agent-access' ) );
		}
		if ( ! is_string( $value ) || ! in_array( $value, array( '0', '1' ), true ) ) {
			return new WP_Error( 'invalid_site_management', __( 'Invalid site-management setting.', 'botcreds-agent-access' ) );
		}
		return '1' === $value;
	}

	/**
	 * Save an already-validated administrator decision. Do not add persistent caps.
	 *
	 * @param int       $user_id Target agent.
	 * @param bool|null $enabled Validated decision, or null to preserve the grant.
	 */
	public static function save( $user_id, $enabled ) {
		if ( null === $enabled ) {
			return;
		}
		if ( ! self::can_manage( $user_id ) ) {
			return;
		}
		if ( $enabled ) {
			update_user_meta( $user_id, self::meta_key(), '1' );
		} else {
			delete_user_meta( $user_id, self::meta_key() );
		}
	}

	/**
	 * Add only the two promised capabilities, only on this site for Agent users.
	 *
	 * @param array   $allcaps Existing capabilities.
	 * @param array   $caps Requested primitive capabilities.
	 * @param array   $args Capability-check arguments.
	 * @param WP_User $user Checked user.
	 * @return array
	 */
	public static function apply_grant( $allcaps, $caps, $args, $user ) {
		if ( (int) $user->get_site_id() !== get_current_blog_id() || ! in_array( Agent_Access_Role::ROLE_SLUG, $user->roles, true ) || ! self::is_enabled( $user->ID ) ) {
			return $allcaps;
		}
		foreach ( self::CAPS as $cap ) {
			$allcaps[ $cap ] = true;
		}
		return $allcaps;
	}

	/**
	 * Changing away from Agent must not leave a dormant grant to revive later.
	 *
	 * @param int $user_id Changed user.
	 */
	public static function clear_if_not_agent( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( Agent_Access_Role::ROLE_SLUG, $user->roles, true ) ) {
			delete_user_meta( $user_id, self::meta_key() );
		}
	}
}
