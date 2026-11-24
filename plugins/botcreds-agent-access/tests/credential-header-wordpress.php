<?php
/** Run on disposable QA via wp eval-file. Header attribution must not authorize. */
if ( ! defined( 'REST_REQUEST' ) ) { define( 'REST_REQUEST', true ); }
$GLOBALS['aa_header_checks'] = 0;
function aa_header_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['aa_header_checks']; }
$server_before = $_SERVER;
$uid = wp_insert_user( array( 'user_login' => 'aa_header_regression', 'user_pass' => wp_generate_password( 40 ), 'role' => 'editor' ) );
try {
 wp_set_current_user( $uid ); $credential = ( new Agent_Access_API() )->create_password( $uid );
 if ( is_wp_error( $credential ) ) { throw new RuntimeException( 'Credential fixture failed' ); }
 $uuid = $credential['uuid']; $GLOBALS['wp_rest_application_password_uuid'] = $uuid;
 Agent_Access_Scope::save( $uid, $uuid, array( Agent_Access_Yoast_SEO::SCOPE ) );
 Agent_Access_Rate_Limiter::save( $uid, $uuid, 'strict' );
 Agent_Access_Content_Policy::save( $uid, $uuid, 'standard' );
 foreach ( array( 'AgentAccessScopeCheck/1.0', 'wordpress.com-mcp', 'wpcom-mcp', 'AUTOMATTIC-MCP', 'wp-mcp', 'Jetpack by WordPress.com' ) as $ua ) {
  $_SERVER['HTTP_USER_AGENT'] = $ua; $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts/1?_for=jetpack'; $_SERVER['HTTP_X_JETPACK_SIGNATURE'] = 'synthetic-unverified';
  aa_header_check( $uuid === Agent_Access_Activity_Log::get_authenticated_botcreds_uuid(), 'verified identity survives ' . $ua );
  aa_header_check( Agent_Access_Activity_Log::SOURCE_AA === Agent_Access_Activity_Log::detect_source()['source'], 'audit attribution preserves credential ' . $ua );
  aa_header_check( array( Agent_Access_Yoast_SEO::SCOPE ) === Agent_Access_Scope::get_slugs_for_current_request(), 'scope preserved ' . $ua );
  aa_header_check( 'strict' === Agent_Access_Rate_Limiter::get_tier_for_current_request(), 'rate limit preserved ' . $ua );
  $request = new WP_REST_Request( 'POST', '/wp/v2/posts/1' );
  aa_header_check( is_wp_error( Agent_Access_Scope::enforce( null, rest_get_server(), $request ) ), 'core writes denied ' . $ua );
  $request->set_param( 'status', 'publish' ); $prepared = (object) array( 'ID' => 1, 'post_status' => 'publish' );
  aa_header_check( 'draft' === Agent_Access_Content_Policy::apply( $prepared, $request )->post_status, 'content policy preserved ' . $ua );
 }
 unset( $GLOBALS['wp_rest_application_password_uuid'] );
 aa_header_check( null === Agent_Access_Scope::get_slugs_for_current_request(), 'ordinary human session not restricted by attribution headers' );
 $other = WP_Application_Passwords::create_new_application_password( $uid, array( 'name' => 'Unrelated QA client' ) );
 $GLOBALS['wp_rest_application_password_uuid'] = $other[1]['uuid'];
 aa_header_check( null === Agent_Access_Activity_Log::get_authenticated_botcreds_uuid(), 'unrelated verified credential cannot claim BotCreds' );
 aa_header_check( null === Agent_Access_Scope::get_slugs_for_current_request(), 'unrelated credential unchanged' );
 $GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();
 aa_header_check( null === Agent_Access_Activity_Log::get_authenticated_botcreds_uuid(), 'unknown UUID cannot claim BotCreds' );
 wp_set_current_user( 0 ); aa_header_check( null === Agent_Access_Activity_Log::get_authenticated_botcreds_uuid(), 'anonymous identity cannot claim BotCreds' );
 echo 'PASS ' . $GLOBALS['aa_header_checks'] . " hosted credential/header assertions\n";
} finally {
 unset( $GLOBALS['wp_rest_application_password_uuid'] ); $_SERVER = $server_before;
 WP_Application_Passwords::delete_all_application_passwords( $uid ); WP_Session_Tokens::get_instance( $uid )->destroy_all();
 wp_set_current_user( 0 ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $uid );
}
