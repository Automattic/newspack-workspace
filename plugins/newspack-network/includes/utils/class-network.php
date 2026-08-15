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
	 * Sideload a peer-supplied image, refusing the initial URL or any redirect hop that
	 * resolves into a blocked range.
	 *
	 * The fetch (media_sideload_image() -> download_url()) follows redirects inside the bundled
	 * Requests library, which never re-enters WP_Http::request() — so a pre_http_request or
	 * http_request_args filter would only ever see the initial URL. Core re-validates each
	 * hop through the Requests before_redirect bridge, but with wp_http_validate_url(), the
	 * same weak check this guard exists to strengthen, so on older WordPress a 302 to
	 * 169.254.169.254 still lands. This registers on that bridge and re-checks every redirect
	 * target with is_safe_sideload_url(), and validates the initial URL up front so the
	 * wrapper holds even if a caller skips its own pre-check. Legitimate images behind a
	 * redirect still load; only hops into a blocked range are stopped.
	 *
	 * @param string      $url     External image URL.
	 * @param int         $post_id Parent post ID (0 for none).
	 * @param string|null $desc    Image description.
	 * @param string      $return  media_sideload_image return type ('html', 'src' or 'id').
	 *
	 * @return int|string|\WP_Error Whatever media_sideload_image returns; WP_Error if a hop is refused.
	 */
	public static function sideload_peer_image( string $url, int $post_id = 0, ?string $desc = null, string $return = 'html' ) {
		if ( ! self::is_safe_sideload_url( $url ) ) {
			return new \WP_Error( 'newspack_network_unsafe_sideload_url', __( 'Refused a sideload request to a private or reserved address.', 'newspack-network' ) );
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Requests fires this bridge action before following each redirect; the callback
		// throws on an unsafe target, which Requests turns into a WP_Error for the caller.
		add_action( 'requests-requests.before_redirect', [ __CLASS__, 'assert_safe_redirect' ] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		try {
			return media_sideload_image( $url, $post_id, $desc, $return );
		} finally {
			remove_action( 'requests-requests.before_redirect', [ __CLASS__, 'assert_safe_redirect' ] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
	}

	/**
	 * Refuse a redirect whose target resolves into a private or reserved range.
	 *
	 * Registered on the Requests before_redirect bridge during a peer sideload. Throwing
	 * aborts the redirect the way core's own reject_unsafe_urls check does, but through
	 * is_safe_sideload_url(), which blocks the ranges core misses on older WordPress.
	 *
	 * @param string $location Redirect target URL.
	 *
	 * @throws \WpOrg\Requests\Exception If the target is unsafe, on WordPress 6.2+.
	 * @throws \Requests_Exception       If the target is unsafe, on WordPress < 6.2.
	 */
	public static function assert_safe_redirect( $location ) {
		if ( is_string( $location ) && ! self::is_safe_sideload_url( $location ) ) {
			// The namespaced Requests exception only exists on WordPress 6.2+, but this guard
			// protects older versions too, where the class is Requests_Exception. Throw whichever
			// the running core provides so WP_Http catches it and returns a WP_Error, rather than
			// fataling with class-not-found on the exact versions we target.
			if ( class_exists( '\WpOrg\Requests\Exception' ) ) {
				throw new \WpOrg\Requests\Exception( esc_html__( 'Refused a sideload redirect to a private or reserved address.', 'newspack-network' ), 'newspack_network_unsafe_sideload_redirect' );
			}
			throw new \Requests_Exception( esc_html__( 'Refused a sideload redirect to a private or reserved address.', 'newspack-network' ), 'newspack_network_unsafe_sideload_redirect' );
		}
	}

	/**
	 * Whether a peer-supplied URL is safe to fetch server-side with media_sideload_image.
	 *
	 * The sideload runs through wp_safe_remote_get, which blocks RFC1918 and loopback
	 * but not 169.254.0.0/16 — the cloud-metadata range — so a peer could otherwise point
	 * this site at an internal address (SSRF). This adds the reserved-range check core
	 * omits, and resolves hostnames (both A and AAAA) so a name pointing at a blocked
	 * address is refused as well as a literal IP.
	 *
	 * Residual: this validates the address at resolve time, and the fetch re-resolves at
	 * connect time, so a peer that controls a short-TTL record could rebind between the two
	 * (a DNS-rebinding window core shares and does not close either). Closing it fully needs
	 * pinning the connection to the validated IP, which is out of scope for this helper.
	 *
	 * @param mixed $url Candidate URL from the network payload.
	 *
	 * @return bool True only for a valid external http(s) URL clear of private/reserved ranges.
	 */
	public static function is_safe_sideload_url( $url ): bool {
		// wp_http_validate_url enforces the http(s) scheme, the allowed ports, and core's
		// own RFC1918/loopback checks, and rejects any host containing ':' — so IPv6-literal
		// URLs never reach the resolution below. The reserved-range check covers what it misses.
		if ( ! is_string( $url ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		// A bracketed IPv6 literal would arrive with its brackets; strip them before
		// validating. This is defensive, since wp_http_validate_url already rejects such hosts.
		$host = trim( $host, '[]' );

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = [ $host ];
		} else {
			$ips = self::resolve_host( $host );
			if ( empty( $ips ) ) {
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
	 * Resolve a hostname to every IPv4 and IPv6 address it advertises.
	 *
	 * Both families are needed: a host with a public A record and an internal AAAA record
	 * (::1, fe80::, fd00::) would otherwise clear an IPv4-only check while happy-eyeballs
	 * connects over v6.
	 *
	 * @param string $host Hostname to resolve.
	 *
	 * @return string[] Resolved IP addresses; empty if the host resolves to none.
	 */
	private static function resolve_host( string $host ): array {
		$ips = gethostbynamel( $host );
		$ips = ( false === $ips ) ? [] : $ips;

		// Peers control the hostname, so an unresolvable one would emit an E_WARNING per event;
		// the empty/false return is handled fail-closed below, so the warning carries nothing.
		$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $aaaa ) ) {
			foreach ( $aaaa as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return $ips;
	}

	/**
	 * Whether an IP address falls in a range we refuse to sideload from.
	 *
	 * Covers loopback, the RFC1918 private ranges and the reserved ranges — including
	 * 169.254.0.0/16, which wp_safe_remote_get does not block — plus two ranges PHP's
	 * filter flags omit: RFC6598 CGNAT (100.64.0.0/10, which reaches some cloud metadata
	 * endpoints) and RFC2544 benchmarking (198.18.0.0/15). Kept I/O-free and separate from
	 * is_safe_sideload_url() so the range logic is unit-testable without DNS. A value that
	 * is not a valid IP is treated as blocked, so the guard fails closed.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 *
	 * @return bool True if the address is private or reserved and must be refused.
	 */
	public static function is_blocked_sideload_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		foreach ( [ '100.64.0.0/10', '198.18.0.0/15' ] as $cidr ) {
			if ( self::ipv4_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IPv4 address sits inside a CIDR block. Non-IPv4 input returns false.
	 *
	 * @param string $ip   IP address to test.
	 * @param string $cidr CIDR block, e.g. '100.64.0.0/10'.
	 *
	 * @return bool True if $ip is IPv4 and within $cidr.
	 */
	private static function ipv4_in_cidr( string $ip, string $cidr ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}
		list( $subnet, $bits ) = explode( '/', $cidr );
		$mask = -1 << ( 32 - (int) $bits );
		return ( ip2long( $ip ) & $mask ) === ( ip2long( $subnet ) & $mask );
	}
}
