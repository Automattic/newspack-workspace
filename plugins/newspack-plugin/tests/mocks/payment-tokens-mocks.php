<?php // phpcs:ignoreFile
/**
 * Mocks for WooCommerce payment tokens and refunds, backing the Subscribers
 * wizard payment-action tests. Loaded after wc-mocks.php.
 *
 * The token store is the global $payment_tokens_database (id => token). The
 * WCS_Payment_Tokens mock mirrors the real class's behaviour faithfully — it
 * resolves the token meta key by matching the OLD token's value against the
 * gateway's declared payment meta — because that matching is exactly the
 * contract production code depends on.
 *
 * @package Newspack\Tests
 */

global $payment_tokens_database, $wc_mock_refunds, $wc_mock_refund_result, $wc_mock_gateways_by_order;
$payment_tokens_database   = [];
$wc_mock_refunds           = [];
$wc_mock_refund_result     = null;
$wc_mock_gateways_by_order = [];

if ( ! class_exists( 'WC_Payment_Token_CC' ) ) {
	/**
	 * Credit-card token mirroring the WC_Payment_Token_CC surface production reads.
	 */
	class WC_Payment_Token_CC {
		private $data = [
			'id'           => 0,
			'token'        => '',
			'gateway_id'   => '',
			'card_type'    => '',
			'last4'        => '',
			'expiry_month' => '',
			'expiry_year'  => '',
			'user_id'      => 0,
			'default'      => false,
		];
		public function get_id() {
			return $this->data['id'];
		}
		public function get_token() {
			return $this->data['token'];
		}
		public function set_token( $token ) {
			$this->data['token'] = $token;
		}
		public function get_gateway_id() {
			return $this->data['gateway_id'];
		}
		public function set_gateway_id( $gateway_id ) {
			$this->data['gateway_id'] = $gateway_id;
		}
		public function get_card_type() {
			return $this->data['card_type'];
		}
		public function set_card_type( $card_type ) {
			$this->data['card_type'] = $card_type;
		}
		public function get_last4() {
			return $this->data['last4'];
		}
		public function set_last4( $last4 ) {
			$this->data['last4'] = $last4;
		}
		public function get_expiry_month() {
			return $this->data['expiry_month'];
		}
		public function set_expiry_month( $month ) {
			$this->data['expiry_month'] = $month;
		}
		public function get_expiry_year() {
			return $this->data['expiry_year'];
		}
		public function set_expiry_year( $year ) {
			$this->data['expiry_year'] = $year;
		}
		public function get_user_id() {
			return $this->data['user_id'];
		}
		public function set_user_id( $user_id ) {
			$this->data['user_id'] = (int) $user_id;
		}
		public function is_default() {
			return (bool) $this->data['default'];
		}
		public function set_default( $default ) {
			$this->data['default'] = (bool) $default;
		}
		public function get_display_name() {
			return trim( $this->data['card_type'] . ' ending in ' . $this->data['last4'] );
		}
		public function save() {
			global $payment_tokens_database;
			if ( ! $this->data['id'] ) {
				$this->data['id'] = count( $payment_tokens_database ) + 1;
			}
			$payment_tokens_database[ $this->data['id'] ] = $this;
			return $this->data['id'];
		}
	}
}

if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
	/**
	 * Token store mirroring the WC_Payment_Tokens statics production calls.
	 */
	class WC_Payment_Tokens {
		public static function get( $token_id ) {
			global $payment_tokens_database;
			return $payment_tokens_database[ (int) $token_id ] ?? null;
		}
		public static function get_customer_tokens( $customer_id, $gateway_id = '' ) {
			global $payment_tokens_database;
			$tokens = [];
			foreach ( $payment_tokens_database as $id => $token ) {
				if ( (int) $token->get_user_id() !== (int) $customer_id ) {
					continue;
				}
				if ( '' !== $gateway_id && $token->get_gateway_id() !== $gateway_id ) {
					continue;
				}
				$tokens[ $id ] = $token;
			}
			return $tokens;
		}
		public static function set_users_default( $user_id, $token_id ) {
			global $payment_tokens_database;
			foreach ( $payment_tokens_database as $token ) {
				if ( (int) $token->get_user_id() === (int) $user_id ) {
					$token->set_default( $token->get_id() === (int) $token_id );
				}
			}
		}
	}
}

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
