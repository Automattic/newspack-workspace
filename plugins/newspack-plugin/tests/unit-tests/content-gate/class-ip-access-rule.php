<?php
/**
 * Tests for IP_Access_Rule utility methods.
 *
 * @package Newspack\Tests\Content_Gate
 */

use Newspack\Content_Gate\IP_Access_Rule;

/**
 * Test IP_Access_Rule functionality.
 *
 * @group Access_Rules
 */
class Newspack_Test_IP_Access_Rule extends WP_UnitTestCase {

	/**
	 * Test exact IP matching.
	 */
	public function test_exact_ip_match() {
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.5' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.6', '10.0.0.5' ) );
	}

	/**
	 * Test CIDR block matching.
	 */
	public function test_cidr_match() {
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '192.168.1.50', '192.168.1.0/24' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '192.168.2.1', '192.168.1.0/24' ) );
	}

	/**
	 * Test comma-separated ranges.
	 */
	public function test_comma_separated_ranges() {
		$ranges = '10.0.0.5,192.168.1.0/24';
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', $ranges ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '192.168.1.100', $ranges ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '172.16.0.1', $ranges ) );
	}

	/**
	 * Test that empty ranges string returns false.
	 */
	public function test_empty_ranges_returns_false() {
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.1', '' ) );
	}

	/**
	 * Test that an invalid CIDR entry is skipped and returns false.
	 */
	public function test_invalid_cidr_is_skipped() {
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.1', '999.999.999.999/24' ) );
	}

	/**
	 * Test that an invalid IP address returns false.
	 */
	public function test_invalid_ip_returns_false() {
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( 'not-an-ip', '10.0.0.0/8' ) );
	}

	/**
	 * Test that malformed CIDR prefixes do not match.
	 *
	 * Previously `(int) $bits` silently coerced non-numeric strings to 0,
	 * letting "10.0.0.0/foo" and "10.0.0.0/" match every IP.
	 */
	public function test_malformed_cidr_prefix_does_not_match() {
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.0/foo' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.0/' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.0/24junk' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.0/-1' ) );
		// Valid CIDR continues to match.
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.0/24' ) );
	}

	/**
	 * Test that whitespace around the CIDR separator is tolerated.
	 *
	 * Common admin typos like "192.168.1.0 / 24" should not silently
	 * disable the rule.
	 */
	public function test_cidr_tolerates_whitespace_around_slash() {
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '192.168.1.50', '192.168.1.0/ 24' ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '192.168.1.50', '192.168.1.0 /24' ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '192.168.1.50', '192.168.1.0 / 24' ) );
	}

	/**
	 * Test that the REST route is registered.
	 */
	public function test_rest_route_registered() {
		do_action( 'rest_api_init' );

		$routes         = rest_get_server()->get_routes( NEWSPACK_API_NAMESPACE );
		$expected_route = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE;
		$this->assertArrayHasKey( $expected_route, $routes, 'The institutional access REST route should be registered.' );

		$endpoint = $routes[ $expected_route ][0];
		$this->assertArrayHasKey( 'GET', $endpoint['methods'], 'The route should accept GET requests.' );
	}

	/**
	 * Test the REST endpoint returns the expected JSON shape.
	 */
	public function test_rest_endpoint_response_shape() {
		$request  = new WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'valid', $data, 'Response should contain a "valid" key.' );
		$this->assertIsBool( $data['valid'] );
	}

	/**
	 * Test the REST endpoint returns institution name when IP matches.
	 */
	public function test_rest_endpoint_with_institution() {
		// Hook a filter that returns an institution post ID.
		$inst_id = self::factory()->post->create( [ 'post_title' => 'Test Library' ] );
		add_filter(
			'newspack_content_gate_check_ip',
			function () use ( $inst_id ) {
				return $inst_id;
			}
		);

		$request  = new WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE );
		$response = @rest_do_request( $request ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- setcookie/nocache_headers cannot send headers in tests.
		$data = $response->get_data();

		$this->assertTrue( $data['valid'] );
		$this->assertSame( 'Test Library', $data['institution'] );

		remove_all_filters( 'newspack_content_gate_check_ip' );
		wp_delete_post( $inst_id, true );
	}

	/**
	 * Test get_redirect_url rebuilds the URL without the institutional-access param.
	 */
	public function test_redirect_url_strips_endpoint_param() {
		$original_uri           = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$original_get           = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_SERVER['REQUEST_URI'] = '/some-article/?institutional-access=1&foo=bar';
		$_GET                   = [
			IP_Access_Rule::ENDPOINT => '1',
			'foo'                    => 'bar',
		];

		$method = new ReflectionMethod( IP_Access_Rule::class, 'get_redirect_url' );
		$method->setAccessible( true );
		$url = $method->invoke( null );

		$this->assertStringContainsString( '/some-article/', $url );
		$this->assertStringContainsString( 'foo=bar', $url );
		$this->assertStringNotContainsString( 'institutional-access', $url );

		if ( null === $original_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $original_uri;
		}
		$_GET = $original_get;
	}

	/**
	 * Test get_dedicated_redirect_url uses redirect_to param.
	 */
	public function test_dedicated_redirect_url_uses_redirect_to() {
		$_GET = [ 'redirect_to' => home_url( '/target-page/' ) ];

		$method = new ReflectionMethod( IP_Access_Rule::class, 'get_dedicated_redirect_url' );
		$method->setAccessible( true );
		$url = $method->invoke( null );

		$this->assertSame( home_url( '/target-page/' ), $url );

		$_GET = [];
	}

	/**
	 * Test get_dedicated_redirect_url rejects external URLs.
	 */
	public function test_dedicated_redirect_url_rejects_external() {
		$_GET = [ 'redirect_to' => 'https://evil.com/steal' ];

		$method = new ReflectionMethod( IP_Access_Rule::class, 'get_dedicated_redirect_url' );
		$method->setAccessible( true );
		$url = $method->invoke( null );

		$this->assertSame( home_url( '/' ), $url );

		$_GET = [];
	}

	/**
	 * Test get_dedicated_redirect_url falls back to homepage.
	 */
	public function test_dedicated_redirect_url_fallback() {
		$_GET = [];

		$method = new ReflectionMethod( IP_Access_Rule::class, 'get_dedicated_redirect_url' );
		$method->setAccessible( true );
		$url = $method->invoke( null );

		$this->assertSame( home_url( '/' ), $url );
	}

	/**
	 * Test REST endpoint with institution_id param — matching IP.
	 */
	public function test_rest_endpoint_institution_id_match() {
		$original_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		$inst_id = \Newspack\Institution::create(
			'REST Test Library',
			'',
			[ 'ip_range' => '192.168.1.0/24' ]
		);
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.50'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		$request = new WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE );
		$request->set_param( 'institution_id', $inst_id );
		$response = @rest_do_request( $request ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$data     = $response->get_data();

		$this->assertTrue( $data['valid'] );
		$this->assertSame( 'REST Test Library', $data['institution'] );

		if ( null === $original_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		} else {
			$_SERVER['REMOTE_ADDR'] = $original_addr; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		}
		wp_delete_post( $inst_id, true );
	}

	/**
	 * Test REST endpoint with institution_id param — non-matching IP.
	 */
	public function test_rest_endpoint_institution_id_no_match() {
		$original_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		$inst_id = \Newspack\Institution::create(
			'REST Test Library',
			'',
			[ 'ip_range' => '192.168.1.0/24' ]
		);
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$_SERVER['REMOTE_ADDR'] = '10.0.0.1'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		$request = new WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE );
		$request->set_param( 'institution_id', $inst_id );
		$response = @rest_do_request( $request ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- nocache_headers cannot send headers in tests.
		$data     = $response->get_data();

		$this->assertFalse( $data['valid'] );
		$this->assertSame( 'REST Test Library', $data['institution'], 'Institution name should be returned even on failure.' );

		if ( null === $original_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		} else {
			$_SERVER['REMOTE_ADDR'] = $original_addr; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		}
		wp_delete_post( $inst_id, true );
	}

	/**
	 * Test REST endpoint with invalid institution_id.
	 */
	public function test_rest_endpoint_institution_id_invalid() {
		$request = new WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE );
		$request->set_param( 'institution_id', 999999 );
		$response = @rest_do_request( $request ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- nocache_headers cannot send headers in tests.
		$data     = $response->get_data();

		$this->assertFalse( $data['valid'] );
		$this->assertArrayNotHasKey( 'institution', $data );
	}

	/**
	 * Test the POST route is registered.
	 */
	public function test_post_route_registered() {
		do_action( 'rest_api_init' );

		$routes         = rest_get_server()->get_routes( NEWSPACK_API_NAMESPACE );
		$expected_route = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE;
		$endpoint       = $routes[ $expected_route ][1];
		$this->assertArrayHasKey( 'POST', $endpoint['methods'], 'The route should accept POST requests.' );
	}

	/**
	 * Test POST requires manage_options capability.
	 */
	public function test_post_requires_authentication() {
		$route = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE;

		// Unauthenticated request should be forbidden.
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'ip', '10.0.0.1' );
		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );

		// Non-admin user should be forbidden.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'ip', '10.0.0.1' );
		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test POST returns 400 for missing or invalid ip param.
	 */
	public function test_post_requires_valid_ip_param() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$route = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE;

		// Missing param (handled by WP REST required arg validation).
		$request  = new WP_REST_Request( 'POST', $route );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );

		// Invalid IP.
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'ip', 'not-an-ip' );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );

		// IPv6 not supported.
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'ip', '2001:db8::1' );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Test POST returns correct show_paywall value.
	 */
	public function test_post_show_paywall_response() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$route = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE;

		// No match: show paywall.
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'ip', '203.0.113.50' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['show_paywall'] );

		// Match: hide paywall.
		add_filter(
			'newspack_content_gate_check_ip',
			function () {
				return 123;
			}
		);
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'ip', '10.0.0.1' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['show_paywall'] );

		remove_all_filters( 'newspack_content_gate_check_ip' );
	}

	/**
	 * Test the IP allowlist GET route is registered.
	 */
	public function test_ip_allowlist_route_registered() {
		do_action( 'rest_api_init' );

		$routes         = rest_get_server()->get_routes( NEWSPACK_API_NAMESPACE );
		$expected_route = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$this->assertArrayHasKey( $expected_route, $routes, 'The IP allowlist REST route should be registered.' );

		$endpoint = $routes[ $expected_route ][0];
		$this->assertArrayHasKey( 'GET', $endpoint['methods'], 'The route should accept GET requests.' );
		$this->assertSame( [ IP_Access_Rule::class, 'api_permissions_check' ], $endpoint['permission_callback'], 'The route should be gated by the admin permission callback.' );
	}

	/**
	 * Test the IP allowlist endpoint requires manage_options.
	 */
	public function test_ip_allowlist_requires_authentication() {
		$route = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;

		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test the IP allowlist endpoint response shape.
	 */
	public function test_ip_allowlist_response_shape() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$inst_id = \Newspack\Institution::create(
			'Allowlist Test Library',
			'',
			[ 'ip_range' => '192.168.1.0/24,10.0.0.5' ]
		);
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );

		$entry = null;
		foreach ( $data as $item ) {
			if ( $item['id'] === $inst_id ) {
				$entry = $item;
				break;
			}
		}
		$this->assertNotNull( $entry, 'Created institution should appear in response.' );
		$this->assertSame( 'Allowlist Test Library', $entry['name'] );
		$this->assertSame( [ '192.168.1.0/24', '10.0.0.5' ], $entry['ip_ranges'] );
	}

	/**
	 * Test institutions without configured IP ranges are excluded.
	 */
	public function test_ip_allowlist_excludes_institutions_without_ip_ranges() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$with_ip = \Newspack\Institution::create( 'Has IPs', '', [ 'ip_range' => '10.0.0.1' ] );
		$no_ip   = \Newspack\Institution::create( 'No IPs', '', [ 'email_domain' => 'example.edu' ] );
		$this->assertIsInt( $with_ip );
		$this->assertIsInt( $no_ip );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertContains( $with_ip, $ids );
		$this->assertNotContains( $no_ip, $ids );
	}

	/**
	 * Test institutions are returned sorted by id ascending.
	 */
	public function test_ip_allowlist_sorted_by_id_ascending() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$first  = \Newspack\Institution::create( 'First', '', [ 'ip_range' => '10.0.0.1' ] );
		$second = \Newspack\Institution::create( 'Second', '', [ 'ip_range' => '10.0.0.2' ] );
		$third  = \Newspack\Institution::create( 'Third', '', [ 'ip_range' => '10.0.0.3' ] );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertContains( $first, $ids );
		$this->assertContains( $second, $ids );
		$this->assertContains( $third, $ids );

		$sorted = $ids;
		sort( $sorted, SORT_NUMERIC );
		$this->assertSame( $sorted, $ids, 'Full institutions list should be in id-ascending order.' );
	}

	/**
	 * Test comma-separated ip_range meta is split, trimmed, and emptied entries dropped.
	 */
	public function test_ip_allowlist_parses_comma_separated_ranges() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$inst_id = \Newspack\Institution::create(
			'Whitespace Test',
			'',
			[ 'ip_range' => '  10.0.0.1 ,, 192.168.1.0/24 , ' ]
		);
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$entry = null;
		foreach ( $data as $item ) {
			if ( $item['id'] === $inst_id ) {
				$entry = $item;
				break;
			}
		}
		$this->assertNotNull( $entry );
		$this->assertSame( [ '10.0.0.1', '192.168.1.0/24' ], $entry['ip_ranges'] );
	}

	/**
	 * Test the endpoint returns an empty institutions array when none exist.
	 */
	public function test_ip_allowlist_returns_empty_when_no_institutions() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
	}

	/**
	 * Test institutions with only separators or whitespace in ip_range are excluded.
	 */
	public function test_ip_allowlist_excludes_institution_with_only_separators() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$inst_id = \Newspack\Institution::create( 'Separators Only', '', [ 'ip_range' => ',, ,' ] );
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertNotContains( $inst_id, $ids );
	}

	/**
	 * Test syntactically invalid IPv4/CIDR entries are dropped from the response.
	 */
	public function test_ip_allowlist_drops_invalid_ip_entries() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$inst_id = \Newspack\Institution::create(
			'Mixed Validity',
			'',
			[ 'ip_range' => 'not-an-ip,10.0.0.1,999.999.999.999,192.168.1.0/24,10.0.0.5/40' ]
		);
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );

		$entry = null;
		foreach ( $response->get_data() as $item ) {
			if ( $item['id'] === $inst_id ) {
				$entry = $item;
				break;
			}
		}
		$this->assertNotNull( $entry );
		$this->assertSame( [ '10.0.0.1', '192.168.1.0/24' ], $entry['ip_ranges'] );
	}

	/**
	 * Test the endpoint normalizes whitespace around the CIDR separator.
	 */
	public function test_ip_allowlist_normalizes_whitespace_around_cidr_slash() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$inst_id = \Newspack\Institution::create(
			'Whitespace CIDR',
			'',
			[ 'ip_range' => '192.168.1.0 / 24, 10.0.0.0/ 8' ]
		);
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );

		$entry = null;
		foreach ( $response->get_data() as $item ) {
			if ( $item['id'] === $inst_id ) {
				$entry = $item;
				break;
			}
		}
		$this->assertNotNull( $entry );
		$this->assertSame( [ '192.168.1.0/24', '10.0.0.0/8' ], $entry['ip_ranges'] );
	}

	/**
	 * Test malformed CIDR prefixes are rejected.
	 *
	 * Previously `(int) $bits` silently coerced non-numeric strings to 0,
	 * letting `"10.0.0.0/foo"` and `"10.0.0.0/"` match all IPs.
	 */
	public function test_ip_allowlist_drops_malformed_cidr_prefixes() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$inst_id = \Newspack\Institution::create(
			'Malformed CIDR',
			'',
			[ 'ip_range' => '10.0.0.0/foo,10.0.0.0/,10.0.0.0/24junk,10.0.0.0/-1,10.0.0.0/24' ]
		);
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );

		$entry = null;
		foreach ( $response->get_data() as $item ) {
			if ( $item['id'] === $inst_id ) {
				$entry = $item;
				break;
			}
		}
		$this->assertNotNull( $entry );
		$this->assertSame( [ '10.0.0.0/24' ], $entry['ip_ranges'] );

		// Matcher must agree: malformed prefixes should not match anything.
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.0/foo' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.0/' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.0/-1' ) );
	}

	/**
	 * Test the `newspack_content_gate_ip_allowlist` filter is applied and
	 * receives the built institution list as input.
	 */
	public function test_ip_allowlist_filter_is_applied() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$inst_id = \Newspack\Institution::create( 'Filter Source', '', [ 'ip_range' => '10.1.2.3' ] );
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$captured    = null;
		$replacement = [
			[
				'id'        => 999999,
				'name'      => 'Filtered',
				'ip_ranges' => [ '8.8.8.8' ],
			],
		];
		add_filter(
			'newspack_content_gate_ip_allowlist',
			function ( $list ) use ( &$captured, $replacement ) {
				$captured = $list;
				return $replacement;
			}
		);

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );

		$this->assertIsArray( $captured, 'Filter should receive the pre-filter list.' );

		$found = null;
		foreach ( $captured as $entry ) {
			if ( ( $entry['id'] ?? null ) === $inst_id ) {
				$found = $entry;
				break;
			}
		}
		$this->assertNotNull( $found, 'Filter input should include freshly built institution entries.' );
		$this->assertSame( 'Filter Source', $found['name'] );
		$this->assertSame( [ '10.1.2.3' ], $found['ip_ranges'] );

		$this->assertSame( $replacement, $response->get_data(), 'Response should reflect the filter return value.' );

		remove_all_filters( 'newspack_content_gate_ip_allowlist' );
	}
}
