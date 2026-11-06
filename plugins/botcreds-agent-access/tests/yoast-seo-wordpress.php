<?php
/** Run with wp eval-file on disposable QA with Yoast active. */
$GLOBALS['aa_seo_checks'] = 0;
function aa_seo_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$GLOBALS['aa_seo_checks']; }
$uid = wp_insert_user( array( 'user_login' => 'aa_seo_regression', 'user_pass' => wp_generate_password( 40 ), 'role' => 'editor' ) );
$post_id = wp_insert_post( array( 'post_title' => 'SEO-only fixture', 'post_content' => 'Original body', 'post_status' => 'publish', 'post_author' => $uid ), true );
try {
	wp_set_current_user( $uid );
	$before = get_post( $post_id, ARRAY_A );
	$server = rest_get_server();
	$scope = array( Agent_Access_Yoast_SEO::SCOPE );
	foreach ( array( $scope, array_merge( $scope, array( '*', 'post', Agent_Access_Scope::ALL_CUSTOM_ENDPOINTS, '/agent-access/v1/*' ) ) ) as $grants ) {
		$GLOBALS['_agent_access_pro_token_row'] = (object) array( 'scope' => wp_json_encode( $grants ) );
		foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
			foreach ( array( '/wp/v2/posts', '/wp/v2/posts/' . $post_id, '/wp/v2/posts/' . $post_id . '/autosaves', '/wp/v2/batch', '/batch/v1', '/wp/v2/settings', '/wp/v2/categories', '/yoast/v1/save', '/newspack-workflows/v1/run' ) as $route ) {
				aa_seo_check( 403 === $server->dispatch( new WP_REST_Request( $method, $route ) )->get_status(), 'deny ' . $method . $route );
			}
		}
	}
	$GLOBALS['_agent_access_pro_token_row'] = (object) array( 'scope' => wp_json_encode( $scope ) );
	$route = Agent_Access_Yoast_SEO::ROUTE . $post_id;
	foreach ( array( 'POST', 'PUT', 'PATCH' ) as $method ) {
		$r = new WP_REST_Request( $method, $route ); $r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( array_fill_keys( Agent_Access_Yoast_SEO::FIELDS, 'SEO ' . $method ) ) );
		aa_seo_check( 200 === $server->dispatch( $r )->get_status(), 'allow SEO ' . $method );
		foreach ( Agent_Access_Yoast_SEO::FIELDS as $field ) { aa_seo_check( 'SEO ' . $method === WPSEO_Meta::get_value( $field, $post_id ), 'durable field ' . $field ); }
	}
	$r = new WP_REST_Request( 'PATCH', $route ); $r->set_header( 'Content-Type', 'application/json' );
	$r->set_body( wp_json_encode( array( 'title' => '<b>SEO</b> %%title%% \' quote \\ path' ) ) );
	aa_seo_check( 200 === $server->dispatch( $r )->get_status(), 'sanitized title with templates quotes slashes saves' );
	aa_seo_check( "SEO %%title%% ' quote \\ path" === WPSEO_Meta::get_value( 'title', $post_id ), 'markup stripped, templates quotes and slashes preserved' );
	aa_seo_check( 200 === $server->dispatch( $r )->get_status(), 'unchanged value is a successful idempotent write' );
	$r->set_body( '{"title":"SEO PATCH"}' ); $server->dispatch( $r );
	$r->set_body( wp_json_encode( array( 'title' => str_repeat( 'x', 10001 ) ) ) );
	aa_seo_check( 400 === $server->dispatch( $r )->get_status(), 'oversized field rejected' );
	$r = new WP_REST_Request( 'POST', Agent_Access_Yoast_SEO::ROUTE . '999999999' ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( '{"title":"No new post"}' );
	aa_seo_check( 404 === $server->dispatch( $r )->get_status(), 'nonexistent post cannot be created' );
	foreach ( array( 'content', 'status', 'post_title', 'meta', 'categories', 'author', 'id', 'canonical', 'meta-robots-noindex', '_method' ) as $bad ) {
		$r = new WP_REST_Request( 'POST', $route ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( array( 'title' => 'Must not save', $bad => 'publish' ) ) );
		aa_seo_check( 400 === $server->dispatch( $r )->get_status(), 'reject mixed ' . $bad );
		aa_seo_check( 'SEO PATCH' === WPSEO_Meta::get_value( 'title', $post_id ), 'mixed rejection atomic ' . $bad );
	}
	foreach ( array( '{}', '[]', 'null', '{"title":null}', '{"title":[]}', '{"title":true}', '{"title":1}', '{"title":' ) as $bad_json ) {
		$r = new WP_REST_Request( 'POST', $route ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( $bad_json );
		aa_seo_check( 400 === $server->dispatch( $r )->get_status(), 'malformed payload rejected' );
	}
	$r = new WP_REST_Request( 'PUT', $route ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( '{"title":"Not saved"}' ); $r->set_query_params( array( 'status' => 'publish' ) );
	aa_seo_check( 400 === $server->dispatch( $r )->get_status(), 'query pollution rejected' );
	$r->set_query_params( array() ); $r->set_body_params( array( 'content' => 'bad' ) );
	aa_seo_check( 400 === $server->dispatch( $r )->get_status(), 'form pollution rejected' );
	$r->set_body_params( array() ); $r->set_file_params( array( 'file' => array( 'name' => 'bad' ) ) );
	aa_seo_check( 400 === $server->dispatch( $r )->get_status(), 'files rejected' );
	aa_seo_check( 403 === $server->dispatch( new WP_REST_Request( 'DELETE', $route ) )->get_status(), 'SEO delete forbidden' );
	aa_seo_check( 200 === $server->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status(), 'read back SEO' );
	$GLOBALS['_agent_access_pro_token_row']->scope = wp_json_encode( array( '__read_only__', Agent_Access_Yoast_SEO::SCOPE, '*' ) );
	$r = new WP_REST_Request( 'POST', $route ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( '{"title":"Not saved"}' );
	aa_seo_check( 403 === $server->dispatch( $r )->get_status(), 'read only beats mixed grants' );
	unset( $GLOBALS['_agent_access_pro_token_row'] );
	wp_set_current_user( 0 );
	aa_seo_check( 401 === $server->dispatch( $r )->get_status(), 'anonymous denied' );
	wp_set_current_user( $uid );
	aa_seo_check( $before === get_post( $post_id, ARRAY_A ), 'ALL post columns unchanged' );
	$parse = new ReflectionMethod( 'Agent_Access_Admin', 'collect_scope_from_post' ); $parse->setAccessible( true );
	$_POST = array( 'scope_seo_only' => '1', 'scope_full' => '1', 'scope_all_custom' => '1', 'scope_types' => array( 'post' ) );
	aa_seo_check( is_wp_error( $parse->invoke( null ) ), 'self-service cannot assign cross-author SEO scope' );
	$_POST['scope_read_only'] = '1'; aa_seo_check( array( '__read_only__' ) === $parse->invoke( null ), 'read only overrides SEO form' );
	Agent_Access_Scope::save( $uid, 'seo-test', $scope ); aa_seo_check( $scope === Agent_Access_Scope::get( $uid, 'seo-test' ), 'scope persists' );
	aa_seo_check( 'Yoast SEO only' === Agent_Access_Scope::get_label( $scope ), 'scope label' );
	echo 'PASS ' . $GLOBALS['aa_seo_checks'] . " hosted SEO scope and durable-data assertions\n";
} finally {
	unset( $GLOBALS['_agent_access_pro_token_row'] ); wp_set_current_user( 0 );
	wp_delete_post( $post_id, true ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $uid );
}
