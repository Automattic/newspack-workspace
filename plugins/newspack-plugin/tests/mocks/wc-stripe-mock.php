<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase, Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Mocks for the Stripe gateway plugin and its main gateway, shared by every test
 * process that needs a WC_Stripe. Define it here only, so a process that loads
 * more than one mock file never meets a second definition.
 *
 * @package Newspack\Tests
 */

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
	 * The plugin singleton: a connection that is never connected, and the main gateway.
	 */
	class WC_Stripe {
		/**
		 * Singleton.
		 *
		 * @var WC_Stripe|null
		 */
		protected static $instance = null;

		/**
		 * The connection object, which the real plugin exposes as a property.
		 *
		 * @var WC_Stripe|null
		 */
		public $connect = null;

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
			if ( is_null( self::$instance ) ) {
				self::$instance          = new self();
				self::$instance->connect = self::$instance;
			}
			return self::$instance;
		}

		/**
		 * Whether the API keys are set.
		 *
		 * @param string $mode Unused.
		 * @return bool
		 */
		public function is_connected( $mode = 'live' ) {
			return false;
		}

		/**
		 * Whether connected through OAuth.
		 *
		 * @param string $mode Unused.
		 * @return bool
		 */
		public function is_connected_via_oauth( $mode = 'live' ) {
			return false;
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
