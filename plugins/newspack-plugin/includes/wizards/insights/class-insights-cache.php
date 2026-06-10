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

		// TTL paths land in a later task — until then, fall through to compute.
		return self::envelope( (array) $compute(), $source );
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
