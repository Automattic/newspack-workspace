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
	 * Create an Agent Access Application Password for the current user.
	 *
	 * @return array{password: string, uuid: string, created: int}|WP_Error
	 */
	public function create_password() {
		$user_id  = get_current_user_id();
		$existing = $this->get_existing_password();

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
	 * Revoke the Agent Access Application Password for the current user.
	 *
	 * @return true|WP_Error
	 */
	public function revoke_password() {
		$user_id  = get_current_user_id();
		$existing = $this->get_existing_password();

		if ( ! $existing ) {
			return new WP_Error(
				'agent_access_not_found',
				__( 'No agent Application Password found to revoke.', 'botcreds-agent-access' )
			);
		}

		$deleted = WP_Application_Passwords::delete_application_password( $user_id, $existing['uuid'] );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return true;
	}

	/**
	 * Get the existing Agent Access Application Password entry (without the password itself).
	 *
	 * @return array|null The application password item or null if not found.
	 */
	public function get_existing_password() {
		$user_id   = get_current_user_id();
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
	 * @param string $password The plain-text application password.
	 * @return array{site_url: string, username: string, password: string}
	 */
	public function get_connection_info( $password ) {
		$user = wp_get_current_user();

		return array(
			'site_url' => home_url( '/' ),
			'username' => $user->user_login,
			'password' => $password,
		);
	}
}
