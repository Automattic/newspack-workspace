<?php
/**
 * Newspack Network Utils methods.
 *
 * @package Newspack
 */

namespace Newspack_Network\Utils;

use Newspack_Network\Hub\Node as Hub_Node;
use Newspack_Network\Hub\Nodes as Hub_Nodes;
use Newspack_Network\Node\Settings;
use Newspack_Network\Site_Role;

/**
 * Network.
 */
class Network {
	/**
	 * Get all networked URLs - excluding url of the site where the function is called.
	 *
	 * Note that all urls have been run through untrailingslashit.
	 *
	 * @return array Array of networked URLs.
	 */
	public static function get_networked_urls(): array {
		if ( Site_Role::is_hub() ) {
			return array_map( fn( $node ) => untrailingslashit( $node->get_url() ), Hub_Nodes::get_all_nodes() );
		}
		$urls = [
			Settings::get_hub_url(),
			...array_map( fn( $node ) => $node['url'], get_option( Hub_Node::HUB_NODES_SYNCED_OPTION, [] ) ),
		];

		// Filter out empty values and apply untrailingslashit.
		return array_map( 'untrailingslashit', array_filter( $urls ) );
	}

	/**
	 * Check if a URL is networked.
	 *
	 * @param string $url URL to check.
	 *
	 * @return bool True if the URL is networked, false otherwise.
	 */
	public static function is_networked_url( string $url ): bool {
		return in_array( untrailingslashit( $url ), self::get_networked_urls(), true );
	}

	/**
	 * Get list of network URLs given a list of URLs.
	 *
	 * @param string[] $urls Array of URLs.
	 *
	 * @return string[] Array of networked URLs.
	 */
	public static function get_networked_urls_from_list( array $urls ): array {
		return array_intersect( array_map( 'untrailingslashit', $urls ), self::get_networked_urls() );
	}

	/**
	 * Get list of URLs that don't belong in the network given a list of URLs.
	 *
	 * @param string[] $urls Array of URLs.
	 *
	 * @return string[] Array of networked URLs.
	 */
	public static function get_non_networked_urls_from_list( array $urls ): array {
		return array_diff( array_map( 'untrailingslashit', $urls ), self::get_networked_urls() );
	}

	/**
	 * Whether a peer-supplied URL is safe to fetch server-side with media_sideload_image.
	 *
	 * The sideload runs through wp_safe_remote_get, which blocks RFC1918 and loopback
	 * but not 169.254.0.0/16 — the cloud-metadata range — so a peer could otherwise point
	 * this site at an internal address (SSRF). This adds the reserved-range check core
	 * omits, and resolves hostnames so a name pointing at a blocked address is refused as
	 * well as a literal IP.
	 *
	 * IPv6 note: literal addresses are range-checked, but hostnames are only resolved over
	 * IPv4 (gethostbynamel). A hostname whose sole AAAA record is link-local is out of
	 * scope for this guard, which is acceptable for a blind vector against an IPv4 endpoint.
	 *
	 * @param mixed $url Candidate URL from the network payload.
	 *
	 * @return bool True only for a valid external http(s) URL clear of private/reserved ranges.
	 */
	public static function is_safe_sideload_url( $url ): bool {
		// wp_http_validate_url enforces the http(s) scheme, the allowed ports, and core's
		// own RFC1918/loopback checks; the reserved-range check below covers what it misses.
		if ( ! is_string( $url ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		// A bracketed IPv6 literal arrives with its brackets; strip them before validating.
		$host = trim( $host, '[]' );

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = [ $host ];
		} else {
			$ips = gethostbynamel( $host );
			if ( false === $ips ) {
				// Unresolvable host: fail closed rather than let the fetch resolve it later.
				return false;
			}
		}

		foreach ( $ips as $ip ) {
			if ( self::is_blocked_sideload_ip( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an IP address falls in a range we refuse to sideload from.
	 *
	 * Covers loopback, the RFC1918 private ranges and the reserved ranges — including
	 * 169.254.0.0/16, which wp_safe_remote_get does not block. Kept I/O-free and separate
	 * from is_safe_sideload_url() so the range logic is unit-testable without DNS. A value
	 * that is not a valid IP is treated as blocked, so the guard fails closed.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 *
	 * @return bool True if the address is private or reserved and must be refused.
	 */
	public static function is_blocked_sideload_ip( string $ip ): bool {
		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}
