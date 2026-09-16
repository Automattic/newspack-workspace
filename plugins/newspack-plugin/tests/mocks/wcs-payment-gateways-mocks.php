<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

// Stand-ins for the WooCommerce Subscriptions gateway handler, used when
// WooCommerce Subscriptions is not loaded in the test environment: the
// capability lookup and the renewal dispatch. WCS routes the capability check
// through a handler class so WooPayments can substitute its own, so both
// halves of that indirection are mocked here.

/**
 * Minimal mock for the WCS gateways handler.
 */
if ( ! class_exists( 'WC_Subscriptions_Payment_Gateways' ) ) {
	class WC_Subscriptions_Payment_Gateways {
		/**
		 * Staged answer for one_gateway_supports(). Tests set this directly.
		 *
		 * @var bool
		 */
		public static $supports = true;

		public static function one_gateway_supports( $feature ) {
			unset( $feature );
			return self::$supports;
		}

		/**
		 * The gateway leg of WCS's renewal chain, as the charge path calls it.
		 *
		 * Real WCS resolves the subscription, picks its latest renewal order and
		 * fires `woocommerce_scheduled_subscription_payment_{gateway}` with the
		 * amount and that order. The mock stops one level higher, on the
		 * umbrella action, so a test stands in for the gateway with a listener
		 * on a single hook and no fixture has to model a gateway id per order.
		 *
		 * @param int $subscription_id The subscription being renewed.
		 */
		public static function gateway_scheduled_subscription_payment( $subscription_id ) {
			do_action( 'woocommerce_scheduled_subscription_payment', $subscription_id );
		}
	}
}

/**
 * Minimal mock for the WCS plugin singleton, which exposes the handler class.
 */
if ( ! class_exists( 'WC_Subscriptions_Core_Plugin' ) ) {
	class WC_Subscriptions_Core_Plugin {
		public static function instance() {
			return new self();
		}

		public function get_gateways_handler_class() {
			return 'WC_Subscriptions_Payment_Gateways';
		}
	}
}
