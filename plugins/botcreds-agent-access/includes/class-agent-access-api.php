<?php
/**
 * Agent Access Application Password management.
 *
 * @package Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_API {

	/**
	 * Create an Agent Access Application Password for the given user (defaults to current user).
	 *
	 * @param int|null $user_id Optional. User ID. Defaults to current user.
	 * @return array{password: string, uuid: string, created: int}|WP_Error
	 */
	public function create_password( $user_id = null ) {
		$user_id  = $user_id ? (int) $user_id : get_current_user_id();
		$existing = $this->get_existing_password( $user_id );

		if ( $existing ) {
			return new WP_Error(
				'agent_access_exists',
				__( 'An agent Application Password already exists. Revoke it first before creating a new one.', 'botcreds-agent-access' )
			);
		}

		$result = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => AGENT_ACCESS_APP_PASSWORD_NAME,
				'app_id' => 'botcreds-agent-access',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		list( $password, $item ) = $result;

		return array(
			'password' => WP_Application_Passwords::chunk_password( $password ),
			'uuid'     => $item['uuid'],
			'created'  => $item['created'],
		);
	}

	/**
	 * Revoke the Agent Access Application Password for the given user (defaults to current user).
	 *
	 * @param int|null $user_id Optional. User ID. Defaults to current user.
	 * @return true|WP_Error
	 */
	public function revoke_password( $user_id = null ) {
		$user_id  = $user_id ? (int) $user_id : get_current_user_id();
		$existing = $this->get_existing_password( $user_id );

		if ( ! $existing ) {
			return new WP_Error(
				'agent_access_not_found',
				__( 'No agent Application Password found to revoke.', 'botcreds-agent-access' )
			);
		}

		$deleted = WP_Application_Passwords::delete_application_password( (int) $user_id, $existing['uuid'] );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return true;
	}

	/**
	 * Get the existing Agent Access Application Password entry (without the password itself).
	 *
	 * @param int|null $user_id Optional. User ID. Defaults to current user.
	 * @return array|null The application password item or null if not found.
	 */
	public function get_existing_password( $user_id = null ) {
		$user_id   = $user_id ? (int) $user_id : get_current_user_id();
		$passwords = WP_Application_Passwords::get_user_application_passwords( $user_id );

		foreach ( $passwords as $item ) {
			if ( $item['name'] === AGENT_ACCESS_APP_PASSWORD_NAME ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Build the connection info array for display.
	 *
	 * @param string   $password The plain-text application password.
	 * @param int|null $user_id  Optional. User ID. Defaults to current user.
	 * @return array{site_url: string, username: string, password: string}
	 */
	public function get_connection_info( $password, $user_id = null ) {
		$user = $user_id ? get_userdata( (int) $user_id ) : wp_get_current_user();

		$info = array(
			'site_url'            => home_url( '/' ),
			'username'            => $user->user_login,
			'password'            => $password,
			'connection_info_url' => rest_url( 'agent-access/v1/connection' ),
			'current_user_url'    => rest_url( 'wp/v2/users/me' ),
		);
		$existing = $this->get_existing_password( $user->ID );
		if ( $existing && in_array( Agent_Access_Authors::SCOPE, Agent_Access_Scope::get( $user->ID, $existing['uuid'] ), true ) ) {
			$info['author_assignment'] = 'Co-Authors Plus: GET /wp-json/agent-access/v1/authors/{post_id}/available?search=NAME for current author IDs; POST {"authors":[ID,...]} to /wp-json/agent-access/v1/authors/{post_id}. Ordered byline only, on already-editable content within scope. Assign last: handing off ownership may remove editing access. Do not use core author or CAP native write endpoints.';
		}
		if ( $existing && in_array( Agent_Access_User_Directory::SCOPE, Agent_Access_Scope::get( $user->ID, $existing['uuid'] ), true ) ) {
			$info['user_directory'] = 'GET /wp-json/wp/v2/users?context=view&per_page=100&page=1 to list/search users. X-WP-Total is the matching user count; X-WP-TotalPages is the page count. This permission adds no user write access.';
		}
		return $info;
	}
}
