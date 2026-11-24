<?php
/** Run with wp eval-file after the private cross-author HTTP fixture is created. */
$f = json_decode( file_get_contents( getenv( 'AA_SEO_FIXTURE' ) ?: '/tmp/agent-access-seo-all-20261106/http-private.json' ), true );
$GLOBALS['aa_seo_admin_checks'] = 0;
function aa_seo_admin_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$GLOBALS['aa_seo_admin_checks']; }
function aa_seo_actor( $id, $cookie ) { wp_set_current_user( $id ); $_COOKIE = array(); foreach ( explode( '; ', $cookie ) as $pair ) { list( $name, $value ) = explode( '=', $pair, 2 ); $_COOKIE[$name] = $value; } }
$agent = get_user_by( 'id', $f['agent'] );
try {
 aa_seo_actor( $f['owner'], $f['owner_cookie'] );
 aa_seo_admin_check( Agent_Access_Yoast_SEO::can_assign( $f['agent'] ), 'human admin cookie may assign another user' );
 aa_seo_admin_check( ! Agent_Access_Yoast_SEO::can_assign( $f['owner'] ), 'self-assignment forbidden' );
 aa_seo_admin_check( ! Agent_Access_Yoast_SEO::can_assign( 0 ), 'invalid target forbidden' );
 $parse = new ReflectionMethod( 'Agent_Access_Admin', 'collect_scope_from_post' ); $parse->setAccessible( true );
 $_POST = array( 'scope_seo_only' => '1', 'scope_full' => '1', 'scope_all_custom' => '1' );
 aa_seo_admin_check( array( Agent_Access_Yoast_SEO::SCOPE ) === $parse->invoke( null, $f['agent'] ), 'validated administrator assignment wins over broad flags' );
 foreach ( array( Agent_Access_Yoast_SEO::SCOPE, Agent_Access_Yoast_SEO::LEGACY_SCOPE ) as $scope ) {
  $_POST = array( 'scope_types' => array( $scope ) ); aa_seo_admin_check( is_wp_error( $parse->invoke( null, $f['agent'] ) ), 'special marker cannot be smuggled as post type' );
 }
 $GLOBALS['_agent_access_pro_token_row'] = (object) array( 'scope' => '["*"]' );
 aa_seo_admin_check( ! Agent_Access_Yoast_SEO::can_assign( $f['agent'] ), 'Pro token cannot assign even with admin cookie' ); unset( $GLOBALS['_agent_access_pro_token_row'] );
 $GLOBALS['wp_rest_application_password_uuid'] = 'aa-test-authenticated-password';
 aa_seo_admin_check( ! Agent_Access_Yoast_SEO::can_assign( $f['agent'] ), 'Application Password cannot assign even with admin cookie' ); unset( $GLOBALS['wp_rest_application_password_uuid'] );
 $_COOKIE = array(); aa_seo_admin_check( ! Agent_Access_Yoast_SEO::can_assign( $f['agent'] ), 'admin without valid cookie cannot assign' );
 aa_seo_actor( $f['agent'], $f['agent_cookie'] );
 foreach ( array( 'manage_options', 'promote_users', 'edit_users' ) as $cap ) { $agent->add_cap( $cap ); }
 wp_set_current_user( 0 ); wp_set_current_user( $f['agent'] );
 aa_seo_admin_check( current_user_can( 'manage_options' ) && current_user_can( 'promote_users' ), 'fixture has administrative capabilities for meaningful role denial' );
 aa_seo_admin_check( ! Agent_Access_Yoast_SEO::can_assign( $f['low'] ), 'Agent-role actor still cannot assign after capability grant' );
 $_POST = array( 'scope_seo_only' => '1' ); aa_seo_admin_check( is_wp_error( $parse->invoke( null, $f['low'] ) ), 'privileged agent forged scope request denied' );
 $render = new ReflectionMethod( 'Agent_Access_Admin', 'render_scope_controls' ); $render->setAccessible( true );
 ob_start(); $render->invoke( new Agent_Access_Admin( new Agent_Access_API() ), array( Agent_Access_Yoast_SEO::LEGACY_SCOPE ), $f['low'] ); $html = ob_get_clean();
 aa_seo_admin_check( false === strpos( $html, 'name="scope_seo_only"' ), 'unauthorized forms omit assignable SEO input' );
 aa_seo_admin_check( false !== strpos( $html, 'administrator must save' ), 'legacy grant migration notice visible' );
 echo 'PASS ' . $GLOBALS['aa_seo_admin_checks'] . " hosted admin-assignment assertions\n";
} finally {
 foreach ( array( 'manage_options', 'promote_users', 'edit_users' ) as $cap ) { $agent->remove_cap( $cap ); }
 unset( $GLOBALS['_agent_access_pro_token_row'], $GLOBALS['wp_rest_application_password_uuid'] );
 wp_set_current_user( 0 ); $_COOKIE = array();
}
