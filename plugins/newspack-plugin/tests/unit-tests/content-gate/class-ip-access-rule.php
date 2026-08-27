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
	 * Test dash-range matching, including both boundaries.
	 */
	public function test_dash_range_match() {
		$range = '203.0.113.0-203.0.113.255';
		// Both boundaries are inclusive.
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '203.0.113.0', $range ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '203.0.113.255', $range ) );
		// Inside the range.
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '203.0.113.128', $range ) );
		// Just outside either boundary.
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '203.0.112.255', $range ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '203.0.114.0', $range ) );
	}

	/**
	 * Test a dash range spanning octet boundaries (not expressible as one CIDR).
	 */
	public function test_dash_range_spanning_octets() {
		$range = '10.0.0.200-10.0.1.100';
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.200', $range ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.255', $range ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.1.0', $range ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.1.100', $range ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.199', $range ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.1.101', $range ) );
	}

	/**
	 * Test a reversed dash range (end < start) is treated as invalid and
	 * never matches — not even its own endpoints. A reversed range is most
	 * likely a typo, and silently normalizing it could grant a much larger
	 * range than intended; the UI warns about it instead.
	 */
	public function test_reversed_dash_range_never_matches() {
		$range = '203.0.113.255-203.0.113.0';
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '203.0.113.0', $range ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '203.0.113.128', $range ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '203.0.113.255', $range ) );
	}

	/**
	 * Test a single-address dash range (start === end) matches exactly that IP.
	 */
	public function test_single_address_dash_range() {
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.5-10.0.0.5' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.6', '10.0.0.5-10.0.0.5' ) );
	}

	/**
	 * Test whitespace around the dash separator is tolerated, mirroring the
	 * CIDR slash behavior.
	 */
	public function test_dash_range_tolerates_whitespace_around_dash() {
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.1 - 10.0.0.9' ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.1- 10.0.0.9' ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.1 -10.0.0.9' ) );
	}

	/**
	 * Test malformed dash ranges are inert: they never match and never
	 * produce a PHP warning/notice/fatal.
	 */
	public function test_malformed_dash_range_is_inert() {
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.1-' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '-10.0.0.9' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.1-banana' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '10.0.0.1-10.0.0.4-10.0.0.9' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '-' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', '999.0.0.1-10.0.0.9' ) );
	}

	/**
	 * Test a token carrying both separators is inert.
	 *
	 * `parse_ip_ranges()` checks for `/` before `-`, so these are read as
	 * malformed CIDR blocks and dropped. Pinned here because reordering that
	 * if/elseif chain would silently change how they parse — and the wizard's
	 * client-side validator flags them on the same assumption.
	 */
	public function test_token_with_both_separators_is_inert() {
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.3', '10.0.0.0/24-10.0.0.5' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.3', '10.0.0.1-10.0.0.9/24' ) );
	}

	/**
	 * Test dash ranges spanning the `ip2long()` sign boundary.
	 *
	 * `ip2long()` returns a signed int, so addresses above 127.255.255.255 are
	 * negative on 32-bit PHP builds. Ranges straddling that boundary are where
	 * a signedness mistake surfaces, as a range silently dropped as "reversed"
	 * or an IP that fails to match.
	 */
	public function test_dash_range_across_ip2long_sign_boundary() {
		$boundary_range = '127.255.255.255-128.0.0.1';
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '127.255.255.255', $boundary_range ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '128.0.0.0', $boundary_range ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '128.0.0.1', $boundary_range ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '128.0.0.2', $boundary_range ) );

		$full_range = '0.0.0.0-255.255.255.255';
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '0.0.0.0', $full_range ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '200.0.0.1', $full_range ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '255.255.255.255', $full_range ) );

		// A range straddling the boundary must not be read as reversed.
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '150.0.0.1', '10.0.0.0-200.0.0.0' ) );
	}

	/**
	 * Test every entry in the shared validation fixture is classified here the
	 * same way the wizard's client-side validator classifies it.
	 *
	 * `tests/fixtures/ip-range-validation-cases.json` is the single source of
	 * truth for both suites (see `src/wizards/audience/views/content-gates/institutions/utils.test.js`).
	 * The dangerous direction is the client calling something valid that this
	 * parser drops: the admin sees no warning and the rule silently never
	 * grants access. Shared cases turn that drift into a CI failure.
	 *
	 * @dataProvider shared_validation_case_provider
	 *
	 * @param string $entry    A single (comma-free) allowlist entry.
	 * @param bool   $is_valid Whether the parser should keep it.
	 */
	public function test_shared_validation_fixture_parity( $entry, $is_valid ) {
		$parse_ip_ranges_method = new ReflectionMethod( IP_Access_Rule::class, 'parse_ip_ranges' );
		$parse_ip_ranges_method->setAccessible( true );
		$parsed = $parse_ip_ranges_method->invoke( null, $entry );

		$this->assertCount(
			$is_valid ? 1 : 0,
			$parsed,
			sprintf( 'Entry %s should %sbe kept by parse_ip_ranges().', wp_json_encode( $entry ), $is_valid ? '' : 'not ' )
		);
	}

	/**
	 * Provide the shared client/server validation cases.
	 *
	 * Throws rather than returning an empty set: PHPUnit reports a provider with
	 * no cases as a skipped test and exits 0, so a missing file or a JSON typo
	 * would silently disarm this half of the parity guard while the jest half
	 * still fails — making a shared problem look client-only.
	 *
	 * @throws RuntimeException If the fixture is unreadable, empty, or has duplicate labels.
	 *
	 * @return array[] Keyed by case label: [ entry, is_valid ].
	 */
	public function shared_validation_case_provider() {
		$fixture_path = dirname( __DIR__, 2 ) . '/fixtures/ip-range-validation-cases.json';
		$fixture_json = file_exists( $fixture_path ) ? file_get_contents( $fixture_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local test fixture.
		$fixture      = json_decode( $fixture_json, true );
		if ( empty( $fixture['cases'] ) ) {
			throw new RuntimeException( sprintf( 'Shared validation fixture %s is missing, unreadable, or has no cases.', esc_html( $fixture_path ) ) );
		}
		$cases = [];
		foreach ( $fixture['cases'] as $case ) {
			// Cases are keyed by label here but not in jest, so a duplicate would
			// drop a case from this suite only.
			if ( isset( $cases[ $case['label'] ] ) ) {
				throw new RuntimeException( sprintf( 'Duplicate case label %s in the shared validation fixture.', esc_html( wp_json_encode( $case['label'] ) ) ) );
			}
			$cases[ $case['label'] ] = [ $case['entry'], $case['valid'] ];
		}
		return $cases;
	}

	/**
	 * Test a mixed list of CIDR, dash range, and single IP entries.
	 */
	public function test_mixed_cidr_dash_and_single_entries() {
		$ranges = '192.168.1.0/24, 203.0.113.0-203.0.113.255, 10.0.0.5';
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '192.168.1.77', $ranges ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '203.0.113.42', $ranges ) );
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', $ranges ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '172.16.0.1', $ranges ) );
	}

	/**
	 * Test an invalid entry in a list does not disable the valid entries
	 * around it.
	 */
	public function test_invalid_entry_does_not_disable_valid_neighbors() {
		$ranges = 'garbage, 203.0.113.0-203.0.113.255, 10.0.0.0/nope';
		$this->assertTrue( IP_Access_Rule::ip_matches_ranges( '203.0.113.42', $ranges ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '10.0.0.5', $ranges ) );
	}

	/**
	 * Test IPv4-mapped IPv6 notation is out of scope: the matcher is
	 * IPv4-only, so `::ffff:a.b.c.d` visitors do not match (documents the
	 * current IPv6 gap rather than asserting desired behavior).
	 */
	public function test_ipv4_mapped_ipv6_does_not_match() {
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '::ffff:10.0.0.5', '10.0.0.0/8' ) );
		$this->assertFalse( IP_Access_Rule::ip_matches_ranges( '::ffff:10.0.0.5', '10.0.0.1-10.0.0.9' ) );
	}

	/**
	 * Test the allowlist endpoint emits dash ranges in normalized
	 * (whitespace-stripped) form and drops reversed/malformed ones.
	 */
	public function test_ip_allowlist_includes_valid_dash_ranges() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$inst_id = \Newspack\Institution::create(
			'Dash Range Library',
			'',
			[ 'ip_range' => '203.0.113.0 - 203.0.113.255, 10.0.0.9-10.0.0.1, 10.0.0.1-, 192.168.1.0/24' ]
		);
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$route    = '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE_IP_ALLOWLIST;
		$request  = new WP_REST_Request( 'GET', $route );
		$response = rest_do_request( $request );

		// Assert the status first: iterating an error payload would fail with an
		// array-key notice instead of naming the permission regression.
		$this->assertSame( 200, $response->get_status() );

		$entry = null;
		foreach ( $response->get_data() as $item ) {
			if ( $item['id'] === $inst_id ) {
				$entry = $item;
				break;
			}
		}
		$this->assertNotNull( $entry );
		$this->assertSame( [ '203.0.113.0-203.0.113.255', '192.168.1.0/24' ], $entry['ip_ranges'] );
	}

	/**
	 * Test the bypass cookie lifetime is long enough to keep institutional
	 * access persistent (see the COOKIE_EXPIRATION docblock).
	 */
	public function test_bypass_cookie_lifetime_is_at_least_thirty_days() {
		$thirty_days_in_seconds = 30 * DAY_IN_SECONDS;
		$this->assertGreaterThanOrEqual( $thirty_days_in_seconds, IP_Access_Rule::COOKIE_EXPIRATION );
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

		$this->assertSame( '/target-page/', $url );

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

		$this->assertSame( '/', $url );

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

		$this->assertSame( '/', $url );
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
		$this->assertArrayNotHasKey( 'institution', $data, 'Institution name must not be disclosed to a visitor who did not match it.' );

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
	 * Test that a presence cookie ('1') is recognized by is_cookie_set().
	 */
	public function test_is_cookie_set_accepts_presence_value() {
		$_COOKIE[ IP_Access_Rule::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertTrue( IP_Access_Rule::is_cookie_set() );
		unset( $_COOKIE[ IP_Access_Rule::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Test that an absent cookie returns false from is_cookie_set().
	 */
	public function test_is_cookie_set_returns_false_when_absent() {
		unset( $_COOKIE[ IP_Access_Rule::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$this->assertFalse( IP_Access_Rule::is_cookie_set() );
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

	/**
	 * Test the loading-page check URL is host-relative.
	 *
	 * The dedicated endpoint's loading page verifies via a client-side fetch.
	 * Under a rewriting reverse proxy (e.g. a library EZproxy) an absolute URL
	 * is left unrewritten inside the inline script, so the fetch goes direct
	 * from the reader's real IP and bypasses the proxy. A host-relative URL
	 * resolves against the document origin (the proxy host) and stays proxied,
	 * so the origin sees the proxy's whitelisted IP. See NPPD-2039.
	 */
	public function test_check_url_is_host_relative() {
		$method = new ReflectionMethod( IP_Access_Rule::class, 'get_check_url' );
		$method->setAccessible( true );

		$url = $method->invoke( null, null );

		$this->assertStringStartsWith( '/', $url, 'Check URL should be host-relative.' );
		$this->assertStringNotContainsString( '://', $url, 'Check URL should not contain a scheme or host.' );
		$this->assertStringContainsString( 'institutional-access/check', $url );
	}

	/**
	 * Test the check URL carries the institution scope and stays host-relative.
	 */
	public function test_check_url_includes_institution_id() {
		$method = new ReflectionMethod( IP_Access_Rule::class, 'get_check_url' );
		$method->setAccessible( true );

		$url = $method->invoke( null, 4242 );

		$this->assertStringNotContainsString( '://', $url, 'Check URL should not contain a scheme or host.' );
		$this->assertStringContainsString( 'institution_id=4242', $url );
	}

	/**
	 * The public classifier names each entry shape. It is the single source of
	 * truth the migration CLI delegates to, so a token carrying both separators
	 * must read as a malformed CIDR (invalid), never as a dash range.
	 */
	public function test_classify_entry() {
		$this->assertSame( 'ip', IP_Access_Rule::classify_entry( '10.0.0.5' ) );
		$this->assertSame( 'cidr', IP_Access_Rule::classify_entry( '192.168.1.0/24' ) );
		$this->assertSame( 'range', IP_Access_Rule::classify_entry( '203.0.113.0-203.0.113.255' ) );
		$this->assertSame( 'range', IP_Access_Rule::classify_entry( '10.0.0.1 - 10.0.0.9' ), 'Whitespace around the dash is tolerated.' );
		$this->assertSame( 'invalid', IP_Access_Rule::classify_entry( '203.0.113.255-203.0.113.0' ), 'A reversed range is invalid.' );
		$this->assertSame( 'invalid', IP_Access_Rule::classify_entry( '10.0.0.0/24-10.0.0.5' ), 'Both separators read as a malformed CIDR.' );
		$this->assertSame( 'invalid', IP_Access_Rule::classify_entry( '2001:db8::1' ), 'IPv6 is unsupported.' );
		$this->assertSame( 'invalid', IP_Access_Rule::classify_entry( 'not-an-ip' ) );
	}

	/**
	 * Entry size lets a caller judge breadth uniformly: a /16 CIDR and the
	 * equivalent dash range report the same address count.
	 */
	public function test_get_entry_size() {
		$this->assertSame( 1.0, IP_Access_Rule::get_entry_size( '10.0.0.5' ) );
		$this->assertSame( 256.0, IP_Access_Rule::get_entry_size( '192.168.1.0/24' ) );
		$this->assertSame( 65536.0, IP_Access_Rule::get_entry_size( '128.100.0.0/16' ) );
		$this->assertSame( 256.0, IP_Access_Rule::get_entry_size( '10.0.0.0-10.0.0.255' ), 'A 256-address dash range matches its /24 equivalent.' );
		$this->assertSame( 65536.0, IP_Access_Rule::get_entry_size( '10.0.0.0-10.0.255.255' ), 'A dash range and its /16 equivalent report the same size.' );
		$this->assertSame( 2.0 ** 32, IP_Access_Rule::get_entry_size( '0.0.0.0-255.255.255.255' ), 'The whole IPv4 space does not overflow (float).' );
		$this->assertSame( 0.0, IP_Access_Rule::get_entry_size( 'not-an-ip' ), 'An invalid entry has no size.' );
	}

	/**
	 * The public normalizer accepts dash ranges (parity with the runtime check),
	 * splits on commas and newlines, canonicalizes CIDR mask bits, and surfaces
	 * invalid entries for reporting rather than dropping them silently.
	 */
	public function test_normalize_ip_ranges() {
		$result = IP_Access_Rule::normalize_ip_ranges( "192.168.1.0/24, 10.0.0.5\n203.0.113.0-203.0.113.255" );
		$this->assertSame( [ '192.168.1.0/24', '10.0.0.5', '203.0.113.0-203.0.113.255' ], $result['valid'], 'Dash ranges are kept alongside IPs and CIDR blocks.' );
		$this->assertSame( [], $result['invalid'] );

		$mixed = IP_Access_Rule::normalize_ip_ranges( [ '0.0.0.0/00', 'not-an-ip', '2001:db8::/32' ] );
		$this->assertSame( [ '0.0.0.0/0' ], $mixed['valid'], 'Leading-zero mask bits are canonicalized.' );
		$this->assertSame( [ 'not-an-ip', '2001:db8::/32' ], $mixed['invalid'], 'Invalid entries are surfaced in their trimmed original form.' );
	}

	/**
	 * Site Kit modules register their GA4/GTM tag printing on template_redirect at
	 * priority 10. The landing page renders and exits, so it must run after them —
	 * at priority 10 the page exits before the publisher's tag is registered and
	 * sends no pageview to the publisher's property. The query-param redirect
	 * emits no HTML and stays at the default priority, so other redirect handlers
	 * on template_redirect can't pre-empt the check.
	 */
	public function test_landing_page_renders_after_third_party_tag_registration() {
		$handle_landing_page_priority = has_action( 'template_redirect', [ IP_Access_Rule::class, 'handle_landing_page' ] );
		$this->assertNotFalse( $handle_landing_page_priority );
		$this->assertGreaterThan( 10, $handle_landing_page_priority );

		$handle_redirect_priority = has_action( 'template_redirect', [ IP_Access_Rule::class, 'handle_redirect' ] );
		$this->assertSame( 10, $handle_redirect_priority );
	}

	/**
	 * The landing-page request check is true only for the dedicated endpoint
	 * (the rendered loading page), not for the query-param flow on a regular URL
	 * and not for unrelated requests. Perfmatters JS delay is vetoed based on it.
	 */
	public function test_landing_page_request_detection() {
		$original_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Dedicated endpoint with an institution slug.
		set_query_var( IP_Access_Rule::ENDPOINT, '1' );
		set_query_var( IP_Access_Rule::ENDPOINT . '-slug', 'test-university' );
		$_SERVER['REQUEST_URI'] = '/institutional-access/test-university/';
		$this->assertTrue( IP_Access_Rule::is_landing_page_request() );

		// Dedicated generic endpoint.
		set_query_var( IP_Access_Rule::ENDPOINT . '-slug', '' );
		$_SERVER['REQUEST_URI'] = '/institutional-access/';
		$this->assertTrue( IP_Access_Rule::is_landing_page_request() );

		// Subdirectory install: the path carries the home path prefix, but the
		// classification must not depend on the path at all.
		$_SERVER['REQUEST_URI'] = '/blog/institutional-access/';
		$this->assertTrue( IP_Access_Rule::is_landing_page_request() );

		// Query-param flow on a regular URL: redirects, never renders the page.
		// WP mirrors the registered query var from $_GET, so both are set.
		$_GET                   = [ IP_Access_Rule::ENDPOINT => '1' ];
		$_SERVER['REQUEST_URI'] = '/some-article/?institutional-access=1';
		$this->assertFalse( IP_Access_Rule::is_landing_page_request() );
		set_query_var( IP_Access_Rule::ENDPOINT, '' );

		// Unrelated request.
		$_GET                   = [];
		$_SERVER['REQUEST_URI'] = '/some-article/';
		$this->assertFalse( IP_Access_Rule::is_landing_page_request() );

		if ( null === $original_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $original_uri;
		}
		$_GET = $original_get;
	}

	/**
	 * The loading page fires a np_institutional_access GA4 event for the outcomes
	 * that never leave the page (not_verified, timeout, error), labeled with the
	 * anonymized institution identifier used by the GA4 `group` dimension.
	 */
	public function test_loading_page_outputs_outcome_events() {
		$inst_id = \Newspack\Institution::create(
			'Events Test University',
			'',
			[ 'ip_range' => '192.168.1.0/24' ]
		);
		$this->assertIsInt( $inst_id );

		ob_start();
		IP_Access_Rule::render_loading_page( $inst_id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'np_institutional_access', $html );
		$this->assertMatchesRegularExpression( '/sendEvent\(\s*["\']not_verified["\']\s*\)/', $html );
		$this->assertMatchesRegularExpression( '/sendEvent\(\s*["\']timeout["\']\s*\)/', $html );
		$this->assertMatchesRegularExpression( '/sendEvent\(\s*["\']error["\']\s*\)/', $html );
		$this->assertDoesNotMatchRegularExpression(
			'/sendEvent\(\s*["\']connected["\']/',
			$html,
			'A success redirects and the destination page fires `connected` — firing it here too would double-count every verification.'
		);
		$this->assertStringContainsString( 'Institution ' . $inst_id, $html );

		wp_delete_post( $inst_id, true );
	}

	/**
	 * The REST check response carries the matched institution's ID, so the landing
	 * page can label the destination-page GA4 event without disclosing anything a
	 * non-matching visitor could not already see.
	 */
	public function test_rest_response_includes_institution_id() {
		$original_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		$inst_id = \Newspack\Institution::create(
			'REST ID Test Library',
			'',
			[ 'ip_range' => '192.168.1.0/24' ]
		);
		$this->assertIsInt( $inst_id );
		delete_transient( \Newspack\Institution::TRANSIENT_KEY );

		$_SERVER['REMOTE_ADDR'] = '192.168.1.50'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		$request = new WP_REST_Request( 'GET', '/' . NEWSPACK_API_NAMESPACE . IP_Access_Rule::REST_ROUTE );
		$request->set_param( 'institution_id', $inst_id );
		$response = @rest_do_request( $request ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- nocache_headers cannot send headers in tests.
		$data     = $response->get_data();

		$this->assertTrue( $data['valid'] );
		$this->assertSame( $inst_id, $data['institution_id'] );

		// A non-matching visitor gets no institution_id, mirroring the name rule.
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		$response               = @rest_do_request( $request ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- nocache_headers cannot send headers in tests.
		$this->assertArrayNotHasKey( 'institution_id', $response->get_data() );

		if ( null === $original_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		} else {
			$_SERVER['REMOTE_ADDR'] = $original_addr; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
		}
		wp_delete_post( $inst_id, true );
	}

	/**
	 * The query-param flow's redirect URL carries the matched institution's ID, so
	 * the destination page can label its GA4 event.
	 */
	public function test_result_redirect_url_includes_institution_id() {
		$original_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$inst_id = \Newspack\Institution::create(
			'Redirect Test University',
			'',
			[ 'ip_range' => '192.168.1.0/24' ]
		);
		$this->assertIsInt( $inst_id );

		$_SERVER['REQUEST_URI'] = '/some-article/?institutional-access=1';
		$_GET                   = [ IP_Access_Rule::ENDPOINT => '1' ];

		$get_result_redirect_url_method = new ReflectionMethod( IP_Access_Rule::class, 'get_result_redirect_url' );
		$get_result_redirect_url_method->setAccessible( true );

		$success_url = $get_result_redirect_url_method->invoke( null, $inst_id );
		$this->assertStringContainsString( IP_Access_Rule::RESULT_PARAM . '=success', $success_url );
		$this->assertStringContainsString( 'institution-id=' . $inst_id, $success_url );

		// A failed re-check on a URL still carrying a previous success's
		// institution params must not pass them through — the destination page
		// would label its not_verified event with an institution the visitor
		// did not match.
		$_GET = [
			IP_Access_Rule::ENDPOINT => '1',
			'institution'            => 'Stale University',
			'institution-id'         => '123',
		];
		$failure_url = $get_result_redirect_url_method->invoke( null, false );
		$this->assertStringContainsString( IP_Access_Rule::RESULT_PARAM . '=failure', $failure_url );
		$this->assertStringNotContainsString( 'institution-id', $failure_url );
		$this->assertStringNotContainsString( 'Stale', $failure_url );

		if ( null === $original_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $original_uri;
		}
		$_GET = $original_get;
		wp_delete_post( $inst_id, true );
	}

	/**
	 * The destination page fires the redirect-borne outcome as a GA4 event: the
	 * result notice handler registers a footer printer whose script sends
	 * np_institutional_access with the action and institution from the URL params.
	 * Redirect outcomes fire only here — the loading page does not also send a
	 * `connected` event, so a success is never double-counted.
	 */
	public function test_result_notice_prints_ga_event() {
		$original_get    = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$original_cookie = $_COOKIE; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE

		$_GET = [
			IP_Access_Rule::RESULT_PARAM => 'success',
			'institution-id'             => '37',
		];
		IP_Access_Rule::handle_result_notice();
		$this->assertNotFalse( has_action( 'wp_footer', [ IP_Access_Rule::class, 'print_result_event' ] ) );

		// Without the bypass cookie the success param is a shared or
		// hand-crafted URL, and no event may be injected from it.
		unset( $_COOKIE[ IP_Access_Rule::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		ob_start();
		IP_Access_Rule::print_result_event();
		$this->assertSame( '', ob_get_clean() );

		$_COOKIE[ IP_Access_Rule::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		ob_start();
		IP_Access_Rule::print_result_event();
		$success_script = ob_get_clean();
		$this->assertStringContainsString( 'np_institutional_access', $success_script );
		$this->assertStringContainsString( '"connected"', $success_script );
		$this->assertStringContainsString( 'Institution 37', $success_script );
		$this->assertStringContainsString( 'replaceState', $success_script, 'The result params must be stripped from the URL, or a reload or shared link fires another event.' );

		// A failure sets no cookie, so its event is not cookie-gated.
		unset( $_COOKIE[ IP_Access_Rule::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		$_GET = [
			IP_Access_Rule::RESULT_PARAM => 'failure',
		];
		ob_start();
		IP_Access_Rule::print_result_event();
		$failure_script = ob_get_clean();
		$this->assertStringContainsString( '"not_verified"', $failure_script );
		$this->assertStringNotContainsString( 'Institution ', $failure_script );

		$_GET    = $original_get;
		$_COOKIE = $original_cookie; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}
}
