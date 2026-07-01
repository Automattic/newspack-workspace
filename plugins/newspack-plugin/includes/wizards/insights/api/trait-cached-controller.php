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
			$this->build_window_payload( $start, $end ),
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
	 * Cache-aware GET wrapper.
	 *
	 * @param WP_REST_Request $request       Incoming request.
	 * @param callable        $build_payload () => array.
	 */
	protected function cached_response( WP_REST_Request $request, callable $build_payload ): WP_REST_Response {
		$envelope = Cache::store(
			$this->tab_slug(),
			$this->cache_source(),
			$this->versioned_cache_key_parts( $request ),
			$build_payload
		);
		$response = rest_ensure_response( self::wrap_envelope( $envelope ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * POST /{tab}/refresh wrapper. Always returns a 200 envelope — when the
	 * BQ cooldown blocks a refresh, `cache.cooldown_until` is populated in
	 * the envelope so the client can render the throttle UI without relying
	 * on a 4xx response (Atomic's edge mutates 4xx bodies).
	 *
	 * @param WP_REST_Request $request       Incoming request.
	 * @param callable        $build_payload () => array.
	 */
	protected function refresh_response( WP_REST_Request $request, callable $build_payload ): WP_REST_Response {
		$envelope = Cache::refresh(
			$this->tab_slug(),
			$this->cache_source(),
			$this->versioned_cache_key_parts( $request ),
			$build_payload
		);
		$response = rest_ensure_response( self::wrap_envelope( $envelope ) );
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
