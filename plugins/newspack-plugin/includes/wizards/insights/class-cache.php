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

/**
 * Insights cache helper.
 */
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
	 * Read a cached envelope from the per-tab transient, or null when no
	 * usable entry is present.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string[] $key_parts Canonicalized window components.
	 * @return array{ payload: array, computed_at: string, source: string }|null
	 */
	private static function read_cached( string $tab, array $key_parts ): ?array {
		$cached = get_transient( self::transient_key( $tab, $key_parts ) );
		if ( is_array( $cached ) && isset( $cached['payload'], $cached['computed_at'], $cached['source'] ) ) {
			return $cached;
		}
		return null;
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

	/**
	 * Force a recompute. Returns the new envelope. When BQ refresh is throttled
	 * by the cooldown, returns the previously-cached envelope (or an empty one)
	 * with `cooldown_until` populated, so the response transport stays 2xx.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string   $source    SOURCE_* constant.
	 * @param string[] $key_parts Canonicalized window components.
	 * @param callable $compute   () => array — orchestrator payload.
	 * @return array
	 */
	public static function refresh(
		string $tab,
		string $source,
		array $key_parts,
		callable $compute
	): array {
		if ( self::is_disabled() ) {
			return self::envelope( (array) $compute(), $source );
		}

		if ( self::SOURCE_BIGQUERY === $source ) {
			$until = self::bq_cooldown_until( $tab );
			if ( null !== $until ) {
				self::log_cooldown( $tab, $until );
				$cached = self::read_cached( $tab, $key_parts );
				if ( null !== $cached ) {
					return [
						'payload'        => $cached['payload'],
						'computed_at'    => $cached['computed_at'],
						'source'         => $cached['source'],
						'cooldown_until' => $until,
					];
				}
				// No prior cache to serve — return an empty envelope with the cooldown
				// marker so the client can still render the throttle UI.
				return [
					'payload'        => [],
					'computed_at'    => null,
					'source'         => $source,
					'cooldown_until' => $until,
				];
			}
			update_option( self::cooldown_option( $tab ), time(), false );
		}

		if ( self::SOURCE_LOCAL !== $source ) {
			delete_transient( self::transient_key( $tab, $key_parts ) );
		}

		$payload  = (array) $compute();
		$envelope = self::envelope( $payload, $source );

		if ( self::SOURCE_LOCAL !== $source ) {
			$store = [
				'payload'     => $envelope['payload'],
				'computed_at' => $envelope['computed_at'],
				'source'      => $envelope['source'],
			];
			set_transient( self::transient_key( $tab, $key_parts ), $store, self::ttl_for( $source ) );
			self::index_add( $tab, self::transient_key( $tab, $key_parts ) );
		}

		return $envelope;
	}

	/**
	 * ISO 8601 timestamp at which the BQ manual-refresh cooldown for $tab ends,
	 * or null if no cooldown is currently active.
	 *
	 * @param string $tab Tab slug.
	 * @return string|null
	 */
	public static function bq_cooldown_until( string $tab ): ?string {
		$last = (int) get_option( self::cooldown_option( $tab ), 0 );
		if ( 0 === $last ) {
			return null;
		}
		$until = $last + self::BQ_COOLDOWN_SECONDS;
		if ( $until <= time() ) {
			return null;
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $until );
	}

	/**
	 * Option name holding the last manual-refresh Unix timestamp for $tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function cooldown_option( string $tab ): string {
		return 'newspack_insights_bq_last_manual_refresh_' . $tab;
	}

	/**
	 * Log a cooldown-rejected refresh via Newspack Logger if available.
	 *
	 * @param string $tab   Tab slug.
	 * @param string $until ISO 8601 cooldown end.
	 */
	private static function log_cooldown( string $tab, string $until ): void {
		if ( ! class_exists( '\Newspack\Logger' ) ) {
			return;
		}
		\Newspack\Logger::newspack_log(
			'newspack_insights_cache_cooldown',
			sprintf( '[%s] manual refresh throttled until %s', $tab, $until ),
			[
				'tab'            => $tab,
				'cooldown_until' => $until,
				'header'         => self::LOGGER_HEADER,
			],
			'warning'
		);
	}

	/**
	 * Clear every cached window for a tab and reset its BQ cooldown marker.
	 * No-op when caching is disabled.
	 *
	 * @param string $tab Tab slug.
	 */
	public static function purge( string $tab ): void {
		if ( self::is_disabled() ) {
			return;
		}
		$option = self::index_option( $tab );
		$keys   = get_option( $option, [] );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}
		delete_option( $option );
		delete_option( self::cooldown_option( $tab ) );
	}
}
