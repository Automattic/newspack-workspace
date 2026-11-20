<?php
/**
 * Read-only discovery for the currently authenticated managed credential.
 *
 * @package BotCreds Agent Access
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Connection {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'private_response' ), 10, 3 );
	}

	public static function register_routes() {
		register_rest_route( 'agent-access/v1', '/connection', array(
			'methods' => WP_REST_Server::READABLE,
			'permission_callback' => array( __CLASS__, 'permissions' ),
			'callback' => array( __CLASS__, 'get' ),
		) );
	}

	public static function permissions() {
		if ( ! get_current_user_id() ) {
			return new WP_Error( 'agent_connection_auth_required', __( 'Authenticate with your managed Agent Access application password.', 'botcreds-agent-access' ), array( 'status' => 401 ) );
		}
		if ( ! empty( $GLOBALS['_agent_access_pro_token_row'] ) || ! Agent_Access_Activity_Log::get_authenticated_botcreds_uuid() || ! current_user_can( 'read' ) ) {
			return new WP_Error( 'agent_connection_credential_required', __( 'This endpoint requires a managed BotCreds application password, not a browser session or another credential type.', 'botcreds-agent-access' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** Include errors as well as successful discovery responses in the cache rule. */
	public static function private_response( $response, $server, $request ) {
		if ( '/agent-access/v1/connection' === untrailingslashit( $request->get_route() ) ) {
			$response = rest_ensure_response( $response );
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Vary', 'Authorization, Cookie' );
		}
		return $response;
	}

	public static function get() {
		$user = wp_get_current_user();
		$uuid = Agent_Access_Activity_Log::get_authenticated_botcreds_uuid();
		$scope = Agent_Access_Scope::get( $user->ID, $uuid );
		$mode = in_array( '__read_only__', $scope, true ) ? 'read_only' : ( Agent_Access_Yoast_SEO::has_scope( $scope ) ? 'yoast_seo_only' : ( in_array( '*', $scope, true ) ? 'full' : 'selected' ) );
		$tier = Agent_Access_Rate_Limiter::get( $user->ID, $uuid );
		$limits = Agent_Access_Rate_Limiter::get_tier( $tier );
		$caps = array();
		foreach ( array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'upload_files', 'manage_options', 'edit_theme_options' ) as $cap ) {
			$caps[ $cap ] = current_user_can( $cap );
		}
		return rest_ensure_response( array(
			'schema_version' => 1,
			'plugin_version' => AGENT_ACCESS_VERSION,
			'site_url' => home_url( '/' ),
			'rest_url' => rest_url(),
			'identity' => array( 'id' => $user->ID, 'username' => $user->user_login ),
			'authentication' => 'managed_application_password',
			'scope' => array( 'configured' => $scope, 'mode' => $mode ),
			'account_capabilities' => $caps,
			'additional_permissions' => array(
				'list_users' => in_array( Agent_Access_User_Directory::SCOPE, $scope, true ),
				'assign_authors' => in_array( Agent_Access_Authors::SCOPE, $scope, true ),
				'site_management_account_wide' => Agent_Access_Site_Management::is_enabled( $user->ID ),
			),
			'dependencies' => array( 'yoast_seo' => Agent_Access_Yoast_SEO::available(), 'co_authors_plus' => Agent_Access_Authors::available() ),
			'approval_status' => Agent_Access_Approval_Queue::get_status( $user->ID, $uuid ),
			'content_policy' => Agent_Access_Content_Policy::get( $user->ID, $uuid ),
			'rate_limit' => array( 'tier' => $tier, 'per_minute' => $limits['per_minute'] ?? null, 'per_hour' => $limits['per_hour'] ?? null ),
			'endpoints' => array(
				'current_user' => rest_url( 'wp/v2/users/me' ),
				'user_directory' => rest_url( 'wp/v2/users' ),
				'yoast_seo_template' => rest_url( 'agent-access/v1/yoast-seo/{id}' ),
				'authors_template' => rest_url( 'agent-access/v1/authors/{id}' ),
			),
			'constraints' => array(
				'Configured grants are not a guarantee that an operation is permitted. Native account permissions, content ownership, dependencies, scope precedence, approval and content policies also apply.',
				'Read-only overrides write grants. SEO-only writes must use the dedicated Yoast route. Author assignment requires already-editable content within scope and Co-Authors Plus author IDs, not WordPress user IDs.',
				'List users applies only to native users GET/HEAD requests; use context=view and X-WP-Total for counts. It grants no user management.',
				'Rate limits are configured ceilings, not remaining quota. Standard authentication bookkeeping and legacy scope migration may occur on reads.',
			),
			'help_url' => 'https://wordpress.org/plugins/botcreds-agent-access/#faq',
		) );
	}
}
