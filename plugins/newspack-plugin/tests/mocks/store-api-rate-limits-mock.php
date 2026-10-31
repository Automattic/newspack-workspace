<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Mock for the WooCommerce Store API's rate limiter.
 *
 * @package Newspack\Tests
 */

namespace Automattic\WooCommerce\StoreApi\Utilities;

if ( ! class_exists( __NAMESPACE__ . '\RateLimits' ) ) {
	/**
	 * Mirrors the Store API's RateLimits counter: a fixed window whose remaining
	 * count is taken from the options filter on the first hit, then clamps at zero.
	 */
	class RateLimits {
		/**
		 * Remaining count per action id.
		 *
		 * @var int[]
		 */
		public static $remaining = [];

		/**
		 * Record a hit.
		 *
		 * @param string $action_id Action id.
		 * @return object Current limit.
		 */
		public static function update_rate_limit( string $action_id ): object {
			if ( isset( self::$remaining[ $action_id ] ) ) {
				self::$remaining[ $action_id ] = max( self::$remaining[ $action_id ] - 1, 0 );
			} else {
				$options                       = apply_filters( 'woocommerce_store_api_rate_limit_options', [ 'limit' => 25 ] );
				self::$remaining[ $action_id ] = (int) $options['limit'] - 1;
			}
			return (object) [
				'reset'     => time() + 60,
				'remaining' => self::$remaining[ $action_id ],
			];
		}
	}
}
