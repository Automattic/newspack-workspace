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
		$this->expectException( \WpOrg\Requests\Exception::class );
		Network::assert_safe_redirect( 'http://169.254.169.254/latest/meta-data/#x.jpg' );
	}

	/**
	 * The redirect-hop validator lets a public target through, so legitimate images behind a
	 * redirect still load.
	 */
	public function test_redirect_validator_allows_public_target() {
		Network::assert_safe_redirect( 'http://93.184.216.34/x.jpg' );
		$this->assertTrue( true, 'A public redirect target must not throw.' );
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
		} catch ( \WpOrg\Requests\Exception $e ) {
			$threw = true;
		} finally {
			remove_action( 'requests-requests.before_redirect', [ Network::class, 'assert_safe_redirect' ] );
		}

		$this->assertTrue( $threw, 'The before_redirect bridge must reach the validator and propagate its exception.' );
	}
}
