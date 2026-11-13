<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * Mock for the Stripe gateway's API client. The plugin and gateway mocks are in
 * wc-stripe-mock.php, which this file loads.
 *
 * @package Newspack\Tests
 */

require_once __DIR__ . '/wc-stripe-mock.php';

if ( ! class_exists( 'WC_Stripe_API' ) ) {
	/**
	 * Answers lookups from a fixed set of objects and records what was asked.
	 */
	class WC_Stripe_API {
		/**
		 * Objects by id. A missing id answers with Stripe's not-found error.
		 *
		 * @var array
		 */
		public static $objects = [];

		/**
		 * Ids looked up, in order.
		 *
		 * @var string[]
		 */
		public static $calls = [];

		/**
		 * Timeout each lookup would have sent, after the http_request_args filter.
		 *
		 * @var int[]
		 */
		public static $timeouts = [];

		/**
		 * Whether a lookup throws.
		 *
		 * @var bool
		 */
		public static $throw = false;

		/**
		 * Retrieve a payment method.
		 *
		 * @param string $payment_method_id Id.
		 * @return mixed
		 */
		public static function get_payment_method( string $payment_method_id ) {
			return self::retrieve( 'payment_methods/' . $payment_method_id );
		}

		/**
		 * GET an API path.
		 *
		 * @param string $api Path.
		 * @return mixed
		 * @throws Exception When told to.
		 */
		public static function retrieve( $api ) {
			$id                 = substr( $api, strrpos( $api, '/' ) + 1 );
			self::$calls[]      = $id;
			$args               = apply_filters( 'http_request_args', [ 'timeout' => 70 ], 'https://api.stripe.com/v1/' . $api ); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Stripe's own timeout, which the code under test must lower.
			self::$timeouts[]   = $args['timeout'];
			if ( self::$throw ) {
				throw new Exception( 'lookup failed' );
			}
			if ( array_key_exists( $id, self::$objects ) ) {
				return self::$objects[ $id ];
			}
			return (object) [
				'error' => (object) [
					'type' => 'invalid_request_error',
					'code' => 'resource_missing',
				],
			];
		}
	}
}
