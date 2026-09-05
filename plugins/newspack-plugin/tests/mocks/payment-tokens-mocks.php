<?php // phpcs:ignoreFile
/**
 * Mocks for WooCommerce payment tokens and refunds, backing the Subscribers
 * wizard payment-action tests. Loaded after wc-mocks.php.
 *
 * The token store is WC_Payment_Tokens::$tokens in wc-mocks.php (id => token).
 * The WCS_Payment_Tokens mock mirrors the real class's behaviour faithfully — it
 * resolves the token meta key by matching the OLD token's value against the
 * gateway's declared payment meta — because that matching is exactly the
 * contract production code depends on.
 *
 * @package Newspack\Tests
 */

global $wc_mock_refunds, $wc_mock_refund_result, $wc_mock_gateways_by_order;
WC_Payment_Tokens::$tokens = [];
$wc_mock_refunds           = [];
$wc_mock_refund_result     = null;
$wc_mock_gateways_by_order = [];

if ( ! class_exists( 'WCS_Payment_Tokens' ) ) {
	/**
	 * Faithful mirror of WCS_Payment_Tokens::update_subscription_token(): find
	 * the meta key whose current value is the old token's value, write the new
	 * token's value there.
	 */
	class WCS_Payment_Tokens {
		// Mirror of the real class's per-request memoized token fetch; the mock
		// store is cheap, so it just delegates.
		public static function get_customer_tokens( $customer_id = 0, $gateway_id = '' ) {
			return WC_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );
		}
		public static function get_subscription_payment_meta( $subscription, $gateway_id ) {
			$payment_method_meta = apply_filters( 'woocommerce_subscription_payment_meta', [], $subscription );
			if ( is_array( $payment_method_meta ) && isset( $payment_method_meta[ $gateway_id ] ) && is_array( $payment_method_meta[ $gateway_id ] ) ) {
				return $payment_method_meta[ $gateway_id ];
			}
			return false;
		}
		public static function update_subscription_token( $subscription, $new_token, $old_token ) {
			$payment_meta_table = self::get_subscription_payment_meta( $subscription, $old_token->get_gateway_id() );
			if ( is_array( $payment_meta_table ) ) {
				foreach ( $payment_meta_table as $meta ) {
					foreach ( $meta as $meta_key => $meta_data ) {
						if ( $old_token->get_token() === $meta_data['value'] ) {
							$subscription->update_meta_data( $meta_key, $new_token->get_token() );
							$subscription->save();
							break 2;
						}
					}
				}
			}
			return apply_filters( 'woocommerce_subscriptions_update_subscription_token', true, $subscription, $new_token, $old_token );
		}
	}
}

if ( ! function_exists( 'wc_create_refund' ) ) {
	/**
	 * Recording refund factory. Stage $wc_mock_refund_result with a WP_Error to
	 * simulate a gateway decline; otherwise the refund "succeeds".
	 *
	 * @param array $args Refund args as passed by production code.
	 */
	function wc_create_refund( $args = [] ) {
		global $wc_mock_refunds, $wc_mock_refund_result;
		$wc_mock_refunds[] = $args;
		if ( is_wp_error( $wc_mock_refund_result ) ) {
			return $wc_mock_refund_result;
		}
		return (object) $args;
	}
}

if ( ! function_exists( 'wc_get_payment_gateway_by_order' ) ) {
	/**
	 * Stage $wc_mock_gateways_by_order[ order_id ] with an object exposing
	 * supports( $feature ); unstaged orders have no gateway (manual payment).
	 *
	 * @param WC_Order $order The order.
	 */
	function wc_get_payment_gateway_by_order( $order ) {
		global $wc_mock_gateways_by_order;
		$order_id = is_object( $order ) ? $order->get_id() : (int) $order;
		return $wc_mock_gateways_by_order[ $order_id ] ?? false;
	}
}

if ( ! class_exists( 'Mock_Refundable_Gateway' ) ) {
	/**
	 * A gateway double whose feature support is staged at construction.
	 */
	class Mock_Refundable_Gateway {
		private $features;
		public function __construct( $features = [ 'refunds' ] ) {
			$this->features = $features;
		}
		public function supports( $feature ) {
			return in_array( $feature, $this->features, true );
		}
	}
}
