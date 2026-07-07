<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

/**
 * Minimal mock for WC_Payment_Token, used when WooCommerce is not loaded in the test environment.
 */
class WC_Payment_Token {
	private $gateway_id;
	public function __construct( $gateway_id ) {
		$this->gateway_id = $gateway_id;
	}
	public function get_gateway_id() {
		return $this->gateway_id;
	}
}

class WC_Install {
	public static function create_pages() {
		return true;
	}
}

class WC_Gateway_Stripe {
	public $enabled         = 'yes';
	private static $options = [];
	public function update_option( $key, $value ) {
		self::$options[ $key ] = $value;
	}
	public static function get_option( $key ) {
		if ( isset( self::$options[ $key ] ) ) {
			return self::$options[ $key ];
		}
		return null;
	}
	public static function reset_testing_options() {
		self::$options = [];
	}
}

class WC_Stripe {
	protected static $instance = null;
	public $connect = null;
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->connect = self::$instance;
		}
		return self::$instance;
	}
	public function is_connected( $mode = 'live' ) {
		return false;
	}
	public function is_connected_via_oauth( $mode = 'live' ) {
		return false;
	}
}

class WC_Stripe_Feature_Flags {
	public static function is_upe_checkout_enabled() {
		return true;
	}
}

class WC_Payment_Gateways {
	private static $gateways = [];
	public static function instance() {
		return new WC_Payment_Gateways();
	}
	public function init() {
		self::$gateways = [ 'stripe' => new WC_Gateway_Stripe() ];
	}
	public function payment_gateways() {
		return self::$gateways;
	}
}

class WC_DateTime extends DateTime {
	public function date( $format ) {
		return gmdate( $format, $this->getTimestamp() );
	}
	public function getOffsetTimestamp() {
		return $this->getTimestamp() + $this->getOffset();
	}
}

class WC_Customer {
	public $data = [];
	public function __construct( $user_id ) {
		$this->data = [
			'user_id'      => $user_id,
			'date_created' => gmdate( 'Y-m-d H:i:s' ),
		];
	}
	public function get_id() {
		return $this->data['user_id'];
	}
	public function get_date_created() {
		return new WC_DateTime( $this->data['date_created'] );
	}
	public function get_total_spent() {
		return get_user_meta( $this->get_id(), 'wc_total_spent', true );
	}
	public function get_billing_first_name() {
		return get_user_meta( $this->get_id(), 'first_name', true );
	}
	public function get_billing_last_name() {
		return get_user_meta( $this->get_id(), 'last_name', true );
	}
	public function get_email() {
		return get_userdata( $this->get_id() )->user_email;
	}
	public function get_billing_email() {
		return $this->data['billing_email'] ?? '';
	}
	public function set_billing_email( $email ) {
		$this->data['billing_email'] = $email;
	}
	public function get_billing() {
		return [];
	}
	public function get_shipping() {
		return [];
	}
	public function get_is_paying_customer() {
		return false;
	}
	public function save() {}
}

$orders_database = [];
$subscriptions_database = [];
$products_database = [];

class WC_Order_Item_Product {
	private $data = [];
	private $meta = [];
	public function __construct( $data = [] ) {
		$this->data = $data;
		if ( isset( $data['meta'] ) ) {
			$this->meta = $data['meta'];
		}
	}
	public function get_name() {
		return $this->data['name'] ?? '';
	}
	public function get_product_id() {
		return $this->data['product_id'] ?? 0;
	}
	public function get_variation_id() {
		return $this->data['variation_id'] ?? 0;
	}
	public function get_quantity() {
		return $this->data['quantity'] ?? 1;
	}
	public function get_subtotal() {
		return $this->data['subtotal'] ?? 0;
	}
	public function get_total() {
		return $this->data['total'] ?? 0;
	}
	public function get_product() {
		global $products_database;
		$product_id = $this->data['product_id'] ?? 0;
		return $products_database[ $product_id ] ?? false;
	}
	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
}

class WC_Product {
	private $data = [];
	private $meta = [];
	public function __construct( $data = [] ) {
		$this->data = $data;
		if ( isset( $data['meta'] ) ) {
			$this->meta = $data['meta'];
		}
	}
	public function get_id() {
		return $this->data['id'] ?? 0;
	}
	public function get_name() {
		return $this->data['name'] ?? '';
	}
	public function get_type() {
		return $this->data['type'] ?? 'simple';
	}
	public function is_type( $types ) {
		$types = (array) $types;
		return in_array( $this->get_type(), $types, true );
	}
	public function get_parent_id() {
		return $this->data['parent_id'] ?? 0;
	}
	public function get_children() {
		return $this->data['children'] ?? [];
	}
	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
}

/**
 * Register a mock product in the global products database.
 *
 * @param array $data Product data including 'id', 'children', 'type', 'name', 'price'.
 * @return WC_Product
 */
function wc_create_mock_product( $data = [] ) {
	global $products_database;
	$product = new WC_Product( $data );
	$products_database[ $product->get_id() ] = $product;
	return $product;
}

class WC_Order {
	public $data = [];
	public $meta = [];
	public function __construct( $data ) {
		global $orders_database;
		$data['id'] = count( $orders_database ) + 1;
		if ( ! isset( $data['date_paid'] ) ) {
			$data['date_paid'] = gmdate( 'Y-m-d H:i:s' );
		}
		if ( ! isset( $data['items'] ) ) {
			$data['items'] = [];
		}
		$this->data = $data;
		if ( $data['status'] === 'completed' ) {
			// Update customer's total spent.
			$customer = new WC_Customer( $this->get_customer_id() );
			$total_spent = (float) $customer->get_total_spent() + (float) $this->get_total();
			update_user_meta( $customer->get_id(), 'wc_total_spent', $total_spent );
			// Add the order to the mock DB.
		}
		if ( isset( $data['meta'] ) ) {
			$this->meta = $data['meta'];
		}
		$orders_database[] = $this;
	}
	public function get_id() {
		return $this->data['id'];
	}
	public function get_customer_id() {
		return $this->data['customer_id'];
	}
	public function get_meta( $field_name ) {
		return isset( $this->meta[ $field_name ] ) ? $this->meta[ $field_name ] : '';
	}
	public function has_status( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = [ $statuses ];
		}
		return in_array( $this->data['status'], $statuses, true );
	}
	public function get_items() {
		return $this->data['items'];
	}
	public function get_date_paid() {
		if ( empty( $this->data['date_paid'] ) ) {
			return null;
		}
		return new WC_DateTime( $this->data['date_paid'] );
	}
	public function get_date_completed() {
		return new WC_DateTime( $this->data['date_completed'] );
	}
	public function get_total() {
		return $this->data['total'];
	}
	public function get_status() {
		return $this->data['status'];
	}
	public function get_coupon_codes() {
		return $this->data['coupon_codes'] ?? [];
	}
	public function delete_meta_data( $field_name ) {
		unset( $this->meta[ $field_name ] );
	}
	public function meta_exists( $field_name ) {
		return isset( $this->meta[ $field_name ] );
	}
	public function save() {
		return true;
	}
	public function get_billing_email() {
		return $this->data['billing_email'] ?? '';
	}
	public function get_currency() {
		return $this->data['currency'] ?? '';
	}
}

class WC_Subscription {
	public $data = [];
	public $meta = [];
	public $orders = [];
	public $products = [];
	public function __construct( $data ) {
		$this->data = array_merge( $data, $this->data );
		if ( isset( $data['meta'] ) ) {
			$this->meta = $data['meta'];
		}
		if ( isset( $data['orders'] ) ) {
			$this->orders = $data['orders'];
			usort(
				$this->orders,
				function( $a, $b ) {
					return $b->get_date_paid()->getTimestamp() <=> $a->get_date_paid()->getTimestamp();
				}
			);
		}
		if ( isset( $data['products'] ) ) {
			$this->products = $data['products'];
		}
	}
	public function get_id() {
		return $this->data['id'];
	}
	public function get_customer_id() {
		return $this->data['customer_id'] ?? null;
	}
	public function get_user_id() {
		return $this->data['customer_id'] ?? null;
	}
	public function get_payment_method() {
		return $this->data['payment_method'] ?? '';
	}
	public function has_product( $product_id ) {
		return in_array( $product_id, $this->products, true );
	}
	public function get_meta( $field_name ) {
		return isset( $this->meta[ $field_name ] ) ? $this->meta[ $field_name ] : '';
	}
	public function update_meta_data( $field_name, $value ) {
		$this->meta[ $field_name ] = $value;
	}
	public function delete_meta_data( $field_name ) {
		unset( $this->meta[ $field_name ] );
	}
	public function meta_exists( $field_name ) {
		return isset( $this->meta[ $field_name ] );
	}
	public function has_status( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = [ $statuses ];
		}
		return in_array( $this->data['status'], $statuses, true );
	}
	public function get_date_created() {
		return new WC_DateTime( $this->data['date_created'] ?? 'now' );
	}
	public function get_date_paid() {
		return new WC_DateTime( $this->data['date_paid'] );
	}
	public function get_total() {
		return $this->data['total'];
	}
	public function get_status() {
		return $this->data['status'];
	}
	public function set_status( $status ) {
		$this->data['status'] = $status;
	}
	public function get_billing_period() {
		return $this->data['billing_period'];
	}
	public function get_billing_interval() {
		return $this->data['billing_interval'];
	}
	public function get_billing_email() {
		return $this->data['billing_email'] ?? '';
	}
	public function get_currency() {
		return $this->data['currency'] ?? '';
	}
	public function get_last_order( $output = 'all', $types = [], $exclude_statuses = [] ) {
		if ( empty( $this->orders ) ) {
			return false;
		}
		if ( ! empty( $exclude_statuses ) ) {
			foreach ( $this->orders as $order ) {
				if ( ! $order->has_status( $exclude_statuses ) ) {
					return $order;
				}
			}
			return false;
		}
		return reset( $this->orders );
	}
	public function get_related_orders( $output = 'all', $type = '' ) {
		return $this->data['related_orders'][ $type ] ?? [];
	}
	public function get_coupon_codes() {
		return $this->data['coupon_codes'] ?? [];
	}
	public function get_parent() {
		return $this->data['parent_order'] ?? null;
	}
	public function get_date( $type ) {
		return $this->data['dates'][ $type ] ?? 0;
	}
	public function get_time( $type ) {
		return $this->data['times'][ $type ] ?? 0;
	}
	public function calculate_date() {
		$start    = strtotime( $this->get_date( 'start' ) );
		$interval = $this->get_billing_interval();
		$period   = $this->get_billing_period();
		$end      = time();

		while ( $start <= $end ) {
			$start = strtotime( "+$interval $period", $start );
		}
		return gmdate( 'Y-m-d H:i:s', $start );
	}
	public function update_dates( $dates ) {
		foreach ( $dates as $type => $date ) {
			$this->data['dates'][ $type ] = $date;
		}
	}
	public function get_formatted_billing_full_name() {
		$first = $this->data['billing_first_name'] ?? '';
		$last  = $this->data['billing_last_name'] ?? '';
		return trim( "$first $last" );
	}
	public function get_items() {
		return $this->data['items'] ?? [];
	}
	public function get_items_sign_up_fee( $item, $tax = 'exclusive_of_tax' ) {
		global $wcs_mock_items_sign_up_fee, $wcs_mock_last_items_sign_up_fee_tax;
		$wcs_mock_last_items_sign_up_fee_tax = $tax;
		if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
			$meta_value = $item->get_meta( '_subscription_sign_up_fee' );
			if ( $meta_value !== '' && $meta_value !== null ) {
				return (float) $meta_value;
			}
		}
		return (float) ( $wcs_mock_items_sign_up_fee ?? 0 );
	}
	public function needs_payment() {
		return ! empty( $this->data['needs_payment'] );
	}
	public function get_parent_id() {
		return $this->data['parent_id'] ?? 0;
	}
	public function get_payment_method_title() {
		return $this->data['payment_method_title'] ?? '';
	}
	public function is_manual() {
		return ! empty( $this->data['requires_manual_renewal'] );
	}
	public function __call( $name, $arguments ) {
		// Address getters: get_billing_first_name(), get_shipping_city(), etc.
		// resolve to flat data keys ('billing_first_name'), matching how the
		// fixtures stage address data.
		if ( 0 === strpos( $name, 'get_billing_' ) || 0 === strpos( $name, 'get_shipping_' ) ) {
			return $this->data[ substr( $name, 4 ) ] ?? '';
		}
		throw new BadMethodCallException( sprintf( 'Call to undefined method %s::%s()', __CLASS__, $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
	public function get_view_order_url() {
		return $this->data['view_order_url'] ?? 'https://example.test/my-account/view-order/' . $this->get_id();
	}
	public function save() {
		return true;
	}
}

class WC_Subscriptions {
}

if ( ! class_exists( 'WC_Subscriptions_Switcher' ) ) {
	/**
	 * Mock of WC_Subscriptions_Switcher.
	 *
	 * The calculate_total_paid_since_last_order() method returns the value of
	 * the $wcs_mock_total_paid_including_signup_fee global so tests can drive
	 * it, and records the arguments it was called with on
	 * $wcs_mock_last_calculate_total_paid_args so tests can assert that the
	 * caller passed the expected sign-up-fee mode and orders_to_include list.
	 */
	class WC_Subscriptions_Switcher {
		public static function cart_contains_switches( $item_action = 'any' ) {
			unset( $item_action );
			return false;
		}

		public static function calculate_total_paid_since_last_order( $subscription, $subscription_item, $include_sign_up_fees = 'include_sign_up_fees', $orders_to_include = [] ) {
			global $wcs_mock_total_paid_including_signup_fee, $wcs_mock_last_calculate_total_paid_args;
			$wcs_mock_last_calculate_total_paid_args = [
				'subscription'         => $subscription,
				'subscription_item'    => $subscription_item,
				'include_sign_up_fees' => $include_sign_up_fees,
				'orders_to_include'    => $orders_to_include,
			];
			return $wcs_mock_total_paid_including_signup_fee ?? 0;
		}
	}
}

if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
	/**
	 * Mock of WC_Subscriptions_Product.
	 *
	 * The get_sign_up_fee() method reads the `_subscription_sign_up_fee` meta
	 * from the product so tests can stage variations with specific sign-up fees.
	 */
	class WC_Subscriptions_Product {
		public static function get_sign_up_fee( $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ) {
				return 0;
			}
			return (float) $product->get_meta( '_subscription_sign_up_fee' );
		}
		public static function get_price( $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ) {
				return 0;
			}
			return (float) $product->get_meta( '_subscription_price' );
		}
	}
}

/**
 * Test double for WCS_Switch_Cart_Item exposing only the surface that the
 * stepped-pricing sign-up fee filter reads from.
 */
class Mock_WCS_Switch_Cart_Item_For_Stepped_Pricing {
	public $subscription;
	public $existing_item;
	public $product;
	private $values;
	public function __construct( $sub, $item, $product, $values ) {
		$this->subscription  = $sub;
		$this->existing_item = $item;
		$this->product       = $product;
		$this->values        = $values;
	}
	public function get_total_paid_for_current_period() {
		return (float) $this->values['total_paid'];
	}
	public function get_days_in_old_cycle() {
		return (int) $this->values['days_in_old_cycle'];
	}
	public function get_days_until_next_payment() {
		return (int) $this->values['days_until_next'];
	}
	public function trial_periods_match() {
		return ! empty( $this->values['trial_periods_match'] );
	}
	public function is_switch_to_one_payment_subscription() {
		return ! empty( $this->values['one_payment'] );
	}
}

/**
 * Test double for an older WCS_Switch_Cart_Item that predates the
 * trial_periods_match() and is_switch_to_one_payment_subscription() methods.
 * Used to verify the integration fails safe (passes through) when it cannot
 * confirm those conditions on the running WCS version.
 */
class Mock_WCS_Switch_Cart_Item_Legacy {
	public $subscription;
	public $existing_item;
	public $product;
	private $values;
	public function __construct( $sub, $item, $product, $values = [] ) {
		$this->subscription  = $sub;
		$this->existing_item = $item;
		$this->product       = $product;
		$this->values        = $values;
	}
	public function get_total_paid_for_current_period() {
		return (float) ( $this->values['total_paid'] ?? 0 );
	}
	public function get_days_in_old_cycle() {
		return (int) ( $this->values['days_in_old_cycle'] ?? 30 );
	}
	public function get_days_until_next_payment() {
		return (int) ( $this->values['days_until_next'] ?? 30 );
	}
}

function wc_create_order( $data ) {
	return new WC_Order( $data );
}
function wc_get_checkout_url() {
	return 'https://example.com/checkout';
}
function wcs_is_subscription( $order ) {
	global $subscriptions_database;
	// Mirror real WooCommerce Subscriptions: only an actual WC_Subscription object
	// (or a numeric ID present in the store) counts as a subscription. In particular
	// a WP_Post — which WP core passes as the second `add_meta_boxes` argument on the
	// classic (non-HPOS) order editor — is NOT a subscription here, just as it isn't
	// under real WCS. That distinction is what the metabox-registration guard must
	// resolve, so the mock must not paper over it.
	if ( is_object( $order ) ) {
		if ( ! $order instanceof WC_Subscription ) {
			return false;
		}
		$id = $order->get_id();
	} else {
		$id = (int) $order;
	}
	return isset( $subscriptions_database[ $id ] );
}
function wcs_create_subscription( $data = [] ) {
	global $subscriptions_database;
	// Auto-generate an ID if not provided.
	if ( ! isset( $data['id'] ) ) {
		$data['id'] = count( $subscriptions_database ) + 1;
	}
	$subscription = new WC_Subscription( $data );
	$subscriptions_database[ $subscription->get_id() ] = $subscription;
	return $subscription;
}
function wcs_get_subscription( $subscription_id ) {
	global $subscriptions_database;
	return $subscriptions_database[ $subscription_id ] ?? null;
}
function wcs_get_objects_property( $object, $property ) {
	if ( ! is_object( $object ) ) {
		return null;
	}
	if ( method_exists( $object, 'get_meta' ) ) {
		// Real WC convention: _subscription_switch_data => 'subscription_switch_data'.
		$meta = $object->get_meta( '_' . $property );
		if ( ! empty( $meta ) ) {
			return $meta;
		}
	}
	return null;
}
function wcs_get_subscriptions_for_order( $order, $args = [] ) {
	global $subscriptions_database;
	if ( ! $order instanceof \WC_Order ) {
		return [];
	}
	$subscription_id = (int) $order->get_meta( '_subscription_renewal' );
	if ( $subscription_id <= 0 || ! isset( $subscriptions_database[ $subscription_id ] ) ) {
		return [];
	}
	return [ $subscriptions_database[ $subscription_id ] ];
}

function wcs_order_contains_renewal( $order ) {
	// @todo Migrate `teams-for-memberships-mocks.php` to set `_subscription_renewal` meta on its
	// fixture orders, then drop this $GLOBALS shim. Until then, honor the legacy global so
	// existing teams tests keep passing.
	if ( isset( $GLOBALS['teams_mock_is_renewal'] ) ) {
		return ! empty( $GLOBALS['teams_mock_is_renewal'] );
	}
	if ( ! $order instanceof \WC_Order ) {
		return false;
	}
	return (int) $order->get_meta( '_subscription_renewal' ) > 0;
}
function wcs_get_users_subscriptions( $user_id ) {
	global $subscriptions_database;
	$user_subscriptions = [];
	foreach ( $subscriptions_database as $id => $subscription ) {
		if ( $subscription->get_customer_id() === $user_id ) {
			$user_subscriptions[ $id ] = $subscription;
		}
	}
	// Mirror the production filter so callers see the same surface the live
	// WCS function exposes (e.g. inject_member_group_subscriptions can inject
	// subs the user is only a member of). Tests that need ownership semantics
	// must guard against this just like production code.
	return apply_filters( 'wcs_get_users_subscriptions', $user_subscriptions, $user_id );
}
function wcs_get_subscriptions( $args = [] ) {
	// Minimal mock: implements only the `customer_id` filter, the sole arg the code
	// under test passes. If a future test needs status/paging args
	// (subscription_status, subscriptions_per_page, paged, offset), extend the filter
	// here rather than relying on this returning the full set.
	global $subscriptions_database;
	$customer_id = $args['customer_id'] ?? null;
	$matches     = [];
	foreach ( $subscriptions_database as $id => $subscription ) {
		if ( null === $customer_id || $subscription->get_customer_id() === $customer_id ) {
			$matches[ $id ] = $subscription;
		}
	}
	return $matches;
}
function wcs_get_canonical_product_id( $item ) {
	if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
		// Real WCS: the variation ID is the canonical ID when present.
		if ( method_exists( $item, 'get_variation_id' ) && $item->get_variation_id() ) {
			return $item->get_variation_id();
		}
		return $item->get_product_id();
	}
	return null;
}
function wcs_get_days_in_cycle( $period, $interval ) {
	$days_per_period = [
		'day'   => 1,
		'week'  => 7,
		'month' => 30,
		'year'  => 365,
	];
	return ( $days_per_period[ $period ] ?? 0 ) * (int) $interval;
}
function wcs_get_order_item( $item_id, $subscription ) {
	global $wcs_mock_order_items;
	return $wcs_mock_order_items[ $item_id ] ?? null;
}
function wc_string_to_bool( $string ) {
	return is_bool( $string ) ? $string : ( 'yes' === strtolower( $string ) || '1' === $string || 'true' === strtolower( $string ) );
}
function wc_bool_to_string( $bool ) {
	return $bool ? 'yes' : 'no';
}
function wc_clean( $var ) {
	if ( is_array( $var ) ) {
		return array_map( 'wc_clean', $var );
	}
	return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
}
function wc_prices_include_tax() {
	global $wcs_mock_prices_include_tax;
	return ! empty( $wcs_mock_prices_include_tax );
}
function wc_get_orders( $args ) {
	global $orders_database;
	// For simplicity, this mock will only return a single page of results.
	if ( isset( $args['page'] ) && $args['page'] > 1 ) {
		return [];
	}
	$orders = $orders_database;
	if ( isset( $args['customer_id'] ) ) {
		// Filter by customer.
		$orders = array_filter(
			$orders_database,
			function( $order ) use ( $args ) {
				return $order->get_customer_id() === $args['customer_id'];
			}
		);
	}
	if ( isset( $args['status'] ) ) {
		// Filter by status.
		$orders = array_filter(
			$orders_database,
			function( $order ) use ( $args ) {
				return 'wc-' . $order->get_status() === $args['status'][0];
			}
		);
	}
	usort(
		$orders,
		function( $a, $b ) {
			return $b->get_date_paid()->getTimestamp() <=> $a->get_date_paid()->getTimestamp();
		}
	);
	return $orders;
}

function wc_customer_bought_product( $customer_email, $user_id, $product_id ) {
	global $orders_database;
	foreach ( $orders_database as $order ) {
		if ( $order->get_customer_id() !== $user_id ) {
			continue;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_product_id() === $product_id ) {
				return true;
			}
		}
	}
	return false;
}
function wc_get_order( $order_id ) {
	global $orders_database, $subscriptions_database;
	foreach ( $orders_database as $order ) {
		if ( $order->get_id() === $order_id ) {
			return $order;
		}
	}
	// Real WC: WC_Subscription extends WC_Order, so wc_get_order resolves a subscription ID too.
	if ( isset( $subscriptions_database[ $order_id ] ) ) {
		return $subscriptions_database[ $order_id ];
	}
	return false;
}
function wc_get_product( $product_id ) {
	global $products_database;
	return $products_database[ $product_id ] ?? false;
}
function wcs_get_subscription_status_name( $status ) {
	return ucfirst( $status );
}
function wcs_get_all_user_actions_for_subscription( $subscription, $user_id ) {
	return apply_filters( 'wcs_view_subscription_actions', [], $subscription, $user_id );
}
if ( ! class_exists( 'WC_CSV_Exporter' ) ) {
	/**
	 * Mock of WooCommerce's WC_CSV_Exporter abstract (includes/export/abstract-wc-csv-exporter.php).
	 *
	 * Replicates the column handling, escaping, and row-formatting semantics of the
	 * real class so Newspack exporter subclasses can be unit-tested without WC.
	 * File-writing and header-sending surfaces are intentionally omitted; the
	 * mock_export_rows() / get_prepared_row_data() / get_mock_total_rows() helpers
	 * are test-only accessors that do not exist on the real class.
	 */
	abstract class WC_CSV_Exporter {
		protected $export_type       = '';
		protected $filename          = 'wc-export.csv';
		protected $limit             = 50;
		protected $exported_row_count = 0;
		protected $row_data          = [];
		protected $total_rows        = 0;
		protected $column_names      = [];
		protected $columns_to_export = [];
		protected $delimiter         = ',';

		abstract public function prepare_data_to_export();

		public function get_column_names() {
			return apply_filters( "woocommerce_{$this->export_type}_export_column_names", $this->column_names, $this );
		}
		public function set_column_names( $column_names ) {
			$this->column_names = [];
			foreach ( $column_names as $column_id => $column_name ) {
				$this->column_names[ wc_clean( $column_id ) ] = wc_clean( $column_name );
			}
		}
		public function get_columns_to_export() {
			return $this->columns_to_export;
		}
		public function get_delimiter() {
			return apply_filters( "woocommerce_{$this->export_type}_export_delimiter", $this->delimiter );
		}
		public function set_columns_to_export( $columns ) {
			$this->columns_to_export = array_map( 'wc_clean', $columns );
		}
		public function is_column_exporting( $column_id ) {
			$column_id         = strstr( $column_id, ':' ) ? current( explode( ':', $column_id ) ) : $column_id;
			$columns_to_export = $this->get_columns_to_export();
			if ( empty( $columns_to_export ) ) {
				return true;
			}
			return in_array( $column_id, $columns_to_export, true ) || 'meta' === $column_id;
		}
		public function get_default_column_names() {
			return [];
		}
		public function set_filename( $filename ) {
			$this->filename = sanitize_file_name( str_replace( '.csv', '', $filename ) . '.csv' );
		}
		public function get_filename() {
			return sanitize_file_name( apply_filters( "woocommerce_{$this->export_type}_export_get_filename", $this->filename ) );
		}
		protected function get_csv_data() {
			return $this->export_rows();
		}
		protected function export_column_headers() {
			$columns    = $this->get_column_names();
			$export_row = [];
			$buffer     = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
			ob_start();
			foreach ( $columns as $column_id => $column_name ) {
				if ( ! $this->is_column_exporting( $column_id ) ) {
					continue;
				}
				$export_row[] = $this->format_data( $column_name );
			}
			$this->fputcsv( $buffer, $export_row );
			return ob_get_clean();
		}
		protected function get_data_to_export() {
			return $this->row_data;
		}
		protected function export_rows() {
			$data   = $this->get_data_to_export();
			$buffer = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
			ob_start();
			array_walk( $data, [ $this, 'export_row' ], $buffer );
			return apply_filters( "woocommerce_{$this->export_type}_export_rows", ob_get_clean(), $this );
		}
		protected function export_row( $row_data, $key, $buffer ) {
			$columns    = $this->get_column_names();
			$export_row = [];
			foreach ( $columns as $column_id => $column_name ) {
				if ( ! $this->is_column_exporting( $column_id ) ) {
					continue;
				}
				if ( isset( $row_data[ $column_id ] ) ) {
					$export_row[] = $this->format_data( $row_data[ $column_id ] );
				} else {
					$export_row[] = '';
				}
			}
			$this->fputcsv( $buffer, $export_row );
			++$this->exported_row_count;
		}
		public function get_limit() {
			return apply_filters( "woocommerce_{$this->export_type}_export_batch_limit", $this->limit, $this );
		}
		public function set_limit( $limit ) {
			$this->limit = absint( $limit );
		}
		public function get_total_exported() {
			return $this->exported_row_count;
		}
		public function escape_data( $data ) {
			$active_content_triggers = [ '=', '+', '-', '@', chr( 0x09 ), chr( 0x0d ) ];
			if ( is_int( $data ) || is_float( $data ) ) {
				return $data;
			}
			if ( in_array( mb_substr( $data, 0, 1 ), $active_content_triggers, true ) ) {
				$data = "'" . $data;
			}
			return $data;
		}
		public function format_data( $data ) {
			if ( ! is_scalar( $data ) ) {
				if ( is_a( $data, 'WC_Datetime' ) ) {
					$data = $data->date( 'Y-m-d G:i:s' );
				} else {
					$data = '';
				}
			} elseif ( is_bool( $data ) ) {
				$data = $data ? 1 : 0;
			}
			return $this->escape_data( $data );
		}
		protected function implode_values( $values ) {
			$values_to_implode = [];
			foreach ( $values as $value ) {
				$value               = (string) is_scalar( $value ) ? html_entity_decode( $value, ENT_QUOTES ) : '';
				$values_to_implode[] = str_replace( ',', '\\,', $value );
			}
			return implode( ', ', $values_to_implode );
		}
		protected function fputcsv( $buffer, $export_row ) {
			fputcsv( $buffer, $export_row, $this->get_delimiter(), '"', "\0" ); // phpcs:ignore
		}

		/** Test-only accessors below (not present on the real WC_CSV_Exporter). */
		public function mock_export_rows() {
			return $this->export_rows();
		}
		public function test_set_row_data( $rows ) {
			$this->row_data = $rows;
		}
		public function get_prepared_row_data() {
			return $this->row_data;
		}
		public function get_mock_total_rows() {
			return $this->total_rows;
		}
	}
}

if ( ! class_exists( 'WC_CSV_Batch_Exporter' ) ) {
	/**
	 * Mock of WC_CSV_Batch_Exporter (includes/export/abstract-wc-csv-batch-exporter.php).
	 * Paging/percent semantics match the real class; file operations are omitted.
	 */
	abstract class WC_CSV_Batch_Exporter extends WC_CSV_Exporter {
		protected $page = 1;
		public function __construct() {
			$this->column_names = $this->get_default_column_names();
		}
		public function get_page() {
			return $this->page;
		}
		public function set_page( $page ) {
			$this->page = absint( $page );
		}
		public function get_total_exported() {
			return ( ( $this->get_page() - 1 ) * $this->get_limit() ) + $this->exported_row_count;
		}
		public function get_percent_complete() {
			return $this->total_rows ? (int) floor( ( $this->get_total_exported() / $this->total_rows ) * 100 ) : 100;
		}
		public function generate_file() {
			$this->prepare_data_to_export();
			$this->export_rows();
		}
		public function get_headers_row_file() {
			$file = chr( 239 ) . chr( 187 ) . chr( 191 ) . $this->export_column_headers();
			if ( file_exists( $this->get_headers_row_file_path() ) ) {
				$file = file_get_contents( $this->get_headers_row_file_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			}
			return $file;
		}
		protected function get_headers_row_file_path() {
			return $this->get_file_path() . '.headers';
		}
		protected function get_file_path() {
			return trailingslashit( sys_get_temp_dir() ) . $this->get_filename();
		}
	}
}

if ( ! class_exists( 'WCS_Customer_Store' ) ) {
	/**
	 * Mock of WCS_Customer_Store. Drive get_users_subscription_ids() via the
	 * static $mock_user_subscription_ids fixture map ( user_id => int[] ).
	 */
	class WCS_Customer_Store {
		public static $mock_user_subscription_ids = [];
		public static function instance() {
			return new self();
		}
		public function get_users_subscription_ids( $user_id ) {
			return self::$mock_user_subscription_ids[ $user_id ] ?? [];
		}
	}
}

if ( ! class_exists( 'WCS_Admin_Post_Types' ) ) {
	/**
	 * Mock of WCS_Admin_Post_Types exposing set_post__in_query_var() with the
	 * real class's intersect/none semantics ($post__in_none = [ 0 ]).
	 */
	class WCS_Admin_Post_Types {
		public static function set_post__in_query_var( $query_vars, $post_ids ) {
			$post__in_none = [ 0 ];
			if ( empty( $post_ids ) ) {
				$query_vars['post__in'] = $post__in_none;
			} elseif ( ! isset( $query_vars['post__in'] ) ) {
				$query_vars['post__in'] = $post_ids;
			} elseif ( $post__in_none !== $query_vars['post__in'] ) {
				$intersecting_post_ids  = array_intersect( $query_vars['post__in'], $post_ids );
				$query_vars['post__in'] = empty( $intersecting_post_ids ) ? $post__in_none : $intersecting_post_ids;
			}
			return $query_vars;
		}
	}
}

function wcs_get_subscription_statuses() {
	return [
		'wc-pending'        => 'Pending',
		'wc-active'         => 'Active',
		'wc-on-hold'        => 'On hold',
		'wc-cancelled'      => 'Cancelled',
		'wc-switched'       => 'Switched',
		'wc-expired'        => 'Expired',
		'wc-pending-cancel' => 'Pending Cancellation',
	];
}
function wcs_sanitize_subscription_status_key( $status_key ) {
	$status_key = sanitize_key( $status_key );
	return 'wc-' === substr( $status_key, 0, 3 ) ? $status_key : 'wc-' . $status_key;
}
function wcs_get_subscriptions_for_product( $product_id ) {
	global $wcs_mock_subscriptions_for_product;
	return $wcs_mock_subscriptions_for_product[ $product_id ] ?? [];
}
function wcs_subscription_search( $term ) {
	global $wcs_mock_subscription_search_results;
	return $wcs_mock_subscription_search_results[ $term ] ?? [];
}
function wcs_is_custom_order_tables_usage_enabled() {
	global $wcs_mock_hpos_enabled;
	return ! empty( $wcs_mock_hpos_enabled );
}
function wcs_get_orders_with_meta_query( $args ) {
	global $wcs_mock_orders_with_meta_query_args, $wcs_mock_orders_with_meta_query_result, $subscriptions_database;
	$wcs_mock_orders_with_meta_query_args = $args;
	if ( isset( $wcs_mock_orders_with_meta_query_result ) ) {
		return $wcs_mock_orders_with_meta_query_result;
	}
	// Default: page the mock subscriptions database honoring limit/offset,
	// mirroring wc_get_orders' paginate => true return shape.
	$ids    = array_keys( $subscriptions_database );
	$total  = count( $ids );
	$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
	$limit  = isset( $args['limit'] ) && (int) $args['limit'] > 0 ? (int) $args['limit'] : $total;
	$ids    = array_slice( $ids, $offset, $limit );
	if ( ! empty( $args['paginate'] ) ) {
		return (object) [
			'orders'        => $ids,
			'total'         => $total,
			'max_num_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 1,
		];
	}
	return $ids;
}

function wc_get_template( $template_name, $args = [] ) {
	$plugin_dir   = dirname( __DIR__, 2 );
	$templates_dir = $plugin_dir . '/includes/plugins/woocommerce/my-account/templates/v1/';
	$map = [
		'myaccount/group-picker.php' => $templates_dir . 'group-picker.php',
		'myaccount/group.php'        => $templates_dir . 'group.php',
	];
	if ( isset( $map[ $template_name ] ) && file_exists( $map[ $template_name ] ) ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $args );
		include $map[ $template_name ];
	}
}

/**
 * Minimal mock for WC_Webhook. generate_signature() mirrors the real
 * WC_Webhook::generate_signature() (default sha256; the
 * woocommerce_webhook_hash_algorithm filter is not applied) so signature
 * verification can be tested.
 */
class WC_Webhook {
	public static $registry = [];
	private $id     = 0;
	private $secret = '';
	public function set_name( $value ) {}
	public function set_topic( $value ) {}
	public function set_status( $value ) {}
	public function set_delivery_url( $value ) {}
	public function set_user_id( $value ) {}
	public function set_secret( $value ) {
		$this->secret = $value;
	}
	public function delete( $force = false ) {
		unset( self::$registry[ $this->id ] );
	}
	public function get_id() {
		return $this->id;
	}
	public function get_secret() {
		return $this->secret;
	}
	public function save() {
		if ( ! $this->id ) {
			$this->id = count( self::$registry ) + 1;
		}
		self::$registry[ $this->id ] = $this;
		return $this->id;
	}
	public function generate_signature( $payload ) {
		return base64_encode( hash_hmac( 'sha256', $payload, wp_specialchars_decode( $this->secret, ENT_QUOTES ), true ) );
	}
}
function wc_get_webhook( $id ) {
	return isset( WC_Webhook::$registry[ (int) $id ] ) ? WC_Webhook::$registry[ (int) $id ] : null;
}
