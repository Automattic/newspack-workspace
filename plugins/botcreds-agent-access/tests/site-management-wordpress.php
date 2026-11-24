<?php
/** Dedicated hosted-QA regression runner; not part of the distributable plugin. */
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
$GLOBALS['aa_sm_checks'] = 0;
$GLOBALS['aa_sm_users'] = array();
function sm_check( $ok, $label ) {
 if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
 ++$GLOBALS['aa_sm_checks'];
}
function sm_user( $role ) {
 $id = wp_insert_user( array( 'user_login' => 'aa_sm_qa_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => $role ) );
 if ( is_wp_error( $id ) ) { throw new RuntimeException( 'User fixture failed' ); }
 $GLOBALS['aa_sm_users'][] = $id; return $id;
}
function sm_actor( $id, $cookie = true ) {
 unset( $GLOBALS['_agent_access_pro_token_row'], $GLOBALS['wp_rest_application_password_uuid'] );
 unset( $_COOKIE[LOGGED_IN_COOKIE] );
 wp_set_current_user( 0 ); wp_set_current_user( $id );
 if ( $cookie ) { $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $id, time() + HOUR_IN_SECONDS, 'logged_in' ); }
}
function sm_ajax( $admin, $method, $input, $nonce_action = 'agent_access_admin_nonce', $valid_nonce = true ) {
 $_POST = wp_slash( $input );
 $_REQUEST = array( 'nonce' => $valid_nonce ? wp_create_nonce( $nonce_action ) : 'invalid' );
 $handler = function () { return function () { throw new RuntimeException( 'SM_JSON_SENT' ); }; };
 add_filter( 'wp_die_ajax_handler', $handler ); ob_start();
 try { $admin->$method(); }
 catch ( RuntimeException $e ) { if ( 'SM_JSON_SENT' !== $e->getMessage() ) { throw $e; } }
 finally { $json = ob_get_clean(); remove_filter( 'wp_die_ajax_handler', $handler ); }
 return json_decode( $json, true );
}
function sm_render( $admin, $id ) {
 $method = new ReflectionMethod( $admin, 'render_site_management_control' ); $method->setAccessible( true );
 ob_start(); $method->invoke( $admin, $id ); return ob_get_clean();
}
$role_before = get_role( 'agent' )->capabilities;
try {
 $owner = sm_user( 'administrator' ); $agent = sm_user( 'agent' ); $other = sm_user( 'agent' ); $editor = sm_user( 'editor' );
 $direct_before = get_user_meta( $agent, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true );
 sm_check( ! Agent_Access_Site_Management::is_enabled( $agent ), 'off by default' );
 sm_check( ! user_can( $agent, 'manage_options' ) && ! user_can( $agent, 'edit_theme_options' ), 'baseline agent has no new caps' );
 sm_actor( $owner );
 sm_check( Agent_Access_Site_Management::can_manage( $agent ), 'signed cookie admin may manage agent' );
 sm_check( ! Agent_Access_Site_Management::can_manage( $owner ), 'no self promotion control' );
 sm_check( ! Agent_Access_Site_Management::can_manage( $editor ), 'only dedicated Agent targets' );
 $api = new Agent_Access_API(); $admin = new Agent_Access_Admin( $api );
 $html = sm_render( $admin, $agent );
 sm_check( false !== strpos( $html, 'agent-access-site-management' ) && false === strpos( $html, "checked='checked'" ), 'admin UI off by default' );
 sm_check( '' === sm_render( $admin, $editor ), 'no control for human editor account' );
 sm_actor( $owner, false ); sm_check( ! Agent_Access_Site_Management::can_manage( $agent ), 'admin without cookie cannot grant' );
 sm_actor( $owner ); $GLOBALS['_agent_access_pro_token_row'] = (object) array( 'scope' => '["*"]' );
 sm_check( ! Agent_Access_Site_Management::can_manage( $agent ), 'Pro-auth admin cannot grant' );
 unset( $GLOBALS['_agent_access_pro_token_row'] );
 $GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();
 sm_check( ! Agent_Access_Site_Management::can_manage( $agent ), 'application-password admin cannot grant' );
 unset( $GLOBALS['wp_rest_application_password_uuid'] );
 $_POST = array(); sm_check( null === Agent_Access_Site_Management::requested_grant( $agent ), 'absent input means preserve, not revoke' );
 foreach ( array( 'yes', 'true', '', array( '1' ), '01' ) as $bad ) {
  $_POST = array( 'site_management' => $bad ); sm_check( is_wp_error( Agent_Access_Site_Management::requested_grant( $agent ) ), 'strict grant input validation' );
 }
 Agent_Access_Site_Management::save( $agent, true );
 sm_check( Agent_Access_Site_Management::is_enabled( $agent ), 'grant persisted' );
 foreach ( Agent_Access_Site_Management::CAPS as $cap ) { sm_check( user_can( $agent, $cap ), 'grant provides ' . $cap ); }
 foreach ( array( 'promote_users', 'edit_users', 'create_users', 'install_plugins', 'activate_plugins', 'install_themes' ) as $cap ) { sm_check( ! user_can( $agent, $cap ), 'does not grant ' . $cap ); }
 sm_check( $direct_before === get_user_meta( $agent, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', true ), 'does not persist direct capabilities' );
 sm_check( $role_before === get_role( 'agent' )->capabilities, 'shared Agent role untouched' );
 sm_check( ! user_can( $other, 'manage_options' ) && ! user_can( $other, 'edit_theme_options' ), 'unrelated Agent unchanged' );
 $response = Agent_Access_Site_Management::report_effective_caps( new WP_REST_Response( array( 'capabilities' => array( 'read' => true ) ) ), get_userdata( $agent ) );
 sm_check( true === $response->get_data()['capabilities']['manage_options'] && true === $response->get_data()['capabilities']['edit_theme_options'], 'authorized REST capability discovery reflects grant' );
 $response = Agent_Access_Site_Management::report_effective_caps( new WP_REST_Response( array( 'capabilities' => (object) array( 'read' => true ) ) ), get_userdata( $agent ) );
 sm_check( true === $response->get_data()['capabilities']['manage_options'] && true === $response->get_data()['capabilities']['edit_theme_options'], 'native REST object-shaped capability map reflects grant' );
 $response = Agent_Access_Site_Management::report_effective_caps( new WP_REST_Response( array( 'name' => 'Synthetic' ) ), get_userdata( $agent ) );
 sm_check( ! isset( $response->get_data()['capabilities'] ), 'public REST response does not gain a capabilities field' );
 sm_check( false !== strpos( sm_render( $admin, $agent ), "checked='checked'" ), 'enabled grant reflected in UI' );
 sm_actor( $agent );
 sm_check( current_user_can( 'manage_options' ) && current_user_can( 'edit_theme_options' ), 'grant available in agent session' );
 sm_check( ! Agent_Access_Site_Management::can_manage( $agent ) && ! Agent_Access_Site_Management::can_manage( $other ), 'site manager cannot promote self or others' );
 sm_check( '' === sm_render( $admin, $other ), 'site manager cannot see grant switch' );
 $_POST = array( 'site_management' => '1' ); sm_check( is_wp_error( Agent_Access_Site_Management::requested_grant( $other ) ), 'agent cannot forge a grant' );
 Agent_Access_Site_Management::save( $other, true ); sm_check( ! Agent_Access_Site_Management::is_enabled( $other ), 'save layer also rejects unauthorized actor' );
 sm_actor( $owner );
 $credential = $api->create_password( $agent ); sm_check( ! is_wp_error( $credential ), 'connected fixture setup' ); $uuid = $credential['uuid'];
 $scope = array( 'post', '/newspack-workflows/v1/*' ); Agent_Access_Scope::save( $agent, $uuid, $scope );
 $payload = array( 'user_id' => $agent, 'scope_types' => $scope, 'rate_limit' => 'standard', 'content_policy' => 'standard', 'site_management' => '0' );
 $r = sm_ajax( $admin, 'handle_admin_update_ajax', $payload );
 sm_check( true === $r['success'] && ! Agent_Access_Site_Management::is_enabled( $agent ), 'actual nonce protected callback revokes grant' );
 sm_check( ! user_can( $agent, 'manage_options' ) && ! user_can( $agent, 'edit_theme_options' ), 'revocation immediately removes added capabilities' );
 sm_check( $scope === Agent_Access_Scope::get( $agent, $uuid ), 'revocation does not drop endpoint scopes' );
 $payload['site_management'] = '1'; $r = sm_ajax( $admin, 'handle_admin_update_ajax', $payload );
 sm_check( true === $r['success'] && user_can( $agent, 'manage_options' ) && user_can( $agent, 'edit_theme_options' ), 'actual admin callback enables both capabilities' );
 unset( $payload['site_management'] ); $r = sm_ajax( $admin, 'handle_admin_update_ajax', $payload );
 sm_check( true === $r['success'] && Agent_Access_Site_Management::is_enabled( $agent ), 'older client cannot accidentally revoke grant' );
 $payload['site_management'] = '0'; $r = sm_ajax( $admin, 'handle_admin_update_ajax', $payload, 'agent_access_admin_nonce', false );
 sm_check( Agent_Access_Site_Management::is_enabled( $agent ), 'invalid nonce cannot revoke' );
 $payload['site_management'] = array( '1' ); $payload['scope_types'] = array( '*' );
 $r = sm_ajax( $admin, 'handle_admin_update_ajax', $payload );
 sm_check( false === $r['success'] && $scope === Agent_Access_Scope::get( $agent, $uuid ), 'invalid grant input cannot partially mutate scope' );
 sm_actor( $agent );
 $r = sm_ajax( $admin, 'handle_admin_update_ajax', array( 'user_id' => $agent, 'scope_types' => array( '*' ), 'site_management' => '1' ) );
 sm_check( false === $r['success'] && $scope === Agent_Access_Scope::get( $agent, $uuid ), 'agent self-escalation rejected before scope mutation' );
 $r = sm_ajax( $admin, 'handle_admin_create_ajax', array( 'user_id' => $owner ), 'agent_access_admin_create' );
 sm_check( false === $r['success'] && ! $api->get_existing_password( $owner ), 'site manager cannot mint administrator credentials' );
 $r = sm_ajax( $admin, 'handle_admin_revoke_ajax', array( 'user_id' => $owner ), 'agent_access_admin_revoke' );
 sm_check( false === $r['success'], 'site manager cannot revoke administrator credentials' );
 $r = sm_ajax( $admin, 'handle_generate_setup_ajax', array( 'user_id' => $owner ), 'agent_access_generate_setup' );
 sm_check( false === $r['success'], 'site manager cannot generate administrator Pro-auth setup' );
 $r = sm_ajax( $admin, 'handle_revoke_pro_token_ajax', array( 'token_id' => 1 ), 'agent_access_revoke_pro_token' );
 sm_check( false === $r['success'], 'site manager cannot revoke other Pro tokens' );
 $r = sm_ajax( $admin, 'handle_create_ajax', array( 'site_management' => '1' ), 'agent_access_create' );
 sm_check( false === $r['success'], 'self-service endpoint cannot accept privilege-grant field' );
 sm_actor( $owner );
 $r = sm_ajax( $admin, 'handle_admin_create_ajax', array( 'user_id' => $other, 'scope_types' => array( 'post' ), 'site_management' => '1' ), 'agent_access_admin_create' );
 sm_check( true === $r['success'] && Agent_Access_Site_Management::is_enabled( $other ) && user_can( $other, 'manage_options' ), 'admin creation flow supports explicit grant' );
 $actor = get_userdata( $agent ); $actor->add_cap( 'manage_options', true ); $actor->add_cap( 'edit_theme_options', false );
 Agent_Access_Site_Management::save( $agent, false );
 sm_check( user_can( $agent, 'manage_options' ), 'revocation preserves unrelated direct true capability' );
 sm_check( ! user_can( $agent, 'edit_theme_options' ), 'revocation restores unrelated explicit false capability' );
 $actor->remove_cap( 'manage_options' ); $actor->remove_cap( 'edit_theme_options' );
 Agent_Access_Site_Management::save( $agent, true );
 remove_filter( 'user_has_cap', array( 'Agent_Access_Site_Management', 'apply_grant' ), 10 );
 sm_check( ! user_can( $agent, 'manage_options' ) && ! user_can( $agent, 'edit_theme_options' ), 'deactivation filter removal leaves no added capabilities' );
 add_filter( 'user_has_cap', array( 'Agent_Access_Site_Management', 'apply_grant' ), 10, 4 );
 sm_check( user_can( $agent, 'manage_options' ), 'reactivation restores saved explicit grant' );
 $actor->set_role( 'subscriber' ); sm_check( ! Agent_Access_Site_Management::is_enabled( $agent ), 'role change clears dormant grant' );
 $actor->set_role( 'agent' ); sm_check( ! user_can( $agent, 'manage_options' ), 'returning to Agent does not revive grant' );
 update_user_meta( $agent, Agent_Access_Site_Management::META_PREFIX . ( get_current_blog_id() + 999 ), '1' );
 sm_check( ! user_can( $agent, 'manage_options' ), 'grant metadata from another site does not apply' );
 Agent_Access_Site_Management::save( $agent, true ); $actor->add_role( 'editor' ); $actor->remove_role( 'agent' );
 sm_check( ! Agent_Access_Site_Management::is_enabled( $agent ), 'removing Agent role clears grant' );
 sm_check( $role_before === get_role( 'agent' )->capabilities, 'Agent role remains unchanged at end' );
} finally {
 unset( $GLOBALS['_agent_access_pro_token_row'], $GLOBALS['wp_rest_application_password_uuid'], $_COOKIE[LOGGED_IN_COOKIE] );
 $_POST = array(); $_REQUEST = array(); wp_set_current_user( 0 );
 require_once ABSPATH . 'wp-admin/includes/user.php';
 foreach ( $GLOBALS['aa_sm_users'] as $uid ) { wp_delete_user( $uid ); }
}
echo 'PASS ' . $GLOBALS['aa_sm_checks'] . " hosted site-management assertions; fixture users removed.\n";
