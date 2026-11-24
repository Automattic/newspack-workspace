<?php
/**
 * Credential-specific, read-only access to the native WordPress user directory.
 * No stored user/role capabilities; no changes to native user write permissions.
 *
 * @package BotCreds Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential-specific, read-only access to the native user directory.
 */
class Agent_Access_User_Directory {
	const SCOPE = '__list_users__';

	/**
	 * Current REST callback stack, including nested rest_do_request() calls.
	 *
	 * @var WP_REST_Request[]
	 */
	private static $requests = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'begin_request' ), 1, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'end_request' ), PHP_INT_MAX, 3 );
		add_filter( 'user_has_cap', array( __CLASS__, 'apply_grant' ), 10, 4 );
		add_filter( 'rest_prepare_user', array( __CLASS__, 'report_effective_cap' ), 10, 2 );
	}

	/**
	 * Whether the current administrator may assign this scope to the given user.
	 *
	 * @param int $user_id The user ID.
	 */
	public static function can_assign( $user_id ) {
		return Agent_Access_Yoast_SEO::can_assign( $user_id );
	}

	/**
	 * Push the request onto the REST callback stack.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param array            $handler The matched route handler.
	 * @param WP_REST_Request  $request The REST request.
	 */
	public static function begin_request( $response, $handler, $request ) {
		self::$requests[] = $request;
		return $response;
	}

	/**
	 * Pop the request from the REST callback stack.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param array            $handler The matched route handler.
	 * @param WP_REST_Request  $request The REST request.
	 */
	public static function end_request( $response, $handler, $request ) {
		array_pop( self::$requests );
		return $response;
	}

	/**
	 * Grant only list_users, only while executing the selected core read route.
	 *
	 * @param array   $allcaps All capabilities the user has.
	 * @param array   $caps The capabilities being checked.
	 * @param array   $args The capability check arguments.
	 * @param WP_User $user The user.
	 */
	public static function apply_grant( $allcaps, $caps, $args, $user ) {
		$request = end( self::$requests );
		if ( ! $request || ! in_array( 'list_users', $caps, true ) || (int) $user->ID !== get_current_user_id() || (int) $user->get_site_id() !== get_current_blog_id() || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) || ! preg_match( '#^/wp/v2/users(?:/[1-9][0-9]*|/me)?/?$#D', $request->get_route() ) ) {
			return $allcaps;
		}
		// Native verified UUID, not an audit header, browser cookie or Pro token.
		$uuid = Agent_Access_Activity_Log::get_authenticated_botcreds_uuid();
		if ( ! $uuid || ! user_can( $user, 'read' ) || ! in_array( self::SCOPE, Agent_Access_Scope::get( $user->ID, $uuid ), true ) ) {
			return $allcaps;
		}
		$allcaps['list_users'] = true;
		return $allcaps;
	}

	/**
	 * Keep authorized self capability discovery consistent with the read grant.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param WP_User          $user The user.
	 */
	public static function report_effective_cap( $response, $user ) {
		$data = $response->get_data();
		if ( (int) $user->ID === get_current_user_id() && isset( $data['capabilities'] ) && ( is_array( $data['capabilities'] ) || is_object( $data['capabilities'] ) ) && current_user_can( 'list_users' ) ) {
			$data['capabilities'] = (array) $data['capabilities'];
			$data['capabilities']['list_users'] = true;
			$response->set_data( $data );
		}
		return $response;
	}
}
