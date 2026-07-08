<?php
/**
 * Newspack Insights — Cached_Controller_Trait.
 *
 * Wraps a REST controller's window-bound payload in the Insights cache
 * envelope and registers a sibling POST /{tab}/refresh route.
 *
 * @package Newspack
 */

namespace Newspack\Insights;

use DateTimeImmutable;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

trait Cached_Controller_Trait {

	/**
	 * Source classification for this controller's data.
	 */
	abstract protected function cache_source(): string;

	/**
	 * Stable tab slug used as the cache namespace.
	 */
	abstract protected function tab_slug(): string;

	/**
	 * Global envelope cache-schema version mixed into the cache key for every
	 * Insights controller. Returns {@see Cache::ENVELOPE_SCHEMA_VERSION} by
	 * default so ALL tabs are versioned uniformly — no per-tab opt-in required.
	 * Override only when a single tab needs an independent bump that must NOT
	 * bust the rest of the pre-warm cycle.
	 */
	protected function cache_schema_version(): string {
		return Cache::ENVELOPE_SCHEMA_VERSION;
	}

	/**
	 * Base-window payload builder — same shape as a no-comparison GET. Each
	 * controller implements it by delegating to its existing build_response()
	 * with null comparison.
	 *
	 * @param DateTimeImmutable $start Window start (00:00:00, site tz).
	 * @param DateTimeImmutable $end   Window end (23:59:59, site tz).
	 * @return array
	 */
	abstract public function build_window_payload( DateTimeImmutable $start, DateTimeImmutable $end ): array;

	/**
	 * Build a base window payload and stamp the NEWS-2603 top-level
	 * `data_status` (`complete` | `warming` | `incomplete`) derived from every
	 * nested metric-scalar `state`.
	 *
	 * Centralized here — on the single path every cached/refreshed/pre-warmed
	 * current window flows through — rather than in each controller's
	 * build_response(). Deriving it once makes it structurally impossible for a
	 * tab to bump its schema version yet ship an envelope without `data_status`
	 * (the omission that left the Donors banner, auto-refetch, and escalation
	 * inert). It also feeds the cache's provisional-payload detection, so a
	 * warming window gets the short TTL and skips the durable pools uniformly
	 * across store(), refresh(), and the pre-warm path.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array The window payload with a top-level `data_status`.
	 */
	protected function status_stamped_window_payload( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$payload                = $this->build_window_payload( $start, $end );
		$payload['data_status'] = Metric_Status::derive( $payload );
		return $payload;
	}

	/**
	 * Build and durably store one pre-warmed base window. Uses the controller's
	 * own tab/source/versioned key so the entry key-matches the GET read path.
	 *
	 * Returns the versioned key parts the entry was stored under, so the
	 * pre-warm caller can build the prune keep-list from the exact key written
	 * (single source of truth — avoids re-computing key parts in a separate
	 * code path that may diverge from cache_schema_version()).
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array Versioned key parts passed to Cache::store_durable().
	 */
	public function warm_window( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$key_parts = $this->versioned_key_parts_from(
			$start->format( 'Y-m-d' ),
			$end->format( 'Y-m-d' ),
			null,
			null
		);
		Cache::store_durable(
			$this->tab_slug(),
			$this->cache_source(),
			$key_parts,
			$this->status_stamped_window_payload( $start, $end ),
			[
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->format( 'Y-m-d' ),
			]
		);
		return $key_parts;
	}

	/**
	 * Compute the versioned durable key parts for a window WITHOUT warming.
	 *
	 * The no-warm sibling of warm_window(): returns the same key array that
	 * warm_window() stores under, so the pre-warm prune step can build the
	 * current-preset keep-list without issuing any BigQuery query.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array Versioned key parts (same shape as warm_window() return).
	 */
	public function durable_key_for( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return $this->versioned_key_parts_from(
			$start->format( 'Y-m-d' ),
			$end->format( 'Y-m-d' ),
			null,
			null
		);
	}

	/**
	 * Compare-stripped versioned key for a single window — the key the pre-warm
	 * and durable/on-demand pools store under.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	private function base_key_parts( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return $this->versioned_key_parts_from( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), null, null );
	}

	/**
	 * Per-window map: [ 'start' => 'Y-m-d', 'end' => 'Y-m-d' ] for the on-demand pool.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	private function window_map( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return [
			'start' => $start->format( 'Y-m-d' ),
			'end'   => $end->format( 'Y-m-d' ),
		];
	}

	/**
	 * Graft the previous window's payload into the current-window envelope as the
	 * comparison data. Default: expose the previous window's metrics at top-level
	 * `previous`. Controllers whose response embeds comparison data in additional
	 * (non-top-level) slots override this to fill those too.
	 *
	 * @param array $current  Current-window base payload (its `previous` is null).
	 * @param array $previous Previous-window base payload (its `current` holds the prior metrics).
	 * @return array The current payload with comparison data grafted in.
	 */
	protected function graft_previous( array $current, array $previous ): array {
		$current['previous'] = $previous['current'] ?? null;
		return $current;
	}

	/**
	 * Cache-aware GET. The CURRENT window is resolved through the durable/on-demand
	 * pools under a comparison-stripped base key (so it hits the pre-warmed preset
	 * entry, or a lazily-cached custom window). When comparison is on, the PREVIOUS
	 * window is computed live via build_window_payload() (sharing the metric-layer
	 * transient) and grafted in. Comparison parameters never enter a cache key.
	 *
	 * @param WP_REST_Request        $request       Incoming request.
	 * @param DateTimeImmutable      $start         Current window start.
	 * @param DateTimeImmutable      $end           Current window end.
	 * @param DateTimeImmutable|null $compare_start Previous window start, or null.
	 * @param DateTimeImmutable|null $compare_end   Previous window end, or null.
	 */
	protected function cached_response(
		WP_REST_Request $request,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?DateTimeImmutable $compare_start,
		?DateTimeImmutable $compare_end
	): WP_REST_Response {
		$current_env = Cache::store(
			$this->tab_slug(),
			$this->cache_source(),
			$this->base_key_parts( $start, $end ),
			fn() => $this->status_stamped_window_payload( $start, $end ),
			$this->window_map( $start, $end )
		);

		$payload = $current_env['payload'];
		if ( null !== $compare_start && null !== $compare_end ) {
			$previous = $this->build_window_payload( $compare_start, $compare_end );
			$payload  = $this->graft_previous( $payload, $previous );
		}

		$response = rest_ensure_response(
			self::wrap_envelope(
				[
					'source'         => $current_env['source'],
					'computed_at'    => $current_env['computed_at'],
					'cooldown_until' => $current_env['cooldown_until'],
					'payload'        => $payload,
				]
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * POST /{tab}/refresh. Force-recomputes the CURRENT window (consuming the
	 * per-tab BQ cooldown and syncing its durable/on-demand entry); the PREVIOUS
	 * window is served via cache-or-compute. Always returns a 200 envelope.
	 *
	 * @param WP_REST_Request        $request       Incoming request.
	 * @param DateTimeImmutable      $start         Current window start.
	 * @param DateTimeImmutable      $end           Current window end.
	 * @param DateTimeImmutable|null $compare_start Previous window start, or null.
	 * @param DateTimeImmutable|null $compare_end   Previous window end, or null.
	 */
	protected function refresh_response(
		WP_REST_Request $request,
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		?DateTimeImmutable $compare_start,
		?DateTimeImmutable $compare_end
	): WP_REST_Response {
		$current_env = Cache::refresh(
			$this->tab_slug(),
			$this->cache_source(),
			$this->base_key_parts( $start, $end ),
			fn() => $this->status_stamped_window_payload( $start, $end ),
			$this->window_map( $start, $end )
		);

		$payload = $current_env['payload'];
		if ( null !== $current_env['payload'] && null !== $compare_start && null !== $compare_end ) {
			$previous = $this->build_window_payload( $compare_start, $compare_end );
			$payload  = $this->graft_previous( $payload, $previous );
		}

		$response = rest_ensure_response(
			self::wrap_envelope(
				[
					'source'         => $current_env['source'],
					'computed_at'    => $current_env['computed_at'],
					'cooldown_until' => $current_env['cooldown_until'],
					'payload'        => $payload,
				]
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Pure cache-key derivation from raw window strings, with the response-shape
	 * version prepended when the controller sets one.
	 *
	 * @param string      $start Window start (Y-m-d).
	 * @param string      $end   Window end (Y-m-d).
	 * @param string|null $cs    Comparison start or null.
	 * @param string|null $ce    Comparison end or null.
	 * @return array
	 */
	private function versioned_key_parts_from( string $start, string $end, ?string $cs, ?string $ce ): array {
		$parts   = [ $start, $end, $cs, $ce ];
		$version = $this->cache_schema_version();
		if ( '' !== $version ) {
			array_unshift( $parts, $version );
		}
		return $parts;
	}

	/**
	 * Canonical window key parts with the response-shape version prepended when
	 * the controller sets one. An empty version leaves the window parts
	 * untouched, so a non-overriding controller's cache key is unchanged.
	 *
	 * NOTE — truthiness coupling: empty-string compare_start / compare_end are
	 * treated as absent here (falsy → null) to match the behaviour of
	 * Insights_REST_Trait::parse_window_args(), which treats a falsy param as
	 * "no comparison window". Both sides must change together if either switches
	 * to isset() / !== '' checks; otherwise cache keys and parsed comparison
	 * windows will disagree on what "absent" means.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array
	 */
	private function versioned_cache_key_parts( WP_REST_Request $request ): array {
		return $this->versioned_key_parts_from(
			(string) $request->get_param( 'start' ),
			(string) $request->get_param( 'end' ),
			$request->get_param( 'compare_start' ) ? (string) $request->get_param( 'compare_start' ) : null,
			$request->get_param( 'compare_end' ) ? (string) $request->get_param( 'compare_end' ) : null
		);
	}

	/**
	 * Build the outer {cache,data} envelope from a Cache envelope.
	 *
	 * @param array $envelope Cache::store() / Cache::refresh() return.
	 * @return array
	 */
	private static function wrap_envelope( array $envelope ): array {
		return [
			'cache' => [
				'source'         => $envelope['source'],
				'computed_at'    => $envelope['computed_at'],
				'cooldown_until' => $envelope['cooldown_until'],
			],
			'data'  => $envelope['payload'],
		];
	}
}
