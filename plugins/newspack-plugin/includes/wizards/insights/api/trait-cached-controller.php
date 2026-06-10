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
	 * Cache-aware GET wrapper.
	 *
	 * @param WP_REST_Request $request       Incoming request.
	 * @param callable        $build_payload () => array.
	 */
	protected function cached_response( WP_REST_Request $request, callable $build_payload ): WP_REST_Response {
		$envelope = Cache::store(
			$this->tab_slug(),
			$this->cache_source(),
			self::cache_key_parts( $request ),
			$build_payload
		);
		return rest_ensure_response( self::wrap_envelope( $envelope ) );
	}

	/**
	 * POST /{tab}/refresh wrapper. Returns a fresh envelope or a 429 WP_Error.
	 *
	 * @param WP_REST_Request $request       Incoming request.
	 * @param callable        $build_payload () => array.
	 * @return WP_REST_Response|\WP_Error
	 */
	protected function refresh_response( WP_REST_Request $request, callable $build_payload ) {
		$result = Cache::refresh(
			$this->tab_slug(),
			$this->cache_source(),
			self::cache_key_parts( $request ),
			$build_payload
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( self::wrap_envelope( $result ) );
	}

	/**
	 * Canonical window components used as the cache key.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array{0:string,1:string,2:?string,3:?string}
	 */
	private static function cache_key_parts( WP_REST_Request $request ): array {
		return [
			(string) $request->get_param( 'start' ),
			(string) $request->get_param( 'end' ),
			$request->get_param( 'compare_start' ) ? (string) $request->get_param( 'compare_start' ) : null,
			$request->get_param( 'compare_end' ) ? (string) $request->get_param( 'compare_end' ) : null,
		];
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
