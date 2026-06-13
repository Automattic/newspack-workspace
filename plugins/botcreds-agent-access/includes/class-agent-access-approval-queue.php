<?php
/**
 * Approval Queue — hold new agent credentials until an admin explicitly approves them.
 *
 * When 'agent_access_require_approval' site option is enabled, freshly-created
 * credentials start in 'pending' status.  Write requests from pending credentials
 * are blocked at rest_pre_dispatch (priority 7) with a 403 and a clear message.
 * Read requests are allowed so the agent can discover its own pending state.
 *
 * Admin flow:
 *   1. Admin enables "Require approval" in the Connections tab settings.
 *   2. A user or admin creates a credential → status = 'pending'.
 *   3. Admin sees the credential in the Connections table with an ⏳ badge.
 *   4. Admin clicks Approve → status = 'approved'.
 *      Or clicks Reject  → credential is revoked and meta is cleaned up.
 *
 * Storage:  user meta '_agent_access_status_{uuid}' = 'pending' | 'approved'
 * Setting:  wp_option 'agent_access_require_approval' = '1' | '' (default off)
 *
 * @package BotCreds Agent Access
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Approval_Queue {

	const META_PREFIX      = '_agent_access_status_';
	const STATUS_PENDING   = 'pending';
	const STATUS_APPROVED  = 'approved';
	const OPTION_KEY       = 'agent_access_require_approval';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce' ), 7, 3 );
		add_action( 'wp_ajax_agent_access_approve', array( __CLASS__, 'handle_approve_ajax' ) );
		add_action( 'wp_ajax_agent_access_reject',  array( __CLASS__, 'handle_reject_ajax' ) );
	}

	// ──────────────────────────────────────────────
	// Enforcement
	// ──────────────────────────────────────────────

	/**
	 * Block write requests from pending credentials.
	 *
	 * @param mixed            $result
	 * @param WP_REST_Server   $server
	 * @param WP_REST_Request  $request
	 * @return mixed
	 */
	public static function enforce( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		// Only block writes.
		$method = strtoupper( $request->get_method() );
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}

		// Only govern Agent Access credentials.
		$source = Agent_Access_Activity_Log::detect_source();
		if ( null === $source || Agent_Access_Activity_Log::SOURCE_AA !== $source['source'] ) {
			return $result;
		}

		$uuid = function_exists( 'rest_get_authenticated_app_password' )
			? rest_get_authenticated_app_password()
			: null;

		if ( ! $uuid ) {
			return $result;
		}

		$user_id = get_current_user_id();
		$status  = self::get_status( $user_id, $uuid );

		if ( self::STATUS_PENDING === $status ) {
			return new WP_Error(
				'agent_credential_pending',
				__( 'This agent credential is pending admin approval. Write access is suspended until a site administrator approves the connection.', 'botcreds-agent-access' ),
				array( 'status' => 403 )
			);
		}

		return $result;
	}

	// ──────────────────────────────────────────────
	// Status storage
	// ──────────────────────────────────────────────

	/**
	 * Get the approval status for a credential.
	 *
	 * Credentials created before this feature existed (no meta) are treated
	 * as 'approved' so existing deployments are unaffected.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @return string 'pending' | 'approved'
	 */
	public static function get_status( $user_id, $uuid ) {
		$val = get_user_meta( (int) $user_id, self::META_PREFIX . $uuid, true );
		return ( self::STATUS_PENDING === $val ) ? self::STATUS_PENDING : self::STATUS_APPROVED;
	}

	/**
	 * Mark a credential as pending (called at creation time when required).
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 */
	public static function set_pending( $user_id, $uuid ) {
		update_user_meta( (int) $user_id, self::META_PREFIX . $uuid, self::STATUS_PENDING );
	}

	/**
	 * Approve a credential.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 */
	public static function approve( $user_id, $uuid ) {
		update_user_meta( (int) $user_id, self::META_PREFIX . $uuid, self::STATUS_APPROVED );
	}

	/**
	 * Remove status meta (called on credential revocation).
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 */
	public static function delete( $user_id, $uuid ) {
		delete_user_meta( (int) $user_id, self::META_PREFIX . $uuid );
	}

	// ──────────────────────────────────────────────
	// Site setting
	// ──────────────────────────────────────────────

	/**
	 * Whether new credentials require admin approval before write access.
	 *
	 * @return bool
	 */
	public static function is_required() {
		return (bool) get_option( self::OPTION_KEY, false );
	}

	/**
	 * Enable or disable the approval requirement.
	 *
	 * @param bool $enabled
	 */
	public static function set_required( $enabled ) {
		if ( $enabled ) {
			update_option( self::OPTION_KEY, '1', false );
		} else {
			delete_option( self::OPTION_KEY );
		}
	}

	// ──────────────────────────────────────────────
	// AJAX handlers
	// ──────────────────────────────────────────────

	/**
	 * Handle approve-credential AJAX request.
	 * Expects: user_id, uuid, nonce (agent_access_approve_{uuid}).
	 */
	public static function handle_approve_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		$uuid    = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

		check_ajax_referer( 'agent_access_approve_' . $uuid, 'nonce' );

		if ( ! $uuid || ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( __( 'Invalid credential.', 'botcreds-agent-access' ) );
		}

		self::approve( $user_id, $uuid );

		$target = get_userdata( $user_id );
		do_action( 'agent_access_audit', 'credential_approved', array(
			'username'    => $target->user_login,
			'approved_by' => wp_get_current_user()->user_login,
		) );

		wp_send_json_success( __( 'Credential approved. The agent now has write access.', 'botcreds-agent-access' ) );
	}

	/**
	 * Handle reject-credential AJAX request.
	 * Revokes the app password entirely.
	 * Expects: user_id, uuid, nonce (agent_access_reject_{uuid}).
	 */
	public static function handle_reject_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'botcreds-agent-access' ) );
		}

		$uuid    = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

		check_ajax_referer( 'agent_access_reject_' . $uuid, 'nonce' );

		if ( ! $uuid || ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( __( 'Invalid credential.', 'botcreds-agent-access' ) );
		}

		// Revoke the application password.
		WP_Application_Passwords::delete_application_password( $user_id, $uuid );

		// Clean up all meta.
		self::delete( $user_id, $uuid );
		Agent_Access_Scope::delete( $user_id, $uuid );
		Agent_Access_Rate_Limiter::delete( $user_id, $uuid );

		$target = get_userdata( $user_id );
		do_action( 'agent_access_audit', 'credential_rejected', array(
			'username'    => $target->user_login,
			'rejected_by' => wp_get_current_user()->user_login,
		) );

		wp_send_json_success( __( 'Credential rejected and revoked.', 'botcreds-agent-access' ) );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Return the display label for a status value.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function get_label( $status ) {
		return ( self::STATUS_PENDING === $status )
			? __( '⏳ Pending', 'botcreds-agent-access' )
			: __( '✓ Approved', 'botcreds-agent-access' );
	}
}
