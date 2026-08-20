<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

// WooCommerce's plugin path constant. Code under test uses it both to locate
// WC files and as an "is WooCommerce loaded" proxy; the mocks below stand in
// for the files themselves, so nothing ever reads the path.
if ( ! defined( 'WC_ABSPATH' ) ) {
	define( 'WC_ABSPATH', '/woocommerce/' );
}

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

class WC_Payment_Token_CC extends WC_Payment_Token {
	private $card_type;
	private $last4;
	private $token;
	private $user_id;
	public function __construct( $card_type = '', $last4 = '', $token = '', $user_id = 0, $gateway_id = '' ) {
		parent::__construct( $gateway_id );
		$this->card_type = $card_type;
		$this->last4     = $last4;
		$this->token     = $token;
		$this->user_id   = $user_id;
	}
	public function get_card_type() {
		return $this->card_type;
	}
	public function get_last4() {
		return $this->last4;
	}
	public function get_token() {
		return $this->token;
	}
	public function get_user_id() {
		return $this->user_id;
	}
}

class WC_Payment_Tokens {
	public static $tokens = [];
	public static function get( $token_id ) {
		return self::$tokens[ $token_id ] ?? null;
	}
	/**
	 * Faithful to WC_Payment_Tokens::get_customer_tokens(): customers below 1
	 * (guests) get an empty array, and a non-empty $gateway_id restricts the
	 * result to that gateway's tokens.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $gateway_id  Optional gateway ID filter.
	 */
	public static function get_customer_tokens( $customer_id, $gateway_id = '' ) {
		if ( $customer_id < 1 ) {
			return [];
		}
		return array_filter(
			self::$tokens,
			function ( $token ) use ( $customer_id, $gateway_id ) {
				if ( ! method_exists( $token, 'get_user_id' ) || (int) $token->get_user_id() !== (int) $customer_id ) {
					return false;
				}
				return '' === $gateway_id || $token->get_gateway_id() === $gateway_id;
			}
		);
	}
}

/**
 * Faithful to wc_get_credit_card_type_label(): normalizes case and -/_ to
 * spaces, maps known types through the labels array (note the real map keys on
 * "american express", not Stripe's "amex" slug), and falls back to ucwords.
 * The woocommerce_credit_card_type_labels / woocommerce_get_credit_card_type_label
 * filters are not applied.
 *
 * @param string $type Card type slug.
 */
function wc_get_credit_card_type_label( $type ) {
	$type   = strtolower( str_replace( [ '-', '_' ], ' ', (string) $type ) );
	$labels = [
		'mastercard'       => 'MasterCard',
		'visa'             => 'Visa',
		'discover'         => 'Discover',
		'american express' => 'American Express',
		'cartes bancaires' => 'Cartes Bancaires',
		'diners'           => 'Diners',
		'jcb'              => 'JCB',
	];
	return $labels[ $type ] ?? ucwords( $type );
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

class WC_Stripe_Helper {
	public static $settings     = [];
	public static $update_calls = 0;
	public static function get_stripe_settings() {
		return self::$settings;
	}
	public static function update_main_stripe_settings( $options ) {
		self::$settings = $options;
		self::$update_calls++;
	}
	public static function reset_testing_settings() {
		self::$settings     = [];
		self::$update_calls = 0;
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
	public function date_i18n( $format = 'Y-m-d' ) {
		return $this->date( $format );
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
$order_items_database = [];
$wc_mock_notices = [];
// Mock registry: product_id => array of grouped-parent product IDs (NPPM-2926).
global $wcs_grouped_parents;
$wcs_grouped_parents = [];

/**
 * Reset the order-item lookup table.
 *
 * Every WC_Order_Item_Product construction across the whole suite registers
 * itself in $order_items_database (mirroring WooCommerce, where order item IDs
 * are globally unique), and test fixtures reuse low integer IDs across files —
 * call this from a test class's set_up() before staging order items so a stale
 * item created by an unrelated suite can't resolve.
 */
function wc_mocks_reset_order_items() {
	global $order_items_database;
	$order_items_database = [];
}

/**
 * Reset the notices recorded by the wc_add_notice() mock.
 *
 * The mock is defined unconditionally, which flips every
 * function_exists( 'wc_add_notice' ) gate in production code from "skip" to
 * "execute" for the whole suite — any test exercising such a path should call
 * this from set_up() so it never asserts against another test's notices.
 */
function wc_mocks_reset_notices() {
	global $wc_mock_notices;
	$wc_mock_notices = [];
}

/**
 * Stand-in for WooCommerce's WC_Data_Exception, thrown by data setters that
 * reject their input.
 */
class WC_Data_Exception extends Exception {
	/**
	 * Machine-readable error code.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 */
	public function __construct( $code, $message ) {
		$this->error_code = $code;
		parent::__construct( $message );
	}

	/**
	 * Machine-readable error code.
	 *
	 * @return string
	 */
	public function getErrorCode() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->error_code;
	}
}

class WC_Order_Item_Product implements ArrayAccess {
	private $data = [];
	private $meta = [];
	public function __construct( $data = [] ) {
		$this->data = $data;
		if ( isset( $data['meta'] ) ) {
			$this->meta = $data['meta'];
		}
		// Mirror wc_order_items: order item IDs are globally unique, and
		// WC_Order_Factory::get_order_item() resolves them without an order scope.
		if ( ! empty( $data['id'] ) ) {
			global $order_items_database;
			$order_items_database[ (int) $data['id'] ] = $this;
		}
	}
	/**
	 * Real WC_Order_Item implements ArrayAccess with an asymmetry this mock
	 * must preserve: offsetGet() resolves getter-backed virtual keys (so
	 * `$item['type']` returns 'line_item'), but offsetExists() reports only
	 * keys actually present in the item's data — `isset( $item['type'] )` is
	 * FALSE on a real order item. Consumers must therefore read such keys
	 * bare, never behind isset()/`??`.
	 *
	 * @param mixed $offset Array key.
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return array_key_exists( $offset, $this->data );
	}
	/**
	 * Array read, delegated to the matching getter like real WC_Order_Item.
	 *
	 * @param mixed $offset Array key.
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		if ( is_callable( [ $this, "get_$offset" ] ) ) {
			return $this->{"get_$offset"}();
		}
		return $this->data[ $offset ] ?? null;
	}
	/**
	 * Array write, stored on the raw data like real WC_Order_Item's setters.
	 *
	 * @param mixed $offset Array key.
	 * @param mixed $value  Value to set.
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$this->data[ $offset ] = $value;
	}
	/**
	 * Array unset.
	 *
	 * @param mixed $offset Array key.
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->data[ $offset ] );
	}
	/**
	 * Real WC_Order_Item_Product::get_type() is always 'line_item'.
	 */
	public function get_type() {
		return $this->data['type'] ?? 'line_item';
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
	/**
	 * Real WC_Order_Item_Product::set_product_id() rejects any ID that is not a
	 * `product` post — a variation is `product_variation` — by throwing
	 * WC_Data_Exception *before* assigning the prop, so the ID is silently
	 * dropped rather than stored. Modelling that here is what lets a test catch
	 * a variation ID being passed where a parent product ID belongs (NPPD-1876);
	 * a mock that accepted anything made the bug invisible to the suite.
	 *
	 * IDs with no registered mock product are left alone, so fixtures that use
	 * arbitrary product IDs keep working.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @throws WC_Data_Exception If the ID belongs to a variation.
	 */
	public function set_product_id( $product_id ) {
		global $products_database;
		$product = $products_database[ (int) $product_id ] ?? null;
		if ( $product_id > 0 && $product && $product->is_type( 'variation' ) ) {
			throw new WC_Data_Exception( 'order_item_product_invalid_product_id', 'Invalid product ID' );
		}
		$this->data['product_id'] = (int) $product_id;
	}
	public function set_variation_id( $variation_id ) {
		$this->data['variation_id'] = (int) $variation_id;
	}
	public function set_name( $name ) {
		$this->data['name'] = $name;
	}
	public function set_taxes( $taxes ) {
		$this->data['taxes'] = $taxes;
	}
	/**
	 * Mirror of WC_Data::set_props(): call each matching setter, collect any
	 * WC_Data_Exception into a returned WP_Error rather than letting it bubble.
	 * That swallowing is exactly how an invalid product ID reaches the database
	 * as 0 without the caller noticing.
	 *
	 * @param array $props Property => value pairs.
	 *
	 * @return true|WP_Error
	 */
	public function set_props( $props ) {
		$errors = false;
		foreach ( $props as $prop => $value ) {
			$setter = "set_$prop";
			if ( ! is_callable( [ $this, $setter ] ) ) {
				continue;
			}
			try {
				$this->{$setter}( $value );
			} catch ( WC_Data_Exception $e ) {
				if ( ! $errors ) {
					$errors = new WP_Error();
				}
				$errors->add( $e->getErrorCode(), $e->getMessage(), [ 'property_name' => $prop ] );
			}
		}
		return $errors ? $errors : true;
	}
	public function save() {
		return true;
	}
	public function get_id() {
		return $this->data['id'] ?? 0;
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
	/**
	 * Like the real getter, resolve the variation first and fall back to the
	 * parent product, so an item linked to a variation returns the variation.
	 */
	public function get_product() {
		global $products_database;
		$product_id = $this->get_variation_id() ? $this->get_variation_id() : $this->get_product_id();
		return $products_database[ $product_id ] ?? false;
	}
	/**
	 * Real WC_Order_Item_Product::set_product() splits a variation into parent
	 * product ID + variation ID; anything else sets the product ID alone. This
	 * is the only setter that handles a variation correctly, which is why the
	 * migration CLI uses it rather than assigning `product_id` directly.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public function set_product( $product ) {
		if ( $product->is_type( 'variation' ) ) {
			$this->set_product_id( $product->get_parent_id() );
			$this->set_variation_id( $product->get_id() );
		} else {
			$this->set_product_id( $product->get_id() );
		}
		$this->set_name( $product->get_name() );
	}
	public function set_quantity( $quantity ) {
		$this->data['quantity'] = $quantity;
	}
	public function set_subtotal( $subtotal ) {
		$this->data['subtotal'] = $subtotal;
	}
	public function set_total( $total ) {
		$this->data['total'] = $total;
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
	public function get_status() {
		return $this->data['status'] ?? 'publish';
	}
	public function get_type() {
		return $this->data['type'] ?? 'simple';
	}
	/**
	 * WC_Product_Subscription_Variation overrides is_type() so a
	 * `subscription_variation` answers true to `is_type( 'variation' )`. Production
	 * code relies on that alias — WC_Order_Item_Product::set_product() branches on
	 * `is_type( 'variation' )` alone — so the mock has to model it, or a test would
	 * take a different branch than the real code does.
	 *
	 * @param string|string[] $types Type or types to test.
	 *
	 * @return bool
	 */
	public function is_type( $types ) {
		$types = (array) $types;
		if ( 'subscription_variation' === $this->get_type() && in_array( 'variation', $types, true ) ) {
			return true;
		}
		return in_array( $this->get_type(), $types, true );
	}
	public function get_parent_id() {
		return $this->data['parent_id'] ?? 0;
	}
	/**
	 * WC_Product_Variation resolves its permalink through the parent, whose page
	 * is the only one a reader can buy from — the variation post has none. Code
	 * that links a product goes through here rather than get_permalink( $id ),
	 * so the mock has to model that or a test would see a URL production never
	 * emits.
	 *
	 * @return string
	 */
	public function get_permalink() {
		$parent_id = $this->get_parent_id();
		return get_permalink( $parent_id ? $parent_id : $this->get_id() );
	}
	public function get_children() {
		return $this->data['children'] ?? [];
	}
	public function get_regular_price() {
		return $this->data['regular_price'] ?? ( $this->meta['_regular_price'] ?? 0 );
	}
	public function get_price() {
		return $this->data['price'] ?? ( $this->meta['_price'] ?? $this->get_regular_price() );
	}
	public function set_price( $price ) {
		$this->data['price'] = $price;
	}
	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
}

class WC_Cart {
	public $cart_contents = [];
	public function __construct( $cart_contents = [] ) {
		$this->cart_contents = $cart_contents;
	}
	public function get_cart() {
		return $this->cart_contents;
	}
	public function get_cart_item( $key ) {
		return $this->cart_contents[ $key ] ?? [];
	}
}

if ( ! class_exists( 'WC_Subscriptions_Cart' ) ) {
	/**
	 * Minimal WCS cart shim: only the calculation-type flag the dynamic-pricing
	 * surface reads to distinguish the main cart pass from the recurring-totals
	 * projection pass. Deliberately omits get_recurring_cart_key — code paths
	 * guard on method_exists and skip when absent.
	 */
	class WC_Subscriptions_Cart {
		public static $calculation_type = 'none';
		public static function get_calculation_type() {
			return self::$calculation_type;
		}
		public static function set_calculation_type( $type ) {
			self::$calculation_type = $type;
			return $type;
		}
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
		// Real WC supports `new WC_Order( $order_id )` — re-hydrate from the mock DB.
		if ( is_numeric( $data ) ) {
			foreach ( $orders_database as $order ) {
				if ( $order->get_id() === (int) $data ) {
					$this->data = $order->data;
					$this->meta = $order->meta;
					return;
				}
			}
			$this->data = [
				'id'     => (int) $data,
				'status' => '',
				'items'  => [],
			];
			return;
		}
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
	public function get_date_created() {
		// Fall back to date_paid like fixtures that predate the date_created key.
		$date_created = $this->data['date_created'] ?? $this->data['date_paid'];
		if ( empty( $date_created ) ) {
			return null;
		}
		return new WC_DateTime( $date_created );
	}
	public function get_date_completed() {
		return new WC_DateTime( $this->data['date_completed'] );
	}
	/**
	 * Real WC_Abstract_Order returns '0' for an order with no total set; without
	 * a default a fixture that omits it raises an undefined-key warning instead.
	 */
	public function get_total() {
		return $this->data['total'] ?? 0;
	}
	public function get_subtotal() {
		return $this->data['subtotal'] ?? 0;
	}
	public function get_status() {
		return $this->data['status'];
	}
	public function get_coupon_codes() {
		return $this->data['coupon_codes'] ?? [];
	}
	public function update_meta_data( $field_name, $value ) {
		$this->meta[ $field_name ] = $value;
	}
	public function add_meta_data( $field_name, $value, $unique = false ) {
		if ( $unique || ! isset( $this->meta[ $field_name ] ) ) {
			$this->meta[ $field_name ] = $value;
		}
	}
	public function delete_meta_data( $field_name ) {
		unset( $this->meta[ $field_name ] );
	}
	public function meta_exists( $field_name ) {
		return isset( $this->meta[ $field_name ] );
	}
	/**
	 * Counts calls so tests can pin that a meta write is followed by persistence.
	 * The mock cannot model real persistence: wc_get_order() hands back the same
	 * instance, so unsaved meta looks saved. Real WC hydrates a fresh order per
	 * lookup and discards unsaved meta at end of request.
	 *
	 * @var int
	 */
	public $save_calls = 0;
	public function save() {
		$this->save_calls++;
		return true;
	}
	public function get_billing_email() {
		return $this->data['billing_email'] ?? '';
	}
	public function get_currency() {
		return $this->data['currency'] ?? '';
	}
	public function get_payment_method() {
		return $this->data['payment_method'] ?? '';
	}
	public function get_payment_method_title() {
		return $this->data['payment_method_title'] ?? '';
	}
	public function get_payment_tokens() {
		return $this->data['payment_tokens'] ?? [];
	}
	public function get_billing_first_name() {
		return $this->data['billing_first_name'] ?? '';
	}
	public function get_billing_last_name() {
		return $this->data['billing_last_name'] ?? '';
	}
	public function get_formatted_order_total() {
		return '$' . number_format( (float) $this->get_total(), 2 );
	}
	public function get_view_order_url() {
		return $this->data['view_order_url'] ?? 'https://example.test/my-account/view-order/' . $this->get_id();
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
	/**
	 * Stageable stand-in for WC_Subscription::payment_method_supports(): pass a
	 * `payment_method_supports` array of supported features to restrict them;
	 * without it every feature is supported.
	 *
	 * @param string $feature Payment gateway feature to check.
	 */
	public function payment_method_supports( $feature ) {
		if ( isset( $this->data['payment_method_supports'] ) ) {
			return in_array( $feature, (array) $this->data['payment_method_supports'], true );
		}
		return true;
	}
	/**
	 * Real WC_Subscription::has_product() walks the line items and matches either
	 * the product ID or the variation ID — which is what lets a rule naming a
	 * variable subscription's parent accept any of its variations. Check the items
	 * first so code depending on that matching behaves as it does in production,
	 * then fall back to the fixture-supplied `products` list, which many tests use
	 * to declare coverage without building line items.
	 *
	 * @param int $product_id Product or variation ID.
	 *
	 * @return bool
	 */
	public function has_product( $product_id ) {
		$product_id = (int) $product_id;
		foreach ( $this->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			if ( (int) $item->get_product_id() === $product_id || (int) $item->get_variation_id() === $product_id ) {
				return true;
			}
		}
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
	public function get_edit_order_url() {
		return admin_url( 'post.php?post=' . $this->get_id() . '&action=edit' );
	}
	public function get_date_paid() {
		return new WC_DateTime( $this->data['date_paid'] );
	}
	/**
	 * Real WC_Abstract_Order returns '0' when no total is set; without a default
	 * a fixture that omits it raises an undefined-key warning instead. Production
	 * code now reads this for every reused subscription.
	 */
	public function get_total() {
		return $this->data['total'] ?? 0;
	}
	/**
	 * Pre-discount total. Distinguishes a fully-discounted subscription, which
	 * still carries a subtotal, from a $0 migration subscription, which does not.
	 */
	public function get_subtotal() {
		return $this->data['subtotal'] ?? 0;
	}
	public function get_status() {
		return $this->data['status'];
	}
	public function set_status( $status ) {
		$this->data['status'] = $status;
	}
	public function get_created_via() {
		return $this->data['created_via'] ?? '';
	}
	public function set_total( $total ) {
		$this->data['total'] = $total;
	}
	/**
	 * Real WC_Abstract_Order keys its items by order-item ID, which is what makes
	 * `remove_item( $item->get_id() )` work. Preserve that when the fixture gave
	 * the item an ID; fall back to appending for the many fixtures that don't, so
	 * their positional keys keep working.
	 *
	 * @param WC_Order_Item_Product $item Line item.
	 */
	public function add_item( $item ) {
		$item_id = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : 0;
		if ( $item_id ) {
			$this->data['items'][ $item_id ] = $item;
			return;
		}
		$this->data['items'][] = $item;
	}
	/**
	 * Stageable stand-in for WC_Subscription::can_be_updated_to(): pass a
	 * `can_update_to` array of allowed target statuses to restrict transitions;
	 * without it every transition is allowed (real gating lives in WCS).
	 *
	 * @param string $status Target status.
	 */
	public function can_be_updated_to( $status ) {
		if ( isset( $this->data['can_update_to'] ) ) {
			return in_array( $status, (array) $this->data['can_update_to'], true );
		}
		return true;
	}
	/**
	 * Recording stand-in for WC_Subscription::update_status(): applies the
	 * status and records the transition with its note on `status_updates`.
	 *
	 * @param string $status New status.
	 * @param string $note   Optional transition note.
	 */
	public function update_status( $status, $note = '' ) {
		$this->data['status']           = $status;
		$this->data['status_updates'][] = [
			'status' => $status,
			'note'   => $note,
		];
	}
	public function add_order_note( $note ) {
		$this->data['order_notes'][] = $note;
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
	/**
	 * Keyed by item ID like WC_Abstract_Order::remove_item().
	 *
	 * @param int $item_id Order item ID.
	 */
	public function remove_item( $item_id ) {
		unset( $this->data['items'][ $item_id ] );
	}
	/**
	 * Sum the line items into the order total, like WC_Abstract_Order does.
	 */
	public function calculate_totals() {
		$total = 0;
		foreach ( $this->get_items() as $item ) {
			$total += (float) $item->get_total();
		}
		$this->data['total'] = $total;
		return $total;
	}
	/**
	 * Address setter. The mock stores address fields flat, matching how the
	 * address getters in __call() read them.
	 *
	 * @param array  $address Address fields.
	 * @param string $type    'billing' or 'shipping'.
	 */
	public function set_address( $address, $type = 'billing' ) {
		foreach ( $address as $field => $value ) {
			$this->data[ $type . '_' . $field ] = $value;
		}
	}
	public function set_billing_period( $period ) {
		$this->data['billing_period'] = $period;
	}
	public function set_billing_interval( $interval ) {
		$this->data['billing_interval'] = $interval;
	}
	public function get_item( $item_id, $load_from_db = true ) {
		// Faithful to WC_Abstract_Order::get_item(): the default delegates to
		// WC_Order_Factory::get_order_item(), a *global* lookup that resolves an
		// order item belonging to any order — only $load_from_db = false scopes
		// the search to this order's own items.
		if ( $load_from_db ) {
			global $order_items_database;
			return $order_items_database[ (int) $item_id ] ?? false;
		}
		foreach ( $this->get_items() as $item ) {
			if ( (int) $item->get_id() === (int) $item_id ) {
				return $item;
			}
		}
		return false;
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
		// Real WC_Subscription keys this 'requires_manual_renewal'; fixtures also stage the shorter 'is_manual'.
		return ! empty( $this->data['requires_manual_renewal'] ) || ! empty( $this->data['is_manual'] );
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
		/**
		 * Stageable: set the $wcs_mock_cart_switches global to an array of
		 * switch-details arrays (keyed by cart item key, each carrying at least
		 * `subscription_id`) to simulate a cart holding switch items. Defaults
		 * to false — no switches — like an empty cart.
		 *
		 * @param string $item_action Type of switch items to include (ignored).
		 */
		public static function cart_contains_switches( $item_action = 'any' ) {
			unset( $item_action );
			global $wcs_mock_cart_switches;
			return $wcs_mock_cart_switches ?? false;
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
		public static function get_visible_grouped_parent_product_ids( $product ) {
			global $wcs_grouped_parents;
			$id = is_object( $product ) ? $product->get_id() : (int) $product;
			return $wcs_grouped_parents[ $id ] ?? [];
		}
		public static function is_subscription( $product ) {
			return is_object( $product ) && method_exists( $product, 'get_type' )
				&& in_array( $product->get_type(), [ 'subscription', 'variable-subscription', 'subscription_variation' ], true );
		}
		public static function get_period( $product ) {
			$period = is_object( $product ) && method_exists( $product, 'get_meta' ) ? $product->get_meta( '_subscription_period' ) : '';
			return $period ? $period : 'month';
		}
		public static function get_interval( $product ) {
			$interval = is_object( $product ) && method_exists( $product, 'get_meta' ) ? (int) $product->get_meta( '_subscription_period_interval' ) : 0;
			return $interval > 0 ? $interval : 1;
		}
		/**
		 * Minimal mirror of WCS's price-string builder — enough to derive a
		 * locale-stable suffix in tests. Real WCS returns localized text via
		 * `wcs_price_string`; we just need a placeholder-substitutable format.
		 *
		 * @param WC_Product $product The subscription product.
		 * @param array      $include Optional. Price-string options ('price', 'subscription_period').
		 * @return string
		 */
		public static function get_price_string( $product, $include = [] ) {
			$price    = isset( $include['price'] ) ? (string) $include['price'] : '';
			$include_period = ! array_key_exists( 'subscription_period', $include ) || $include['subscription_period'];
			$suffix = '';
			if ( $include_period ) {
				$interval = self::get_interval( $product );
				$period   = self::get_period( $product );
				$suffix   = 1 === $interval ? sprintf( ' / %s', $period ) : sprintf( ' every %d %ss', $interval, $period );
			}
			return $price . $suffix;
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
	// The mock reuses subscription IDs across tests (each test resets
	// $subscriptions_database, so IDs restart at 1). Group_Subscription memoizes
	// managers/members per request keyed by subscription ID, so a (re)created
	// subscription must invalidate that cache or a later test reading the reused ID
	// would see the previous test's cached data. No-op in production, where
	// subscription IDs are unique post IDs that are never reissued.
	if ( class_exists( '\Newspack\Group_Subscription' ) ) {
		\Newspack\Group_Subscription::reset_cache();
	}
	return $subscription;
}
function wcs_get_product_limitation( $product ) {
	// Real WCS reads the product's _subscription_limit setting: 'no' | 'active' | 'any'.
	$limitation = is_object( $product ) && method_exists( $product, 'get_meta' ) ? $product->get_meta( '_subscription_limit' ) : '';
	return '' !== $limitation ? $limitation : 'no';
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
	// Minimal mock: implements the `customer_id` and `subscription_status` filters
	// plus `subscriptions_per_page`/`offset` paging — the args the code under test
	// passes. `subscription_status` accepts a single status or an array; 'any' (or
	// unset) means no status filter. `meta_query` and `orderby` are still ignored —
	// extend here rather than relying on this returning the full set if a test
	// needs them.
	//
	// `paged` is deliberately NOT implemented: the real wcs_get_subscriptions()
	// declares it among its own defaults and strips it before building the query,
	// so a mock that honored it would make a broken caller look correct.
	global $subscriptions_database;
	// Every call's args are recorded so tests can pin the query contract itself
	// (e.g. that a paginating caller requests a deterministic sort).
	global $wcs_mock_query_log;
	$wcs_mock_query_log[] = $args;
	$customer_id = $args['customer_id'] ?? null;
	$statuses    = $args['subscription_status'] ?? 'any';
	$per_page    = isset( $args['subscriptions_per_page'] ) ? (int) $args['subscriptions_per_page'] : 0;
	// Stageable: set $wcs_mock_ignore_offset to reproduce a query that never advances —
	// what the real function does when handed `paged`, and what a third-party
	// `woocommerce_get_subscriptions_query_args` filter dropping `offset` would do.
	global $wcs_mock_ignore_offset;
	$offset = ( isset( $args['offset'] ) && empty( $wcs_mock_ignore_offset ) ) ? max( 0, (int) $args['offset'] ) : 0;
	if ( 'any' === $statuses ) {
		$statuses = null;
	} elseif ( null !== $statuses ) {
		$statuses = (array) $statuses;
	}
	$matches = [];
	foreach ( $subscriptions_database as $id => $subscription ) {
		if ( null !== $customer_id && $subscription->get_customer_id() !== $customer_id ) {
			continue;
		}
		if ( null !== $statuses && ! $subscription->has_status( $statuses ) ) {
			continue;
		}
		$matches[ $id ] = $subscription;
	}
	// Page the results so a caller looping `offset` terminates on a short final page.
	if ( $per_page > 0 || $offset > 0 ) {
		$matches = array_slice( $matches, $offset, $per_page > 0 ? $per_page : null, true );
	}
	return $matches;
}
function wcs_get_subscriptions_for_product( $product_ids, $fields = 'ids', $args = [] ) {
	// Minimal mock mirroring the real return shape: subscriptions keyed by their
	// ID (so array_keys() yields subscription IDs), matched via WC_Subscription's
	// `products` array (has_product()). `subscription_status`/paging args are
	// ignored — extend here if a test needs them.
	global $subscriptions_database;
	$product_ids   = array_map( 'absint', (array) $product_ids );
	$subscriptions = [];
	foreach ( $subscriptions_database as $id => $subscription ) {
		if ( ! method_exists( $subscription, 'has_product' ) ) {
			continue;
		}
		foreach ( $product_ids as $product_id ) {
			if ( $subscription->has_product( $product_id ) ) {
				$subscriptions[ $id ] = ( 'ids' !== $fields ) ? $subscription : $id;
				break;
			}
		}
	}
	return $subscriptions;
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
/**
 * Stageable: set the $wcs_mock_product_switchable global to false to simulate
 * a product type that WCS does not allow switching (real WCS checks the
 * product type against the store's switching settings).
 *
 * @param WC_Product|int $product Product to check (ignored).
 */
function wcs_is_product_switchable_type( $product ) {
	unset( $product );
	global $wcs_mock_product_switchable;
	return $wcs_mock_product_switchable ?? true;
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
/**
 * Real WC: order statuses considered "paid" (payment received).
 */
function wc_get_is_paid_statuses() {
	return [ 'processing', 'completed' ];
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
			$orders,
			function( $order ) use ( $args ) {
				return $order->get_customer_id() === $args['customer_id'];
			}
		);
	}
	if ( ! empty( $args['customer'] ) ) {
		// Real WC: 'customer' accepts a user ID or billing email (or an array of
		// either) and matches orders belonging to ANY of the values — guest
		// orders (customer_id 0) match via their billing email. Email matching
		// happens in SQL under a case-insensitive collation, so compare with
		// strcasecmp() rather than a strict string comparison.
		//
		// The `! empty()` gate is deliberate: both order stores test the query var
		// with `! empty()` too, so an empty value drops the constraint entirely and
		// matches EVERY customer's orders. Guarding with isset() here would invert
		// that and make callers passing an empty customer look safe.
		$customer_values = (array) $args['customer'];
		$orders          = array_filter(
			$orders,
			function( $order ) use ( $customer_values ) {
				foreach ( $customer_values as $customer_value ) {
					if ( is_numeric( $customer_value ) && $order->get_customer_id() === (int) $customer_value ) {
						return true;
					}
					if ( is_string( $customer_value ) && ! is_numeric( $customer_value ) && 0 === strcasecmp( (string) $order->get_billing_email(), $customer_value ) ) {
						return true;
					}
				}
				return false;
			}
		);
	}
	if ( isset( $args['status'] ) ) {
		// Filter by status. Real wc_get_orders accepts statuses with or without
		// the 'wc-' prefix; normalize both sides so either form matches.
		$statuses = array_map(
			function( $status ) {
				return preg_replace( '/^wc-/', '', $status );
			},
			(array) $args['status']
		);
		$orders   = array_filter(
			$orders,
			function( $order ) use ( $statuses ) {
				return in_array( $order->get_status(), $statuses, true );
			}
		);
	}
	if ( isset( $args['date_created'] ) && is_string( $args['date_created'] ) && str_starts_with( $args['date_created'], '>' ) ) {
		// Support the '>{timestamp}' comparison form used by date-bounded queries.
		$cutoff = (int) substr( $args['date_created'], 1 );
		$orders = array_filter(
			$orders,
			function( $order ) use ( $cutoff ) {
				$date_created = $order->get_date_created();
				return $date_created && $date_created->getTimestamp() > $cutoff;
			}
		);
	}
	usort(
		$orders,
		function( $a, $b ) {
			return $b->get_date_paid()->getTimestamp() <=> $a->get_date_paid()->getTimestamp();
		}
	);
	if ( isset( $args['limit'] ) && (int) $args['limit'] > 0 ) {
		$orders = array_slice( $orders, 0, (int) $args['limit'] );
	}
	return $orders;
}

function wc_customer_bought_product( $customer_email, $user_id, $product_id ) {
	global $orders_database;
	// Real WC hands the question to third parties first and returns whatever they
	// answer verbatim, ahead of its own identity check.
	$filtered = apply_filters( 'woocommerce_pre_customer_bought_product', null, $customer_email, $user_id, $product_id );
	if ( null !== $filtered ) {
		return $filtered;
	}
	foreach ( $orders_database as $order ) {
		// Real WC matches the customer user ID OR the billing email, so guest
		// orders count toward the buyer's history. The email comparison runs in
		// SQL under a case-insensitive collation.
		$matches_user  = $user_id && $order->get_customer_id() === $user_id;
		$matches_email = $customer_email && 0 === strcasecmp( (string) $order->get_billing_email(), (string) $customer_email );
		if ( ! $matches_user && ! $matches_email ) {
			continue;
		}
		// Real WC only counts orders in paid statuses (processing/completed).
		if ( ! $order->has_status( wc_get_is_paid_statuses() ) ) {
			continue;
		}
		foreach ( $order->get_items() as $item ) {
			// Real WC matches both _product_id and _variation_id order item meta.
			if ( $item->get_product_id() === $product_id || ( $item->get_variation_id() && $item->get_variation_id() === $product_id ) ) {
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
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		return 'USD';
	}
}
/**
 * Minimal WC_Product_Query stand-in: filters $products_database on `type` and `status`.
 *
 * The `status` default reproduces WC_Product_Query's own default
 * ( draft, pending, private, publish ), which is what a caller that omits `status`
 * actually gets — the property the CLI's SELECTABLE_PRODUCT_STATUSES constant claims to
 * mirror. `limit` is accepted and ignored (fixtures are small).
 *
 * @param array $args Query args.
 * @return WC_Product[] The matching products.
 */
function wc_get_products( $args = [] ) {
	global $products_database;
	$types    = isset( $args['type'] ) ? (array) $args['type'] : null;
	$statuses = isset( $args['status'] ) ? (array) $args['status'] : [ 'draft', 'pending', 'private', 'publish' ];
	$matches  = [];
	foreach ( $products_database as $product ) {
		if ( null !== $types && ! in_array( $product->get_type(), $types, true ) ) {
			continue;
		}
		if ( ! in_array( $product->get_status(), $statuses, true ) ) {
			continue;
		}
		$matches[] = $product;
	}
	return $matches;
}
/**
 * Minimal stand-in for WooCommerce's admin field renderer. Only enough markup to let a metabox
 * callback render end to end; assertions belong on the surrounding markup, not on this field.
 *
 * @param array $field The field definition.
 */
function woocommerce_wp_text_input( $field ) {
	printf(
		'<p class="form-field %1$s"><label for="%2$s">%3$s</label><input type="text" id="%2$s" name="%4$s" value="%5$s" /></p>',
		esc_attr( $field['wrapper_class'] ?? '' ),
		esc_attr( $field['id'] ?? '' ),
		esc_html( $field['label'] ?? '' ),
		esc_attr( $field['name'] ?? ( $field['id'] ?? '' ) ),
		esc_attr( $field['value'] ?? '' )
	);
}
/**
 * Recording mock: notices land on the $wc_mock_notices global so tests can
 * assert the reader-facing half of code paths gated on
 * function_exists( 'wc_add_notice' ).
 *
 * @param string $message     Notice message.
 * @param string $notice_type Notice type: 'success' | 'error' | 'notice'.
 * @param array  $data        Extra notice data (unused).
 */
function wc_add_notice( $message, $notice_type = 'success', $data = [] ) {
	global $wc_mock_notices;
	$wc_mock_notices[] = [
		'notice' => $message,
		'type'   => $notice_type,
	];
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
	 * Paging/percent semantics match the real class, and generate_file() appends
	 * each page to the data file as the real class does — the export file is the
	 * only evidence the AJAX handler has that a page was written, so a mock that
	 * skipped it could not exercise that path. Use
	 * newspack_test_remove_export_files() to clear the staged files.
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
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			if ( 1 === $this->get_page() ) {
				// A run starts from a clean file, as in the real class.
				foreach ( [ $this->get_file_path(), $this->get_headers_row_file_path() ] as $temp_file ) {
					if ( file_exists( $temp_file ) ) {
						unlink( $temp_file );
					}
				}
			}
			$this->prepare_data_to_export();
			file_put_contents( $this->get_file_path(), $this->export_rows(), FILE_APPEND );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
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

/**
 * Delete the CSV export temp files staged by the batch-exporter mock, so a
 * test class that drives generate_file() leaves the uploads dir as it found
 * it. No-op unless the exporter base class is loaded.
 */
function newspack_test_remove_export_files() {
	if ( ! class_exists( '\Newspack\CSV_Batch_Exporter' ) ) {
		return;
	}
	$files = glob( trailingslashit( \Newspack\CSV_Batch_Exporter::get_exports_dir() ) . '*.csv*' );
	// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	foreach ( (array) $files as $file ) {
		unlink( $file );
	}
	// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
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
