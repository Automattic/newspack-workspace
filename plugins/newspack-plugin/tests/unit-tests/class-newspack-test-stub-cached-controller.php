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
	 * Compute counter — how many times build_window_payload() ran.
	 *
	 * @var int
	 */
	public $computes = 0;

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
	 * Deterministic single-window payload; increments the compute counter.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	public function build_window_payload( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		$this->computes++;
		return [
			'current'  => $this->window_marker( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ),
			'previous' => null,
		];
	}

	/**
	 * Return a deterministic marker string for a window.
	 *
	 * @param string $s Window start (Y-m-d).
	 * @param string $e Window end (Y-m-d).
	 * @return string
	 */
	public function window_marker( string $s, string $e ): string {
		return 'win:' . $s . '..' . $e;
	}

	/**
	 * Return the current compute count.
	 */
	public function compute_count(): int {
		return $this->computes;
	}

	/**
	 * Reset the compute counter to zero.
	 */
	public function reset_compute_count(): void {
		$this->computes = 0;
	}

	/**
	 * Expose the protected tab_slug() for assertions.
	 */
	public function tab_slug_public(): string {
		return $this->tab_slug();
	}

	/**
	 * Expose the protected cache_source() for assertions.
	 */
	public function cache_source_public(): string {
		return $this->cache_source();
	}

	/**
	 * Expose the private base_key_parts() for assertions.
	 *
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @return array
	 */
	public function base_key_public( DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return $this->base_key_parts( $start, $end );
	}

	/**
	 * Parse a request's window params into [ start, end, compare_start|null, compare_end|null ].
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array
	 */
	private function parse_windows( WP_REST_Request $request ): array {
		$mk = static function ( $v, bool $eod ): ?DateTimeImmutable {
			return $v ? new DateTimeImmutable( (string) $v . ( $eod ? ' 23:59:59' : ' 00:00:00' ) ) : null;
		};
		return [
			$mk( $request->get_param( 'start' ), false ),
			$mk( $request->get_param( 'end' ), true ),
			$mk( $request->get_param( 'compare_start' ), false ),
			$mk( $request->get_param( 'compare_end' ), true ),
		];
	}

	/**
	 * Test helper — exposes the protected cached_response().
	 *
	 * @param WP_REST_Request $request Test request.
	 */
	public function call_cached( WP_REST_Request $request ): WP_REST_Response {
		[ $s, $e, $cs, $ce ] = $this->parse_windows( $request );
		return $this->cached_response( $request, $s, $e, $cs, $ce );
	}

	/**
	 * Test helper — exposes the protected refresh_response().
	 *
	 * @param WP_REST_Request $request Test request.
	 */
	public function call_refresh( WP_REST_Request $request ): WP_REST_Response {
		[ $s, $e, $cs, $ce ] = $this->parse_windows( $request );
		return $this->refresh_response( $request, $s, $e, $cs, $ce );
	}
}
