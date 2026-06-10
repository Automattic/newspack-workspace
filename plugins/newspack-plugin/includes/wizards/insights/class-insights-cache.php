<?php
/**
 * Newspack Insights — Cache helper.
 *
 * Source-typed transient wrapper used by the Insights REST controllers.
 * See ~/Documents/AI/plans/2026-06-10-insights-caching-design.md.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

defined( 'ABSPATH' ) || exit;

final class Cache {

	const SOURCE_BIGQUERY = 'bigquery';
	const SOURCE_EXTERNAL = 'external';
	const SOURCE_LOCAL    = 'local';

	const TTL_BIGQUERY        = DAY_IN_SECONDS;
	const TTL_EXTERNAL        = 10 * MINUTE_IN_SECONDS;
	const BQ_COOLDOWN_SECONDS = 10 * MINUTE_IN_SECONDS;

	const LOGGER_HEADER = 'NEWSPACK-INSIGHTS-CACHE';

	/**
	 * Disable all server-side caching when the escape-hatch constant is on.
	 */
	public static function is_disabled(): bool {
		return defined( 'NEWSPACK_INSIGHTS_CACHE_DISABLED' ) && NEWSPACK_INSIGHTS_CACHE_DISABLED;
	}

	/**
	 * Store-or-compute. For SOURCE_LOCAL this is a pure pass-through.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string   $source    SOURCE_* constant.
	 * @param string[] $key_parts Canonicalized window components.
	 * @param callable $compute   () => array — orchestrator payload.
	 * @return array{ payload: array, computed_at: string, source: string, cooldown_until: ?string }
	 */
	public static function store( string $tab, string $source, array $key_parts, callable $compute ): array {
		if ( self::SOURCE_LOCAL === $source || self::is_disabled() ) {
			return self::envelope( (array) $compute(), $source );
		}

		$key    = self::transient_key( $tab, $key_parts );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['payload'], $cached['computed_at'], $cached['source'] ) ) {
			return [
				'payload'        => $cached['payload'],
				'computed_at'    => $cached['computed_at'],
				'source'         => $cached['source'],
				'cooldown_until' => null,
			];
		}

		$payload  = (array) $compute();
		$envelope = self::envelope( $payload, $source );

		$store = [
			'payload'     => $envelope['payload'],
			'computed_at' => $envelope['computed_at'],
			'source'      => $envelope['source'],
		];
		set_transient( $key, $store, self::ttl_for( $source ) );
		self::index_add( $tab, $key );

		return $envelope;
	}

	/**
	 * Generate the transient key for a given tab and key parts.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string[] $key_parts Canonicalized window components.
	 * @return string Transient key.
	 */
	private static function transient_key( string $tab, array $key_parts ): string {
		return 'newspack_insights_' . $tab . '_' . md5( (string) wp_json_encode( $key_parts ) );
	}

	/**
	 * Get the TTL for a given source.
	 *
	 * @param string $source SOURCE_* constant.
	 * @return int TTL in seconds.
	 */
	private static function ttl_for( string $source ): int {
		if ( self::SOURCE_BIGQUERY === $source ) {
			return self::TTL_BIGQUERY;
		}
		return self::TTL_EXTERNAL;
	}

	/**
	 * Get the option name for the transient index of a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string Option name.
	 */
	private static function index_option( string $tab ): string {
		return 'newspack_insights_index_' . $tab;
	}

	/**
	 * Add a transient key to the index for a tab.
	 *
	 * @param string $tab Tab slug.
	 * @param string $key Transient key.
	 */
	private static function index_add( string $tab, string $key ): void {
		$keys = get_option( self::index_option( $tab ), [] );
		if ( ! is_array( $keys ) ) {
			$keys = [];
		}
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::index_option( $tab ), $keys, false );
		}
	}

	/**
	 * Build the cache envelope.
	 *
	 * @param array       $payload        The orchestrator payload.
	 * @param string      $source         SOURCE_* constant.
	 * @param string|null $cooldown_until Optional cooldown-until marker.
	 * @return array{ payload: array, computed_at: string, source: string, cooldown_until: ?string }
	 */
	private static function envelope( array $payload, string $source, ?string $cooldown_until = null ): array {
		return [
			'payload'        => $payload,
			'computed_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'source'         => $source,
			'cooldown_until' => $cooldown_until,
		];
	}
}
