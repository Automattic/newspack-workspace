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
}
