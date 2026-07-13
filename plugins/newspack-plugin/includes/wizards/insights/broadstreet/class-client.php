<?php
/**
 * Newspack Insights — Broadstreet reporting client (NPPD-2045).
 *
 * Generic, metric-agnostic primitives for reading Broadstreet ad reporting that
 * power Tab 8 (Advertising) when Broadstreet — rather than Google Ad Manager —
 * is the site's active ad server. The Advertising metric orchestrator
 * ({@see \Newspack\Insights\Advertising_Metric}) builds metric-specific reports
 * on top of the single {@see self::report()} primitive.
 *
 * Unlike GAM (async SOAP report jobs), Broadstreet exposes a synchronous REST
 * rollup: a single `GET /api/1/records?type=custom` returns every advertiser (or
 * zone) row for the whole network over a date range in one call — no fan-out. So
 * this client is a thin `wp_remote_get` wrapper; the orchestrator computes the
 * Broadstreet window synchronously (no Action Scheduler).
 *
 * IMPORTANT — read before modifying:
 *
 *  - The Broadstreet v1 API has NO revenue / RPM / eCPM / cost anywhere
 *    (confirmed live). Broadstreet is an impressions-side provider only; every
 *    revenue-derived Tab 8 metric is GAM-only.
 *  - Credentials come from the Broadstreet plugin via newspack-ads' guarded
 *    accessors ({@see \Broadstreet_Utility::getApiKey()} / `getNetworkId()`).
 *    Every access is `class_exists`/`method_exists`-guarded so Insights never
 *    hard-depends on the Broadstreet plugin being installed.
 *  - Insights owns the HTTP call here (via `wp_remote_get`); the newspack-ads
 *    Broadstreet provider is used only for the active-server detection signal.
 *
 * @package Newspack
 */

namespace Newspack\Insights\Broadstreet;

use Newspack\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Broadstreet reporting client.
 */
final class Client {

	/**
	 * Broadstreet API base (v1).
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.broadstreetads.com/api/1';

	/**
	 * Logger header for this client.
	 *
	 * @var string
	 */
	const LOGGER_HEADER = 'NEWSPACK-INSIGHTS-BROADSTREET';

	/**
	 * Whether Broadstreet is the site's active ad server. Prefers the detection
	 * newspack-ads already ships ({@see \Newspack_Ads\Providers\Broadstreet_Provider::is_active()}
	 * — plugin present + API key + network id); falls back to replicating that
	 * check directly against the Broadstreet plugin's utility when the provider
	 * class isn't available. Guarded so Insights never hard-depends on either
	 * plugin being loaded. Local/cheap — no remote calls.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		if ( class_exists( '\Newspack_Ads\Providers\Broadstreet_Provider' ) ) {
			return ( new \Newspack_Ads\Providers\Broadstreet_Provider() )->is_active();
		}
		// Fallback: replicate the provider's own is_active() check.
		return '' !== self::get_access_token() && '' !== self::get_network_id();
	}

	/**
	 * The Broadstreet network id, or '' when unavailable.
	 *
	 * @return string
	 */
	public static function get_network_id(): string {
		if ( class_exists( '\Broadstreet_Utility' ) && method_exists( '\Broadstreet_Utility', 'getNetworkId' ) ) {
			return (string) \Broadstreet_Utility::getNetworkId();
		}
		return '';
	}

	/**
	 * The Broadstreet API access token (key), or '' when unavailable.
	 *
	 * @return string
	 */
	public static function get_access_token(): string {
		if ( class_exists( '\Broadstreet_Utility' ) && method_exists( '\Broadstreet_Utility', 'getApiKey' ) ) {
			return (string) \Broadstreet_Utility::getApiKey();
		}
		return '';
	}

	/**
	 * Run a network-rollup records report. One synchronous call returns every
	 * grouped row for the whole network over the date range.
	 *
	 * @param string   $group  Grouping dimension: network|advertiser|zone|campaign.
	 * @param string[] $select Fields to select, e.g. [ 'advertiser.name', 'count(view)', 'count(click)' ].
	 *                         `count(view)` is impressions; `count(click)` is clicks.
	 * @param string   $start  Inclusive window start, YYYY-MM-DD.
	 * @param string   $end    Inclusive window end, YYYY-MM-DD.
	 * @return array<int,array<string,mixed>> The `records` rows, or [] on any failure (degrade).
	 */
	public static function report( string $group, array $select, string $start, string $end ): array {
		$token      = self::get_access_token();
		$network_id = self::get_network_id();
		if ( '' === $token || '' === $network_id ) {
			return [];
		}

		// add_query_arg() URL-encodes each value exactly once, so the `select` list's
		// parens and commas (count(view),count(click),advertiser.name) are encoded
		// safely. Do NOT pre-encode with rawurlencode() — that double-encodes and
		// breaks the request.
		$url = add_query_arg(
			[
				'access_token' => $token,
				'type'         => 'custom',
				'network_id'   => $network_id,
				'select'       => implode( ',', $select ),
				'group'        => $group,
				'start_date'   => $start,
				'end_date'     => $end,
			],
			self::API_BASE . '/records'
		);

		$decoded = self::request( $url );
		if ( ! is_array( $decoded ) || ! isset( $decoded['records'] ) || ! is_array( $decoded['records'] ) ) {
			return [];
		}
		return $decoded['records'];
	}

	/**
	 * The HTTP boundary: fetch a Broadstreet API URL and JSON-decode it. Isolated
	 * so the network-touching path is the single point that reads the wire (and so
	 * it can be stubbed in isolation). Returns the decoded array, or null on any
	 * transport / non-200 / decode failure — the caller degrades to [] rather than
	 * surfacing an error, so a Broadstreet outage never breaks the tab.
	 *
	 * @param string $url The fully-built request URL.
	 * @return array<string,mixed>|null Decoded JSON, or null on failure.
	 */
	protected static function request( string $url ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Broadstreet reporting reads hit the publisher's own ad server off a transient-cached path; a slightly longer timeout tolerates a cold rollup, and any failure degrades to an empty result rather than blocking.
		$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
		if ( is_wp_error( $response ) ) {
			if ( class_exists( '\Newspack\Logger' ) ) {
				Logger::error( 'Broadstreet API request failed: ' . $response->get_error_message(), self::LOGGER_HEADER );
			}
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			if ( class_exists( '\Newspack\Logger' ) ) {
				Logger::error( 'Broadstreet API returned a non-200 response.', self::LOGGER_HEADER );
			}
			return null;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
