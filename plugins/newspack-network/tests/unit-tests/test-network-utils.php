<?php
/**
 * Class TestNetworkUtils
 *
 * @package Newspack_Network
 */

use Newspack_Network\Utils\Network;

/**
 * Test the sideload URL guards on Utils\Network.
 *
 * Peer avatar and thumbnail URLs are fetched server-side via media_sideload_image,
 * which runs through wp_safe_remote_get. That blocks RFC1918 and loopback but not
 * the 169.254.0.0/16 cloud-metadata range, so the guard has to reject that range
 * itself. These tests use literal-IP URLs so they resolve no DNS and stay hermetic.
 */
class TestNetworkUtils extends WP_UnitTestCase {

	/**
	 * The cloud-metadata range is the gap core leaves open; it must be blocked.
	 */
	public function test_metadata_range_ip_is_blocked() {
		$this->assertTrue( Network::is_blocked_sideload_ip( '169.254.169.254' ) );
	}

	/**
	 * Loopback and the RFC1918 private ranges are blocked too.
	 */
	public function test_private_and_loopback_ips_are_blocked() {
		$this->assertTrue( Network::is_blocked_sideload_ip( '127.0.0.1' ), 'loopback' );
		$this->assertTrue( Network::is_blocked_sideload_ip( '10.0.0.1' ), '10/8' );
		$this->assertTrue( Network::is_blocked_sideload_ip( '172.16.0.1' ), '172.16/12' );
		$this->assertTrue( Network::is_blocked_sideload_ip( '192.168.1.1' ), '192.168/16' );
	}

	/**
	 * A routable public address is not blocked.
	 */
	public function test_public_ip_is_not_blocked() {
		$this->assertFalse( Network::is_blocked_sideload_ip( '8.8.8.8' ) );
		$this->assertFalse( Network::is_blocked_sideload_ip( '93.184.216.34' ) );
	}

	/**
	 * A malformed address fails closed (treated as blocked).
	 */
	public function test_malformed_ip_fails_closed() {
		$this->assertTrue( Network::is_blocked_sideload_ip( 'not-an-ip' ) );
	}

	/**
	 * The metadata payload form from the report is rejected, fragment and all —
	 * the exact URL wp_http_validate_url alone would let through.
	 */
	public function test_metadata_url_is_rejected() {
		$this->assertFalse( Network::is_safe_sideload_url( 'http://169.254.169.254/latest/meta-data/#x.jpg' ) );
	}

	/**
	 * A sideload URL pointing at a routable public IP is allowed.
	 */
	public function test_public_url_is_allowed() {
		$this->assertTrue( Network::is_safe_sideload_url( 'http://93.184.216.34/avatar.jpg' ) );
	}

	/**
	 * Non-strings, malformed URLs and non-http(s) schemes are rejected without error.
	 */
	public function test_non_url_input_is_rejected() {
		$this->assertFalse( Network::is_safe_sideload_url( '' ), 'empty string' );
		$this->assertFalse( Network::is_safe_sideload_url( 'not a url' ), 'not a url' );
		$this->assertFalse( Network::is_safe_sideload_url( 'ftp://169.254.169.254/x.jpg' ), 'non-http scheme' );
		$this->assertFalse( Network::is_safe_sideload_url( null ), 'null' );
	}

	/**
	 * The CGNAT and benchmarking ranges PHP's filter flags omit are blocked, and addresses
	 * just outside them are not.
	 */
	public function test_cgnat_and_benchmarking_ranges_are_blocked() {
		$this->assertTrue( Network::is_blocked_sideload_ip( '100.64.0.1' ), '100.64/10 low' );
		$this->assertTrue( Network::is_blocked_sideload_ip( '100.100.100.200' ), 'Alibaba metadata' );
		$this->assertTrue( Network::is_blocked_sideload_ip( '100.127.255.255' ), '100.64/10 high' );
		$this->assertFalse( Network::is_blocked_sideload_ip( '100.128.0.1' ), 'just above 100.64/10' );
		$this->assertTrue( Network::is_blocked_sideload_ip( '198.18.0.1' ), '198.18/15 low' );
		$this->assertTrue( Network::is_blocked_sideload_ip( '198.19.255.255' ), '198.18/15 high' );
		$this->assertFalse( Network::is_blocked_sideload_ip( '198.20.0.1' ), 'just above 198.18/15' );
	}

	/**
	 * IPv6 loopback, link-local and unique-local addresses are blocked; a public v6 is not.
	 */
	public function test_ipv6_private_and_reserved_are_blocked() {
		$this->assertTrue( Network::is_blocked_sideload_ip( '::1' ), 'loopback' );
		$this->assertTrue( Network::is_blocked_sideload_ip( 'fe80::1' ), 'link-local' );
		$this->assertTrue( Network::is_blocked_sideload_ip( 'fd00::1' ), 'unique-local' );
		$this->assertFalse( Network::is_blocked_sideload_ip( '2606:4700:4700::1111' ), 'public v6' );
	}

	/**
	 * A hostname that resolves to nothing is refused, which is the branch every
	 * IP-literal test skips. Inverting the fail-closed check in resolve_host()'s caller
	 * leaves the rest of this file green, so without this the branch is unpinned.
	 *
	 * Hermetic: RFC 2606 reserves .invalid, so it is NXDOMAIN everywhere, and a container
	 * with no resolver at all lands on the same assertion.
	 */
	public function test_unresolvable_host_is_refused() {
		$this->assertFalse( Network::is_safe_sideload_url( 'http://no-such-host.invalid/x.jpg' ) );
	}

	/**
	 * A resolved address a network deliberately allows can be opted back in, without
	 * loosening anything else. Networks on private addressing answer
	 * `http_request_host_is_external` to permit their own hosts, which this guard would
	 * otherwise override, costing them avatar and thumbnail sync.
	 */
	public function test_blocked_ip_filter_can_opt_an_address_back_in() {
		$allow_one = function ( $blocked, $ip ) {
			return '10.0.0.5' === $ip ? false : $blocked;
		};

		$this->assertTrue( Network::is_blocked_sideload_ip( '10.0.0.5' ), 'Blocked by default.' );

		add_filter( 'newspack_network_blocked_sideload_ip', $allow_one, 10, 2 );
		$allowed  = Network::is_blocked_sideload_ip( '10.0.0.5' );
		$metadata = Network::is_blocked_sideload_ip( '169.254.169.254' );
		remove_filter( 'newspack_network_blocked_sideload_ip', $allow_one, 10 );

		$this->assertFalse( $allowed, 'The filter opts the named address back in.' );
		$this->assertTrue( $metadata, 'Everything else stays refused.' );
	}

	/**
	 * The v4-in-v6 spellings of a blocked address are blocked too. PHP's reserved-range
	 * flags cover ::ffff:0:0/96 and nothing else, so NAT64 and 6to4 forms of
	 * 169.254.169.254 would otherwise pass.
	 *
	 * @dataProvider v4_in_v6_provider
	 *
	 * @param string $ip       Address under test.
	 * @param bool   $expected Whether it must be refused.
	 * @param string $label    What the address is.
	 */
	public function test_v4_in_v6_forms_are_blocked( $ip, $expected, $label ) {
		$this->assertSame( $expected, Network::is_blocked_sideload_ip( $ip ), $label );
	}

	/**
	 * Addresses for test_v4_in_v6_forms_are_blocked().
	 *
	 * @return array[]
	 */
	public function v4_in_v6_provider() {
		return [
			'ipv4-mapped metadata' => [ '::ffff:169.254.169.254', true, 'IPv4-mapped 169.254.169.254' ],
			'nat64 metadata'       => [ '64:ff9b::a9fe:a9fe', true, 'NAT64 169.254.169.254' ],
			'6to4 metadata'        => [ '2002:a9fe:a9fe::', true, '6to4 169.254.169.254' ],
			'public v6 unaffected' => [ '2606:4700:4700::1111', false, 'A public v6 address' ],
		];
	}

	/**
	 * The guard is registered on the hook by the production code path, not just reachable
	 * when a test registers it by hand.
	 *
	 * Every other redirect test writes the hook name itself, so renaming it in
	 * sideload_peer_image() would leave them green while the redirect guard did nothing on
	 * a real site. This drives a genuine sideload and reads has_action() from inside the
	 * request, then aborts before any network I/O.
	 *
	 * The URL is a literal IP for the same reason the rest of the class uses one: a host
	 * that cannot resolve is refused by is_safe_sideload_url() before the hook is ever
	 * registered, so the interceptor never runs and every assertion below passes on a probe
	 * that did not happen. The assertNotNull guards against that, and fails rather than
	 * passing quietly.
	 */
	public function test_sideload_registers_the_redirect_guard_on_the_real_hook() {
		$registered = null;

		$intercept = function ( $preempt, $args, $url ) use ( &$registered ) {
			$registered = has_action(
				'requests-requests.before_redirect', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
				[ Network::class, 'assert_safe_redirect' ]
			);
			return new \WP_Error( 'test_intercepted', 'Aborted before any network I/O.' );
		};

		add_filter( 'pre_http_request', $intercept, 10, 3 );
		Network::sideload_peer_image( 'https://93.184.216.34/x.jpg', 0, null, 'id' );
		remove_filter( 'pre_http_request', $intercept, 10 );

		$this->assertNotNull( $registered, 'The sideload must reach the HTTP layer, or this test asserts nothing.' );
		$this->assertNotFalse( $registered, 'The sideload must register the guard on the hook Requests fires.' );

		$this->assertFalse(
			has_action(
				'requests-requests.before_redirect', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
				[ Network::class, 'assert_safe_redirect' ]
			),
			'And must remove it again once the sideload returns.'
		);
	}

	/**
	 * The peer sideload refuses an unsafe initial URL up front, returning a WP_Error rather
	 * than making the request.
	 */
	public function test_sideload_peer_image_refuses_unsafe_initial_url() {
		$result = Network::sideload_peer_image( 'http://169.254.169.254/x.jpg', 0, null, 'id' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_network_unsafe_sideload_url', $result->get_error_code() );
	}

	/**
	 * The redirect-hop validator throws on a target in a blocked range. This is the callback
	 * Requests invokes before following each 302 during a peer sideload, so a redirect to the
	 * metadata address is aborted rather than fetched.
	 */
	public function test_redirect_validator_refuses_internal_target() {
		// The thrown class is version-dependent (Requests_Exception below WP 6.2), matching
		// the production guard; assert whichever the running core provides.
		$expected = class_exists( \WpOrg\Requests\Exception::class ) ? \WpOrg\Requests\Exception::class : \Requests_Exception::class;
		$this->expectException( $expected );
		Network::assert_safe_redirect( 'http://169.254.169.254/latest/meta-data/#x.jpg' );
	}

	/**
	 * The redirect-hop validator lets a public target through, so legitimate images behind a
	 * redirect still load.
	 */
	public function test_redirect_validator_allows_public_target() {
		$this->expectNotToPerformAssertions();
		Network::assert_safe_redirect( 'http://93.184.216.34/x.jpg' );
	}

	/**
	 * The validator is wired to the hook core's own HTTP stack fires on each redirect. Drive
	 * that bridge (WP_HTTP_Requests_Hooks::dispatch) the way WP_Http does and assert the
	 * validator is reached and its exception propagates — this is what makes the redirect
	 * refusal real rather than a hook registered on a name nothing fires.
	 */
	public function test_before_redirect_bridge_reaches_validator() {
		$hooks    = new \WP_HTTP_Requests_Hooks( 'https://example.test/', [] );
		$location = 'http://169.254.169.254/x.jpg';

		add_action( 'requests-requests.before_redirect', [ Network::class, 'assert_safe_redirect' ] );
		$threw = false;
		try {
			$hooks->dispatch( 'requests.before_redirect', [ &$location ] );
		} catch ( \Exception $e ) {
			// Both the namespaced (WP 6.2+) and legacy Requests_Exception extend \Exception.
			$threw = true;
		} finally {
			remove_action( 'requests-requests.before_redirect', [ Network::class, 'assert_safe_redirect' ] );
		}

		$this->assertTrue( $threw, 'The before_redirect bridge must reach the validator and propagate its exception.' );
	}
}
