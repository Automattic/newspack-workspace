<?php
/** Hosted regression runner. Run with wp eval-file; never package in the plugin ZIP. */
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
$GLOBALS['aa_checks'] = 0;
function aa_check( $ok, $label ) {
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$GLOBALS['aa_checks'];
}
function aa_enforce( $scope, $route, $method = 'POST' ) {
	$GLOBALS['_agent_access_pro_token_row'] = (object) array( 'scope' => wp_json_encode( $scope ) );
	return Agent_Access_Scope::enforce( null, rest_get_server(), new WP_REST_Request( $method, $route ) );
}
function aa_parse( $input ) {
	$_POST = wp_slash( $input );
	$method = new ReflectionMethod( 'Agent_Access_Admin', 'collect_scope_from_post' );
	$method->setAccessible( true );
	return $method->invoke( null );
}
function aa_render( $scope ) {
	$admin = new Agent_Access_Admin( new Agent_Access_API() );
	$method = new ReflectionMethod( $admin, 'render_scope_controls' );
	$method->setAccessible( true );
	ob_start(); $method->invoke( $admin, $scope ); return ob_get_clean();
}
function aa_ajax( $admin, $payload ) {
	$_POST = wp_slash( $payload );
	$_REQUEST = array( 'nonce' => wp_create_nonce( 'agent_access_admin_nonce' ) );
	$handler = function () { return function () { throw new RuntimeException( 'AA_JSON_SENT' ); }; };
	add_filter( 'wp_die_ajax_handler', $handler );
	ob_start();
	try { $admin->handle_admin_update_ajax(); }
	catch ( RuntimeException $e ) { if ( 'AA_JSON_SENT' !== $e->getMessage() ) { throw $e; } }
	finally { $json = ob_get_clean(); remove_filter( 'wp_die_ajax_handler', $handler ); }
	return json_decode( $json, true );
}
rest_get_server();
foreach ( array( 'newspack-workflows/v1', 'wc/v3', 'wp-future/v1', 'acme/v1', 'wp/v99' ) as $ns ) {
	register_rest_route( $ns, '/aa-scope-probe', array( 'methods' => 'GET,POST,PUT,DELETE', 'permission_callback' => function () { return current_user_can( 'manage_options' ); }, 'callback' => function () { return array( 'aa_probe' => true ); } ) );
}
$uid = 0;
try {
	$custom = Agent_Access_Scope::ALL_CUSTOM_ENDPOINTS;
	foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
		aa_check( null === aa_enforce( array( $custom ), '/newspack-workflows/v1/aa-scope-probe', $method ), 'all custom allows workflow ' . $method );
		aa_check( null === aa_enforce( array( $custom ), '/wc/v3/orders/123', $method ), 'all custom allows Woo ' . $method );
		foreach ( array( '/wp/v2/users', '/wp/v2/settings', '/wp/v2/pages', '/wp/v2/posts', '/wp-site-health/v1/tests', '/wp-future/v1/aa-scope-probe', '/wp/v99/aa-scope-probe', '/oembed/1.0/proxy' ) as $route ) {
			aa_check( is_wp_error( aa_enforce( array( $custom ), $route, $method ) ), 'custom blanket denies core ' . $method . $route );
		}
	}
	aa_check( is_wp_error( aa_enforce( array( $custom ), '/wp/v2/categories' ) ), 'custom-only does not grant taxonomy writes' );
	aa_check( null === aa_enforce( array( 'post', $custom ), '/wp/v2/posts' ), 'content grants remain effective' );
	aa_check( is_wp_error( aa_enforce( array( 'post', $custom ), '/wp/v2/pages' ) ), 'posts plus custom does not grant pages' );
	aa_check( is_wp_error( aa_enforce( array( 'post' ), '/wc/v3/orders' ) ), 'post scope still blocks Woo writes' );
	aa_check( is_wp_error( aa_enforce( array( $custom ), '/unregistered/v1/save' ) ), 'blanket requires registered namespace' );
	aa_check( is_wp_error( aa_enforce( array( '/wc/v3/*' ), '/wc/v30/orders' ) ), 'route prefix boundary' );
	aa_check( null === aa_enforce( array( '/wc/v3/*' ), '/wc/v3/orders' ), 'specific route grant' );
	aa_check( null === aa_enforce( array( '/wc/v3/orders' ), '/wc/v3/orders/1' ), 'legacy prefix grants retain descendants' );
	aa_check( null === aa_enforce( array( '*' ), '/wp/v2/users' ), 'legacy full access' );
	aa_check( is_wp_error( aa_enforce( array( '__read_only__' ), '/wc/v3/orders' ) ), 'read-only wins' );
	foreach ( array( 'GET', 'HEAD', 'OPTIONS' ) as $method ) {
		aa_check( null === aa_enforce( array( '__read_only__' ), '/wc/v3/orders', $method ), 'reads pass scope gate ' . $method );
	}
	unset( $GLOBALS['_agent_access_pro_token_row'] );
	aa_check( null === Agent_Access_Scope::enforce( null, rest_get_server(), new WP_REST_Request( 'POST', '/wc/v3/orders' ) ), 'ordinary cookie/non-agent requests untouched' );
	$discovered = array_column( Agent_Access_Scope::get_custom_integrations(), 'slug' );
	aa_check( in_array( '/newspack-workflows/v1/*', $discovered, true ), 'discover Workflows' );
	aa_check( in_array( '/wc/v3/*', $discovered, true ), 'discover Woo' );
	aa_check( ! in_array( '/wp/v2/*', $discovered, true ), 'do not discover core as custom' );
	aa_check( ! in_array( '/wp-future/v1/*', $discovered, true ), 'reserve future core prefix' );
	register_rest_route( 'new-plugin/v1', '/probe', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => '__return_true' ) );
	aa_check( null === aa_enforce( array( $custom ), '/new-plugin/v1/probe' ), 'future integration allowed by blanket grant' );
	foreach ( array( 'https://site.test/wc/v3/*', '/*', '/', '/wp/v2/users', '/wp-future/v1/*', '/oembed/1.0/*', '/wc/*/orders', '/wc/v3/../wp', '/wc/v3/%2e%2e', "/wc/v3/\x00", '/wc/v3/?x=1' ) as $bad ) {
		aa_check( is_wp_error( aa_parse( array( 'scope_custom_routes' => $bad ) ) ), 'reject malformed/overbroad grant ' . bin2hex( $bad ) );
	}
	aa_check( array( '__read_only__' ) === aa_parse( array() ), 'empty selection is read-only' );
	aa_check( array( '*' ) === aa_parse( array( 'scope_full' => '1' ) ), 'explicit full access' );
	aa_check( array( '__read_only__' ) === aa_parse( array( 'scope_read_only' => '1', 'scope_full' => '1', 'scope_all_custom' => '1' ) ), 'read-only takes precedence' );
	aa_check( array( $custom ) === aa_parse( array( 'scope_all_custom' => '1' ) ), 'all custom is distinct from full' );
	aa_check( array( 'post', '/wc/v3/*' ) === aa_parse( array( 'scope_types' => array( 'post' ), 'scope_custom_routes' => " /wc/v3/* \r\n/wc/v3/*\n" ) ), 'parse multiline and deduplicate' );
	aa_check( is_wp_error( aa_parse( array( 'scope_types' => array( array( 'post' ) ) ) ) ), 'reject nested type' );
	aa_check( is_wp_error( aa_parse( array( 'scope_custom_routes' => array( '/wc/v3/*' ) ) ) ), 'reject malformed textarea' );
	$html = aa_render( array( 'post', '/inactive-plugin/v1/*' ) );
	aa_check( false !== strpos( $html, '/inactive-plugin/v1/*' ), 'inactive route retained' );
	aa_check( false !== strpos( $html, 'Newspack Workflows' ), 'friendly Workflow label' );
	aa_check( false !== strpos( $html, 'WooCommerce' ), 'friendly Woo label' );
	aa_check( false !== strpos( $html, 'scope_custom_routes' ), 'advanced field rendered' );
	aa_check( 1 === preg_match( '/value="inactive_cpt"[^>]*checked=/', aa_render( array( 'inactive_cpt' ) ) ), 'inactive CPT grant retained' );
	aa_check( 1 === preg_match( '/class="agent-access-scope-full"[^>]*checked=/', aa_render( array( '*' ) ) ), 'full access checked on roundtrip' );
	$uid = wp_insert_user( array( 'user_login' => 'aa_scope_qa_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );
	if ( is_wp_error( $uid ) ) { throw new RuntimeException( 'Fixture user setup failed' ); }
	$uuid = wp_generate_uuid4();
	Agent_Access_Scope::save( $uid, $uuid, array( 'post', $custom, '/wc/v3/*' ) );
	aa_check( array( 'post', $custom, '/wc/v3/*' ) === Agent_Access_Scope::get( $uid, $uuid ), 'real database scope roundtrip' );
	update_user_meta( $uid, Agent_Access_Scope::META_PREFIX . $uuid, 'posts_media' );
	aa_check( array( 'post', 'attachment' ) === Agent_Access_Scope::get( $uid, $uuid ), 'legacy database migration' );
	unset( $GLOBALS['_agent_access_pro_token_row'] );
	wp_set_current_user( $uid );
	$request = new WP_REST_Request( 'PUT', '/newspack-workflows/v1/aa-scope-probe' );
	$response = rest_do_request( $request );
	aa_check( 200 === $response->get_status() && true === $response->get_data()['aa_probe'], 'ordinary authenticated administrator dispatch unaffected' );
	$api = new Agent_Access_API();
	$credential = $api->create_password( $uid );
	aa_check( ! is_wp_error( $credential ), 'fixture credential creation' );
	$uuid = $credential['uuid'];
	$admin = new Agent_Access_Admin( $api );
	$response = aa_ajax( $admin, array( 'user_id' => $uid, 'scope_types' => array( 'post' ), 'scope_custom_routes' => '/newspack-workflows/v1/*', 'rate_limit' => 'standard', 'content_policy' => 'standard' ) );
	aa_check( true === $response['success'], 'real nonce-protected admin update callback' );
	aa_check( array( 'post', '/newspack-workflows/v1/*' ) === Agent_Access_Scope::get( $uid, $uuid ), 'callback persisted exact scope' );
	$before = Agent_Access_Scope::get( $uid, $uuid );
	$response = aa_ajax( $admin, array( 'user_id' => $uid, 'scope_custom_routes' => '/*' ) );
	aa_check( false === $response['success'] && $before === Agent_Access_Scope::get( $uid, $uuid ), 'invalid update cannot change saved scope' );
	$fixture_user = get_userdata( $uid ); $fixture_user->set_role( 'subscriber' );
	wp_set_current_user( 0 ); wp_set_current_user( $uid );
	$response = aa_ajax( $admin, array( 'user_id' => $uid, 'scope_full' => '1' ) );
	aa_check( false === $response['success'] && $before === Agent_Access_Scope::get( $uid, $uuid ), 'subscriber cannot expand own credential scope' );
	$fixture_user->set_role( 'administrator' );
	wp_set_current_user( 0 ); wp_set_current_user( $uid );
	$response = aa_ajax( $admin, array( 'user_id' => $uid, 'scope_all_custom' => '1' ) );
	aa_check( true === $response['success'] && array( $custom ) === Agent_Access_Scope::get( $uid, $uuid ), 'all-custom grant persisted without full access' );
	wp_set_current_user( 0 );
	$response = rest_do_request( new WP_REST_Request( 'PUT', '/newspack-workflows/v1/aa-scope-probe' ) );
	aa_check( in_array( $response->get_status(), array( 401, 403 ), true ), 'endpoint still enforces native WordPress permission callback' );
} finally {
	unset( $GLOBALS['_agent_access_pro_token_row'] );
	$_POST = array(); wp_set_current_user( 0 );
	if ( $uid && ! is_wp_error( $uid ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $uid ); }
}
echo 'PASS ' . $GLOBALS['aa_checks'] . " hosted WordPress scope assertions; fixtures cleaned.\n";
