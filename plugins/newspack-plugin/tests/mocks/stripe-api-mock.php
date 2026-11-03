<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase, Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Mocks for the Stripe gateway: its API client, and the plugin and main gateway
 * that say which payment method types are turned on.
 *
 * @package Newspack\Tests
 */

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

if ( ! class_exists( 'WC_Stripe_UPE_Payment_Gateway' ) ) {
	/**
	 * The main gateway, answering which payment method types it has turned on.
	 */
	class WC_Stripe_UPE_Payment_Gateway {
		/**
		 * Get the enabled payment method types.
		 *
		 * @param bool $force_refresh Unused.
		 * @return mixed What the plugin mock holds.
		 */
		public function get_upe_enabled_payment_method_ids( $force_refresh = false ) {
			return WC_Stripe::$enabled;
		}
	}
}

if ( ! class_exists( 'WC_Stripe' ) ) {
	/**
	 * The plugin singleton, handing out the main gateway.
	 */
	class WC_Stripe {
		/**
		 * What the gateway reports as enabled. Set to a non-array to simulate no answer.
		 *
		 * @var mixed
		 */
		public static $enabled = [ 'card', 'link', 'amazon_pay' ];

		/**
		 * Whether there is no gateway to ask.
		 *
		 * @var bool
		 */
		public static $gateway_missing = false;

		/**
		 * Get the singleton.
		 *
		 * @return WC_Stripe
		 */
		public static function get_instance() {
			return new self();
		}

		/**
		 * Get the main gateway.
		 *
		 * @return WC_Stripe_UPE_Payment_Gateway|null
		 */
		public function get_main_stripe_gateway() {
			return self::$gateway_missing ? null : new WC_Stripe_UPE_Payment_Gateway();
		}
	}
}
