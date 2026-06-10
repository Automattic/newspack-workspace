<?php
/**
 * Test stub controller using Newspack\Insights\Cached_Controller_Trait.
 *
 * @package Newspack
 */

use Newspack\Insights\Cache;
use Newspack\Insights\Cached_Controller_Trait;

/**
 * Concrete external-source controller used by trait tests.
 */
class Newspack_Test_Stub_Cached_Controller extends WP_REST_Controller {
	use Cached_Controller_Trait;

	/**
	 * Get cache source.
	 */
	protected function cache_source(): string {
		return Cache::SOURCE_EXTERNAL;
	}

	/**
	 * Get tab slug.
	 */
	protected function tab_slug(): string {
		return 'stub';
	}

	/**
	 * Test helper — exposes the protected cached_response().
	 *
	 * @param WP_REST_Request $request Test request.
	 * @param callable        $cb      Payload builder.
	 */
	public function call_cached( WP_REST_Request $request, callable $cb ): WP_REST_Response {
		return $this->cached_response( $request, $cb );
	}

	/**
	 * Test helper — exposes the protected refresh_response().
	 *
	 * @param WP_REST_Request $request Test request.
	 * @param callable        $cb      Payload builder.
	 * @return WP_REST_Response|WP_Error
	 */
	public function call_refresh( WP_REST_Request $request, callable $cb ) {
		return $this->refresh_response( $request, $cb );
	}
}
