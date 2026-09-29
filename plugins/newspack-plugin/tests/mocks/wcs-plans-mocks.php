<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Stand-ins for WooCommerce Subscriptions' subscription plans API (the folded-in
 * All Products for Subscriptions, `WCS_ATT_*`). Loaded only by tests that need plans,
 * so the rest of the suite runs as a site without them.
 *
 * @package Newspack\Tests
 */

require_once __DIR__ . '/wc-mocks.php';

if ( ! class_exists( 'WCS_ATT_Scheme' ) ) {
	class WCS_ATT_Scheme {
		private $data;
		public function __construct( array $data ) {
			$this->data = array_merge(
				[
					'id'           => '',
					'period'       => 'month',
					'interval'     => 1,
					'length'       => 0,
					'trial_period' => '',
					'trial_length' => 0,
					'signup_fee'   => 0.0,
				],
				$data
			);
		}
		public function get_key() {
			return $this->data['id'];
		}
		public function get_period() {
			return $this->data['period'];
		}
		public function get_interval() {
			return $this->data['interval'];
		}
		public function get_length() {
			return $this->data['length'];
		}
		public function get_trial_period() {
			return $this->data['trial_period'];
		}
		public function get_trial_length() {
			return $this->data['trial_length'];
		}
		public function get_signup_fee(): float {
			return (float) $this->data['signup_fee'];
		}
	}
}

if ( ! class_exists( 'WCS_ATT_Product_Schemes' ) ) {
	/**
	 * Plans live on the parent of a variable product; a variation resolves its
	 * parent's, as WooCommerce does.
	 */
	class WCS_ATT_Product_Schemes {
		private static $plans  = [];
		private static $forced = [];
		private static $active = [];
		/**
		 * How many times has_subscription_schemes() ran: the plan lookup a caller
		 * that has nothing to decide should never reach.
		 *
		 * @var int
		 */
		public static $mock_lookups = 0;

		public static function mock_register( int $product_id, array $plans, bool $forced = false ) {
			self::$plans[ $product_id ]  = $plans;
			self::$forced[ $product_id ] = $forced;
		}
		public static function mock_reset() {
			self::$plans  = [];
			self::$forced = [];
			self::$active       = [];
			self::$mock_lookups = 0;
		}
		private static function source_id( $product ) {
			$id = $product->get_id();
			return ! isset( self::$plans[ $id ] ) && $product->get_parent_id() ? $product->get_parent_id() : $id;
		}
		public static function has_subscription_schemes( $product, $context = 'any' ) {
			++self::$mock_lookups;
			return ! empty( self::$plans[ self::source_id( $product ) ] );
		}
		public static function get_subscription_schemes( $product, $context = 'any' ) {
			$schemes = [];
			foreach ( self::$plans[ self::source_id( $product ) ] ?? [] as $key => $data ) {
				$schemes[ $key ] = new WCS_ATT_Scheme( array_merge( [ 'id' => $key ], $data ) );
			}
			return $schemes;
		}
		public static function has_forced_subscription_scheme( $product ) {
			return (bool) apply_filters( 'wcsatt_force_subscription', ! empty( self::$forced[ self::source_id( $product ) ] ), $product );
		}
		public static function set_subscription_scheme( $product, $key ) {
			self::$active[ spl_object_id( $product ) ] = $key;
		}
		/**
		 * Real WCS_ATT_Product_Schemes::get_subscription_scheme() returns null/false when no
		 * scheme has been applied, even on a forced product: WooCommerce applies a forced
		 * product's default plan only when it reaches the cart (WCS_ATT_Cart), never here.
		 *
		 * @param \WC_Product $product    Product or variation.
		 * @param string      $return     Unused by the mock; real WCS reads 'key' or 'object'.
		 * @param string      $scheme_key Unused by the mock.
		 */
		public static function get_subscription_scheme( $product, $return = 'key', $scheme_key = '' ) {
			$key = self::$active[ spl_object_id( $product ) ] ?? null;
			return $key ? $key : false;
		}
		/**
		 * Real WCS reads `convert_to_sub_<id>` from the request, returning null when it is
		 * absent and false for the one-time choice.
		 *
		 * @param int|string $product_id Product ID, or a variation's parent ID.
		 */
		public static function get_posted_subscription_scheme( $product_id = '' ) {
			$field = '' !== $product_id ? 'convert_to_sub_' . absint( $product_id ) : 'convert_to_sub';
			if ( ! isset( $_REQUEST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return null;
			}
			$value = sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return in_array( $value, [ '', '0' ], true ) ? false : $value;
		}
		public static function filter_is_subscription( $is_subscription, $product_id, $product ) {
			if ( $is_subscription || ! is_object( $product ) || ! self::has_subscription_schemes( $product ) ) {
				return $is_subscription;
			}
			return false !== self::get_subscription_scheme( $product );
		}
	}
	add_filter( 'woocommerce_is_subscription', [ 'WCS_ATT_Product_Schemes', 'filter_is_subscription' ], 10, 3 );
}

if ( ! class_exists( 'WCS_ATT_Product' ) ) {
	class WCS_ATT_Product {
		public static $mock_default_mode = 'disable';
		public static function get_default_subscription_scheme_mode() {
			return self::$mock_default_mode;
		}
	}
}

if ( ! class_exists( 'WCS_ATT_Order' ) ) {
	class WCS_ATT_Order {
		public static function get_subscription_scheme( $order_item, $args = [] ) {
			$key = method_exists( $order_item, 'get_meta' ) ? $order_item->get_meta( '_wcsatt_scheme', true ) : '';
			return '' === $key ? false : $key;
		}
	}
}
