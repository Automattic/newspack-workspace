<?php
/**
 * Test stub controller using Newspack\Insights\Cached_Controller_Trait.
 *
 * @package Newspack
 */

use DateTimeImmutable;
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
	 * Stub implementation — returns an empty base-window payload.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	public function build_window_payload( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return [];
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
	 */
	public function call_refresh( WP_REST_Request $request, callable $cb ): WP_REST_Response {
		return $this->refresh_response( $request, $cb );
	}
}
