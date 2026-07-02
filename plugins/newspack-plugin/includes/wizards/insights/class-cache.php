<?php
/**
 * Newspack Insights — Cache helper.
 *
 * Source-typed transient wrapper used by the Insights REST controllers.
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

	const SOURCE_SNAPSHOT = 'snapshot';

	/**
	 * Global envelope cache-schema version, folded into every Insights window
	 * cache key (durable + transient) via Cached_Controller_Trait. Bump this on
	 * ANY Insights window-payload shape change so a shape-changing deploy cannot
	 * serve an old-shaped durable/transient payload to the new frontend. A bump
	 * busts every tab's window cache at once (one cold pre-warm cycle).
	 */
	const ENVELOPE_SCHEMA_VERSION = 'v1';

	const TTL_SNAPSHOT = 9 * DAY_IN_SECONDS;

	const TTL_DURABLE_FRESH = 25 * HOUR_IN_SECONDS;

	const TTL_BIGQUERY        = DAY_IN_SECONDS;
	const TTL_EXTERNAL        = 10 * MINUTE_IN_SECONDS;
	const BQ_COOLDOWN_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * Max number of transient keys retained per tab in the index. Older
	 * entries are dropped FIFO when the cap is exceeded; the underlying
	 * transients still expire naturally on their TTL — losing the index
	 * ref just means purge() won't delete them explicitly.
	 */
	const INDEX_MAX_ENTRIES = 200;

	/**
	 * Max on-demand durable entries retained per tab. Non-preset windows (custom
	 * ranges) are cached here lazily on a compute-miss so they survive memcached
	 * eviction; FIFO eviction (move-to-newest on rewrite) bounds wp_options growth.
	 */
	const ONDEMAND_MAX_ENTRIES = 10;

	const LOGGER_HEADER = 'NEWSPACK-INSIGHTS-CACHE';

	/**
	 * Disable all server-side caching when the escape-hatch constant is on.
	 */
	public static function is_disabled(): bool {
		return defined( 'NEWSPACK_INSIGHTS_CACHE_DISABLED' ) && NEWSPACK_INSIGHTS_CACHE_DISABLED;
	}

	/**
	 * Store-or-compute for one window. For SOURCE_LOCAL this is a pure
	 * pass-through. Read precedence: preset durable → on-demand durable (fresh)
	 * → envelope transient → live compute. A live compute for a windowed
	 * BigQuery-proxy source (when $window is supplied) write-throughs to the
	 * on-demand pool so the window survives memcached eviction; a stale
	 * on-demand entry is recomputed inline and overwritten.
	 *
	 * @param string     $tab       Tab slug.
	 * @param string     $source    SOURCE_* constant.
	 * @param string[]   $key_parts Canonicalized window components.
	 * @param callable   $compute   () => array — orchestrator payload.
	 * @param array|null $window    [ 'start' => 'Y-m-d', 'end' => 'Y-m-d' ]. When
	 *                              supplied for a BigQuery-proxy source, this window
	 *                              participates in the on-demand pool — consulted on
	 *                              read and written through on a live compute. Null
	 *                              (non-windowed callers) skips the on-demand pool
	 *                              entirely (neither read nor written).
	 * @return array{ payload: array, computed_at: string, source: string, cooldown_until: ?string }
	 */
	public static function store( string $tab, string $source, array $key_parts, callable $compute, ?array $window = null ): array {
		if ( self::SOURCE_LOCAL === $source || self::is_disabled() ) {
			return self::envelope( (array) $compute(), $source );
		}

		$cooldown_until = self::SOURCE_BIGQUERY === $source ? self::bq_cooldown_until( $tab ) : null;

		// Preset durable pool (pre-warm owned). Stale entries still serve
		// instantly; the SWR action schedules an async background refresh.
		$durable = self::peek_durable( $tab, $source, $key_parts );
		if ( null !== $durable ) {
			if ( ! self::is_fresh( $durable['computed_at'] ) ) {
				do_action(
					'newspack_insights_durable_stale',
					$tab,
					(string) ( $durable['window']['start'] ?? '' ),
					(string) ( $durable['window']['end'] ?? '' )
				);
			}
			return [
				'payload'        => $durable['payload'],
				'computed_at'    => $durable['computed_at'],
				'source'         => $durable['source'],
				'cooldown_until' => $cooldown_until,
			];
		}

		// On-demand durable pool (windowed BigQuery-proxy sources only). A fresh
		// entry serves instantly; a stale one is recomputed inline below and
		// overwritten. When the caller passes no window (or a non-windowed source),
		// the pool is neither read nor written — symmetric with the write-through
		// gate below, so $window = null truly opts out of on-demand caching.
		$ondemand_eligible = null !== $window
			&& ( self::SOURCE_EXTERNAL === $source || self::SOURCE_BIGQUERY === $source );
		$ondemand       = $ondemand_eligible ? self::peek_ondemand( $tab, $source, $key_parts ) : null;
		$ondemand_stale = null !== $ondemand && ! self::is_fresh( $ondemand['computed_at'] );
		if ( null !== $ondemand && ! $ondemand_stale ) {
			return [
				'payload'        => $ondemand['payload'],
				'computed_at'    => $ondemand['computed_at'],
				'source'         => $ondemand['source'],
				'cooldown_until' => $cooldown_until,
			];
		}

		// Envelope transient — consulted only on a true miss (not when refreshing
		// a stale on-demand entry, which must recompute).
		if ( null === $ondemand ) {
			$key    = self::transient_key( $tab, $key_parts );
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['payload'], $cached['computed_at'], $cached['source'] ) ) {
				return [
					'payload'        => $cached['payload'],
					'computed_at'    => $cached['computed_at'],
					'source'         => $cached['source'],
					'cooldown_until' => $cooldown_until,
				];
			}
		}

		$payload  = (array) $compute();
		$envelope = self::envelope( $payload, $source );

		$store = [
			'payload'     => $envelope['payload'],
			'computed_at' => $envelope['computed_at'],
			'source'      => $envelope['source'],
		];
		set_transient( self::transient_key( $tab, $key_parts ), $store, self::ttl_for( $source ) );
		if ( self::SOURCE_SNAPSHOT !== $source ) {
			self::index_add( $tab, self::transient_key( $tab, $key_parts ) );
		}

		// Write-through / refresh the on-demand pool (same eligibility as the read).
		if ( $ondemand_eligible ) {
			self::store_ondemand( $tab, $source, $key_parts, $envelope['payload'], $window );
		}

		$envelope['cooldown_until'] = $cooldown_until;
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
	 * Read a cached envelope WITHOUT computing on miss. Returns the stored
	 * `{ payload, computed_at, source }` array, or null when nothing usable is
	 * cached, caching is disabled, or the cached envelope's source does not
	 * match the requested $source. The read-only primitive the snapshot
	 * pre-warm pattern needs: request-path callers peek and, on null, schedule a
	 * background refresh rather than computing an expensive payload inline.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string   $source    SOURCE_* constant. Must match the stored envelope's source.
	 * @param string[] $key_parts Canonicalized key components.
	 * @return array{ payload: array, computed_at: string, source: string }|null
	 */
	public static function peek( string $tab, string $source, array $key_parts ): ?array {
		if ( self::is_disabled() ) {
			return null;
		}
		$cached = self::read_cached( $tab, $key_parts );
		if ( null === $cached || $cached['source'] !== $source ) {
			return null;
		}
		return $cached;
	}

	/**
	 * Durable option name for a warmed window. Mirrors transient_key() but in
	 * the wp_options namespace, so pre-warmed presets survive memcached eviction.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string[] $key_parts Canonicalized window components.
	 * @return string
	 */
	private static function durable_option( string $tab, array $key_parts ): string {
		return 'newspack_insights_warm_' . $tab . '_' . md5( (string) wp_json_encode( $key_parts ) );
	}

	/**
	 * Per-tab durable warm index option name.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function durable_index_option( string $tab ): string {
		return 'newspack_insights_warm_index_' . $tab;
	}

	/**
	 * Write a pre-warmed window to durable (non-autoloaded) storage and record
	 * its key in the per-tab warm index. No storage TTL — freshness is logical
	 * (computed_at + is_fresh()). No-op write when caching is disabled; the
	 * envelope is still returned so callers behave uniformly.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string   $source    SOURCE_* constant.
	 * @param string[] $key_parts Canonicalized window components.
	 * @param array    $payload   The window payload.
	 * @param array    $window    [ 'start' => 'Y-m-d', 'end' => 'Y-m-d' ].
	 * @return array{ payload: array, computed_at: string, source: string, cooldown_until: null }
	 */
	public static function store_durable( string $tab, string $source, array $key_parts, array $payload, array $window ): array {
		$envelope = self::envelope( $payload, $source );
		if ( self::is_disabled() ) {
			return $envelope;
		}
		$store = [
			'payload'     => $envelope['payload'],
			'computed_at' => $envelope['computed_at'],
			'source'      => $envelope['source'],
			'window'      => $window,
		];
		update_option( self::durable_option( $tab, $key_parts ), $store, false );
		self::durable_index_add( $tab, $key_parts );
		return $envelope;
	}

	/**
	 * Read a durable warm entry without computing. Returns the stored
	 * { payload, computed_at, source, window } or null when disabled, absent,
	 * malformed, or the stored source does not match $source.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string   $source    SOURCE_* constant the caller expects.
	 * @param string[] $key_parts Canonicalized window components.
	 * @return array{ payload: array, computed_at: string, source: string, window: array }|null
	 */
	public static function peek_durable( string $tab, string $source, array $key_parts ): ?array {
		if ( self::is_disabled() ) {
			return null;
		}
		$stored = get_option( self::durable_option( $tab, $key_parts ), null );
		if ( ! is_array( $stored ) || ! isset( $stored['payload'], $stored['computed_at'], $stored['source'] ) ) {
			return null;
		}
		if ( $stored['source'] !== $source ) {
			return null;
		}
		// A durable entry with a missing or empty window cannot be SWR-refreshed:
		// on_durable_stale() bails when start/end are empty, so the entry would be
		// served forever-stale with no way to refresh. Return null to fall through
		// to a live recompute rather than serving an unserviceable entry.
		if (
			! isset( $stored['window'] ) ||
			! is_array( $stored['window'] ) ||
			empty( $stored['window']['start'] ) ||
			empty( $stored['window']['end'] )
		) {
			return null;
		}
		return $stored;
	}

	/**
	 * Whether an ISO 8601 UTC timestamp is within the durable freshness window.
	 *
	 * @param string $computed_at ISO 8601 UTC timestamp.
	 * @return bool
	 */
	public static function is_fresh( string $computed_at ): bool {
		$ts = strtotime( $computed_at );
		if ( false === $ts ) {
			return false;
		}
		return ( time() - $ts ) <= self::TTL_DURABLE_FRESH;
	}

	/**
	 * Add a key to the per-tab durable warm index.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string[] $key_parts Canonicalized window components.
	 */
	private static function durable_index_add( string $tab, array $key_parts ): void {
		$option = self::durable_index_option( $tab );
		$keys   = get_option( $option, [] );
		if ( ! is_array( $keys ) ) {
			$keys = [];
		}
		$hash = md5( (string) wp_json_encode( $key_parts ) );
		if ( ! in_array( $hash, $keys, true ) ) {
			$keys[] = $hash;
			update_option( $option, $keys, false );
		}
	}

	/**
	 * Delete durable warm options for $tab whose key is not in $keep_key_parts,
	 * and rewrite the index to the kept set. Bounds durable option growth as the
	 * daily-shifting presets move (yesterday's Last-30 window becomes orphaned).
	 * No-op when caching is disabled.
	 *
	 * @param string  $tab            Tab slug.
	 * @param array[] $keep_key_parts List of key_parts arrays to retain.
	 */
	public static function prune_durable( string $tab, array $keep_key_parts ): void {
		if ( self::is_disabled() ) {
			return;
		}
		$keep_hashes = array_map(
			static fn( array $parts ): string => md5( (string) wp_json_encode( $parts ) ),
			$keep_key_parts
		);
		$option = self::durable_index_option( $tab );
		$hashes = get_option( $option, [] );
		if ( ! is_array( $hashes ) ) {
			$hashes = [];
		}
		foreach ( $hashes as $hash ) {
			if ( ! in_array( $hash, $keep_hashes, true ) ) {
				delete_option( 'newspack_insights_warm_' . $tab . '_' . $hash );
			}
		}
		// Rewrite the index as the intersection of what was already indexed and
		// what the caller wants to keep. This is self-consistent: an entry that
		// was never stored (e.g. a phantom versioned hash computed incorrectly)
		// cannot survive a prune cycle.
		$surviving = array_values( array_intersect( $hashes, $keep_hashes ) );
		update_option( $option, $surviving, false );
	}

	/**
	 * On-demand durable option name for a lazily-cached window.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string[] $key_parts Canonicalized window components.
	 * @return string
	 */
	private static function ondemand_option( string $tab, array $key_parts ): string {
		return 'newspack_insights_ondemand_' . $tab . '_' . md5( (string) wp_json_encode( $key_parts ) );
	}

	/**
	 * Per-tab on-demand index option name.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function ondemand_index_option( string $tab ): string {
		return 'newspack_insights_ondemand_index_' . $tab;
	}

	/**
	 * Write a window to the on-demand (non-autoloaded) durable pool and record its
	 * key in the per-tab FIFO index. No storage TTL — freshness is logical
	 * (computed_at + is_fresh()). No-op write when caching is disabled; the
	 * envelope is still returned so callers behave uniformly.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string   $source    SOURCE_* constant.
	 * @param string[] $key_parts Canonicalized window components.
	 * @param array    $payload   The window payload.
	 * @param array    $window    [ 'start' => 'Y-m-d', 'end' => 'Y-m-d' ].
	 * @return array{ payload: array, computed_at: string, source: string, cooldown_until: null }
	 */
	public static function store_ondemand( string $tab, string $source, array $key_parts, array $payload, array $window ): array {
		$envelope = self::envelope( $payload, $source );
		if ( self::is_disabled() ) {
			return $envelope;
		}
		$store = [
			'payload'     => $envelope['payload'],
			'computed_at' => $envelope['computed_at'],
			'source'      => $envelope['source'],
			'window'      => $window,
		];
		update_option( self::ondemand_option( $tab, $key_parts ), $store, false );
		self::ondemand_index_add( $tab, $key_parts );
		return $envelope;
	}

	/**
	 * Read an on-demand entry without computing. Same contract as peek_durable():
	 * null when disabled, absent, malformed, source-mismatched, or window-empty.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string   $source    SOURCE_* constant the caller expects.
	 * @param string[] $key_parts Canonicalized window components.
	 * @return array{ payload: array, computed_at: string, source: string, window: array }|null
	 */
	public static function peek_ondemand( string $tab, string $source, array $key_parts ): ?array {
		if ( self::is_disabled() ) {
			return null;
		}
		$stored = get_option( self::ondemand_option( $tab, $key_parts ), null );
		if ( ! is_array( $stored ) || ! isset( $stored['payload'], $stored['computed_at'], $stored['source'] ) ) {
			return null;
		}
		if ( $stored['source'] !== $source ) {
			return null;
		}
		if (
			! isset( $stored['window'] ) ||
			! is_array( $stored['window'] ) ||
			empty( $stored['window']['start'] ) ||
			empty( $stored['window']['end'] )
		) {
			return null;
		}
		return $stored;
	}

	/**
	 * Add a key to the per-tab on-demand FIFO index (move-to-newest on rewrite),
	 * evicting the oldest option(s) when the cap is exceeded.
	 *
	 * @param string   $tab       Tab slug.
	 * @param string[] $key_parts Canonicalized window components.
	 */
	private static function ondemand_index_add( string $tab, array $key_parts ): void {
		$option = self::ondemand_index_option( $tab );
		$hashes = get_option( $option, [] );
		if ( ! is_array( $hashes ) ) {
			$hashes = [];
		}
		$hash = md5( (string) wp_json_encode( $key_parts ) );
		// Move-to-newest: drop any existing occurrence, then append.
		$hashes   = array_values( array_filter( $hashes, static fn( $h ) => $h !== $hash ) );
		$hashes[] = $hash;
		$count    = count( $hashes );
		while ( $count > self::ONDEMAND_MAX_ENTRIES ) {
			$evicted = array_shift( $hashes );
			delete_option( 'newspack_insights_ondemand_' . $tab . '_' . $evicted );
			--$count;
		}
		update_option( $option, $hashes, false );
	}

	/**
	 * Delete every on-demand option for a tab and reset its index.
	 *
	 * @param string $tab Tab slug.
	 */
	public static function purge_ondemand( string $tab ): void {
		$option = self::ondemand_index_option( $tab );
		$hashes = get_option( $option, [] );
		if ( is_array( $hashes ) ) {
			foreach ( $hashes as $hash ) {
				delete_option( 'newspack_insights_ondemand_' . $tab . '_' . $hash );
			}
		}
		delete_option( $option );
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
		if ( self::SOURCE_SNAPSHOT === $source ) {
			return self::TTL_SNAPSHOT;
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
			// Cap the index so users churning the date picker don't bloat
			// wp_options. FIFO drops the oldest refs — their transients
			// still expire on their own TTL; purge() simply won't sweep
			// them explicitly.
			if ( count( $keys ) > self::INDEX_MAX_ENTRIES ) {
				$keys = array_slice( $keys, -self::INDEX_MAX_ENTRIES );
			}
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
	 * @param string     $tab       Tab slug.
	 * @param string     $source    SOURCE_* constant.
	 * @param string[]   $key_parts Canonicalized window components.
	 * @param callable   $compute   () => array — orchestrator payload.
	 * @param array|null $window    Optional window array with 'start' and 'end' keys.
	 * @return array
	 */
	public static function refresh(
		string $tab,
		string $source,
		array $key_parts,
		callable $compute,
		?array $window = null
	): array {
		if ( self::is_disabled() ) {
			return self::envelope( (array) $compute(), $source );
		}

		$key = self::SOURCE_LOCAL === $source ? null : self::transient_key( $tab, $key_parts );

		if ( self::SOURCE_BIGQUERY === $source ) {
			$until = self::bq_cooldown_until( $tab );
			if ( null !== $until ) {
				self::log_cooldown( $tab, $until );
				/**
				 * Fires when a manual BQ refresh is rejected by the active
				 * cooldown. Telemetry hook for tracking throttle frequency.
				 *
				 * @since 0.0.0
				 *
				 * @param string $tab   Tab slug whose refresh was blocked.
				 * @param string $until ISO 8601 timestamp when the cooldown ends.
				 */
				do_action( 'newspack_insights_cache_cooldown_blocked', $tab, $until );
				$cached = self::read_cached( $tab, $key_parts );
				if ( null !== $cached ) {
					return [
						'payload'        => $cached['payload'],
						'computed_at'    => $cached['computed_at'],
						'source'         => $cached['source'],
						'cooldown_until' => $until,
					];
				}
				// No prior cache to serve — return a null payload (rather than an
				// empty array, which is truthy in JS and trips JSX destructuring).
				// The client preserves any prior slot data when payload is null;
				// the cooldown marker still surfaces so the throttle UI renders.
				return [
					'payload'        => null,
					'computed_at'    => null,
					'source'         => $source,
					'cooldown_until' => $until,
				];
			}
		}

		// Run compute BEFORE setting the cooldown or writing the transient so a
		// throw from the orchestrator (e.g. a BQ-proxy 500) doesn't burn the
		// cooldown window OR wipe the prior cached envelope. The React layer
		// (insightsCache.refresh) is already defensive against an error
		// response — it preserves prior slot data.
		$payload = (array) $compute();

		if ( self::SOURCE_BIGQUERY === $source ) {
			update_option( self::cooldown_option( $tab ), time(), false );
		}

		$envelope = self::envelope( $payload, $source );

		if ( null !== $key ) {
			$store = [
				'payload'     => $envelope['payload'],
				'computed_at' => $envelope['computed_at'],
				'source'      => $envelope['source'],
			];
			// set_transient() overwrites in place — no need to delete_transient() first.
			set_transient( $key, $store, self::ttl_for( $source ) );
			if ( self::SOURCE_SNAPSHOT !== $source ) {
				self::index_add( $tab, $key );
			}

			// Keep the durable pools in sync with the manually-refreshed data so
			// the read-precedence path (preset → on-demand → transient → compute)
			// returns fresh data on the next request. Preset entries are synced in
			// place; on-demand entries are synced or (for a windowed source with a
			// supplied window) created — a refreshed custom range becomes durable.
			$existing_durable = self::peek_durable( $tab, $source, $key_parts );
			if ( null !== $existing_durable ) {
				self::store_durable( $tab, $source, $key_parts, $envelope['payload'], $existing_durable['window'] );
			}
			$existing_ondemand = self::peek_ondemand( $tab, $source, $key_parts );
			if ( null !== $existing_ondemand ) {
				self::store_ondemand( $tab, $source, $key_parts, $envelope['payload'], $existing_ondemand['window'] );
			} elseif (
				null === $existing_durable &&
				null !== $window &&
				( self::SOURCE_EXTERNAL === $source || self::SOURCE_BIGQUERY === $source )
			) {
				self::store_ondemand( $tab, $source, $key_parts, $envelope['payload'], $window );
			}
		}

		// BigQuery refreshes always come back with the active cooldown stamp
		// so the React layer can render the throttle UI from the very first
		// refresh response, not just the second click.
		if ( self::SOURCE_BIGQUERY === $source ) {
			$envelope['cooldown_until'] = self::bq_cooldown_until( $tab );
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
