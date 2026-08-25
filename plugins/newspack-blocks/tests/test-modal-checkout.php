<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class ModalCheckoutTest
 *
 * @package Newspack_Blocks
 */

/**
 * Modal checkout.
 */
if ( ! function_exists( 'wcs_is_product_limited_for_user' ) ) {
	/**
	 * Mock WooCommerce Subscriptions product limiting.
	 *
	 * @param object $product Product.
	 * @param int    $user_id User ID.
	 *
	 * @return bool
	 */
	function wcs_is_product_limited_for_user( $product, $user_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce Subscriptions global.
		global $newspack_blocks_test_limited_product_id, $newspack_blocks_test_limited_user_id;

		return (
			$product &&
			method_exists( $product, 'get_id' ) &&
			(int) $product->get_id() === (int) $newspack_blocks_test_limited_product_id &&
			(int) $user_id === (int) $newspack_blocks_test_limited_user_id
		);
	}
}

if ( ! function_exists( 'wcs_get_product_limitation' ) ) {
	/**
	 * Mock WooCommerce Subscriptions product limitation type.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return string
	 */
	function wcs_get_product_limitation( $product_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce Subscriptions global.
		unset( $product_id );
		return 'any';
	}
}

require_once __DIR__ . '/mocks/newspack-plugin-mocks.php';

if ( ! function_exists( 'WC' ) ) {
	/**
	 * Mock WC() function returning a controllable container.
	 *
	 * Defining WC() makes function_exists( 'WC' ) gates pass across the whole
	 * suite, so the container must keep previously-gated paths behaving as if
	 * WooCommerce had no state: no cart and no registered payment gateways.
	 *
	 * @return object
	 */
	function WC() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global.
		global $newspack_blocks_test_wc;
		if ( empty( $newspack_blocks_test_wc ) ) {
			$newspack_blocks_test_wc = new class() {
				/**
				 * Cart double, set by tests.
				 *
				 * @var object|null
				 */
				public $cart = null;

				/**
				 * Session double, set by tests.
				 *
				 * @var object|null
				 */
				public $session = null;

				/**
				 * Countries double exposing a US-only states list.
				 *
				 * @var object
				 */
				public $countries;

				/**
				 * Set up the countries double.
				 */
				public function __construct() {
					$this->countries = new class() {
						/**
						 * Get states for a country.
						 *
						 * @param string $country Country code.
						 *
						 * @return array
						 */
						public function get_states( $country ) {
							return 'US' === $country ? [ 'CA' => 'California' ] : [];
						}
					};
				}

				/**
				 * Mimic WC()->payment_gateways() with no registered gateways.
				 *
				 * @return object
				 */
				public function payment_gateways() {
					return new class() {
						/**
						 * Get registered gateways.
						 *
						 * @return array
						 */
						public function payment_gateways() {
							return [];
						}
					};
				}
			};
		}
		return $newspack_blocks_test_wc;
	}
}

if ( ! class_exists( 'WC_Validation' ) ) {
	/**
	 * Mock WooCommerce postcode validation.
	 */
	class WC_Validation {
		/**
		 * Mock postcode check: only the literal "INVALID" fails.
		 *
		 * @param string $postcode Postcode.
		 * @param string $country  Country code.
		 *
		 * @return bool
		 */
		public static function is_postcode( $postcode, $country ) {
			unset( $country );
			return 'INVALID' !== $postcode;
		}
	}
}

if ( ! function_exists( 'wc_get_checkout_url' ) ) {
	/**
	 * Mock WooCommerce checkout URL helper.
	 *
	 * @return string
	 */
	function wc_get_checkout_url() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global.
		return 'https://example.com/checkout/';
	}
}

if ( ! function_exists( 'wc_coupons_enabled' ) ) {
	/**
	 * Mock WooCommerce coupons-enabled flag. Defaults to enabled; a test can
	 * force it off via the $newspack_blocks_test_coupons_enabled global.
	 *
	 * @return bool
	 */
	function wc_coupons_enabled() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global.
		global $newspack_blocks_test_coupons_enabled;
		return null === $newspack_blocks_test_coupons_enabled ? true : (bool) $newspack_blocks_test_coupons_enabled;
	}
}

if ( ! function_exists( 'wc_format_coupon_code' ) ) {
	/**
	 * Mock WooCommerce coupon code formatter (lowercase + trim, as core does).
	 *
	 * @param string $code Coupon code.
	 *
	 * @return string
	 */
	function wc_format_coupon_code( $code ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global.
		return strtolower( trim( (string) $code ) );
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	/**
	 * Mock WooCommerce notice queue: record notices in a global.
	 *
	 * @param string $message     Notice message.
	 * @param string $notice_type Notice type.
	 *
	 * @return void
	 */
	function wc_add_notice( $message, $notice_type = 'success' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global.
		global $newspack_blocks_test_notices;
		if ( ! is_array( $newspack_blocks_test_notices ) ) {
			$newspack_blocks_test_notices = [];
		}
		$newspack_blocks_test_notices[] = [
			'message' => $message,
			'type'    => $notice_type,
		];
	}
}

if ( ! function_exists( 'wc_clear_notices' ) ) {
	/**
	 * Mock WooCommerce notice clearing: empty the queue and count the calls so
	 * tests can assert the auto-apply cleared the success notice.
	 *
	 * @return void
	 */
	function wc_clear_notices() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global.
		global $newspack_blocks_test_notices, $newspack_blocks_test_cleared_notices_count;
		$newspack_blocks_test_notices               = [];
		$newspack_blocks_test_cleared_notices_count = (int) $newspack_blocks_test_cleared_notices_count + 1;
	}
}

if ( ! function_exists( 'wc_get_notices' ) ) {
	/**
	 * Mock WooCommerce notice reading: return the recorded notices of one type in
	 * WooCommerce's own shape, where each entry carries a `notice` key.
	 *
	 * @param string $notice_type Notice type.
	 *
	 * @return array
	 */
	function wc_get_notices( $notice_type = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mock WooCommerce global.
		global $newspack_blocks_test_notices;
		$notices = is_array( $newspack_blocks_test_notices ) ? $newspack_blocks_test_notices : [];
		$matched = [];
		foreach ( $notices as $notice ) {
			if ( $notice_type && $notice['type'] !== $notice_type ) {
				continue;
			}
			$matched[] = [
				'notice' => $notice['message'],
				'data'   => [],
			];
		}
		return $matched;
	}
}

if ( ! class_exists( 'WC_Coupon' ) ) {
	/**
	 * Minimal WooCommerce coupon stub carrying its code.
	 */
	class WC_Coupon {
		/**
		 * Coupon code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Constructor.
		 *
		 * @param string $code Coupon code.
		 */
		public function __construct( $code = '' ) {
			$this->code = $code;
		}

		/**
		 * Get the coupon code.
		 *
		 * @return string
		 */
		public function get_code() {
			return $this->code;
		}
	}
}

if ( ! class_exists( 'WC_Discounts' ) ) {
	/**
	 * Minimal WooCommerce discounts stub. A coupon is valid only when its code
	 * is in the test-configured valid list; otherwise a WP_Error is returned,
	 * matching WC_Discounts::is_coupon_valid().
	 */
	class WC_Discounts {
		/**
		 * Constructor.
		 *
		 * @param mixed $cart Cart (unused by the stub).
		 */
		public function __construct( $cart = null ) {
			unset( $cart );
		}

		/**
		 * Whether a coupon is valid.
		 *
		 * @param WC_Coupon $coupon Coupon.
		 *
		 * @return bool|WP_Error
		 */
		public function is_coupon_valid( $coupon ) {
			global $newspack_blocks_test_valid_coupons;
			$valid = is_array( $newspack_blocks_test_valid_coupons ) ? $newspack_blocks_test_valid_coupons : [];
			if ( in_array( $coupon->get_code(), $valid, true ) ) {
				return true;
			}
			return new WP_Error( 'invalid_coupon', 'Invalid coupon.' );
		}
	}
}

// `WC_Cart` and `WC_Product` are stubbed by tests/test-modal-checkout-data.php,
// which PHPUnit loads first (it sorts ahead of this file). Guarding the subclass
// on the parent's existence keeps this file from fataling at load time if that
// ever stops being true; the tests that need it skip instead.
if ( class_exists( 'WC_Cart' ) && ! class_exists( 'Newspack_Blocks_Test_Mutable_Cart' ) ) {
	/**
	 * A cart double that actually mutates, for the empty-and-re-add round trip
	 * Modal_Checkout::update_cart_quantity() performs. Extends the WC_Cart stub so
	 * Checkout_Data::get_checkout_data()'s `instanceof \WC_Cart` branch is reached.
	 */
	class Newspack_Blocks_Test_Mutable_Cart extends WC_Cart {
		/**
		 * Cart contents, keyed by cart item key.
		 *
		 * @var array
		 */
		public $contents = [];

		/**
		 * Applied coupon codes.
		 *
		 * @var string[]
		 */
		public $applied = [];

		/**
		 * Quantities add_to_cart() should reject, as an add-to-cart guard would.
		 *
		 * @var int[]
		 */
		public $rejected_quantities = [];

		/**
		 * Counter behind the generated cart item keys.
		 *
		 * @var int
		 */
		private $key_count = 0;

		/**
		 * Constructor.
		 *
		 * @param array $contents Initial cart contents, keyed by cart item key.
		 */
		public function __construct( $contents = [] ) {
			parent::__construct( $contents );
			$this->contents = $contents;
		}

		/**
		 * Get the cart contents.
		 *
		 * @return array
		 */
		public function get_cart() {
			return $this->contents;
		}

		/**
		 * Get a single cart item.
		 *
		 * @param string $key Cart item key.
		 *
		 * @return array|false
		 */
		public function get_cart_item( $key ) {
			return isset( $this->contents[ $key ] ) ? $this->contents[ $key ] : false;
		}

		/**
		 * Empty the cart.
		 */
		public function empty_cart() {
			$this->contents = [];
		}

		/**
		 * Add an item, or reject it the way a guard-thrown exception makes
		 * WC_Cart::add_to_cart() do: queue an error notice and return false.
		 *
		 * @param int   $product_id     Product ID.
		 * @param int   $quantity       Quantity.
		 * @param int   $variation_id   Variation ID.
		 * @param array $variation      Variation attributes.
		 * @param array $cart_item_data Cart item data.
		 *
		 * @return string|false Cart item key, or false when rejected.
		 */
		public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = [], $cart_item_data = [] ) {
			unset( $variation );
			if ( in_array( (int) $quantity, $this->rejected_quantities, true ) ) {
				wc_add_notice( 'Choose at least 2 seats.', 'error' );
				return false;
			}
			$key                    = 'item_' . ++$this->key_count;
			$this->contents[ $key ] = array_merge(
				$cart_item_data,
				[
					'product_id'   => (int) $product_id,
					'variation_id' => (int) $variation_id,
					'quantity'     => (int) $quantity,
					'data'         => new WC_Product( (int) $product_id, 'simple', [], '10', 'Product ' . (int) $product_id ),
				]
			);
			return $key;
		}

		/**
		 * Get the applied coupon codes.
		 *
		 * @return string[]
		 */
		public function get_applied_coupons() {
			return $this->applied;
		}

		/**
		 * Apply a coupon.
		 *
		 * @param string $code Coupon code.
		 *
		 * @return bool
		 */
		public function apply_coupon( $code ) {
			$this->applied[] = $code;
			return true;
		}
	}
}

class ModalCheckoutTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * Reset coupon/notice test state to deterministic defaults before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $newspack_blocks_test_notices, $newspack_blocks_test_cleared_notices_count, $newspack_blocks_test_valid_coupons, $newspack_blocks_test_coupons_enabled;
		$newspack_blocks_test_notices               = [];
		$newspack_blocks_test_cleared_notices_count = 0;
		$newspack_blocks_test_valid_coupons         = [];
		$newspack_blocks_test_coupons_enabled       = null;
	}

	/**
	 * Clean up request data.
	 */
	public function tear_down() {
		global $newspack_blocks_test_limited_product_id, $newspack_blocks_test_limited_user_id, $newspack_blocks_test_wc, $newspack_blocks_test_notices, $newspack_blocks_test_cleared_notices_count, $newspack_blocks_test_valid_coupons, $newspack_blocks_test_coupons_enabled;
		$newspack_blocks_test_limited_product_id    = null;
		$newspack_blocks_test_limited_user_id       = null;
		$newspack_blocks_test_wc                    = null;
		$newspack_blocks_test_notices               = [];
		$newspack_blocks_test_cleared_notices_count = 0;
		$newspack_blocks_test_valid_coupons         = [];
		$newspack_blocks_test_coupons_enabled       = null;
		if ( property_exists( \Newspack\WooCommerce_My_Account::class, 'is_from_my_account' ) ) {
			\Newspack\WooCommerce_My_Account::$is_from_my_account = false;
		}
		remove_all_filters( 'woocommerce_cart_item_removed_message' );
		remove_all_filters( 'newspack_blocks_donate_billing_fields_keys' );
		remove_all_filters( 'newspack_blocks_modal_checkout_quantity_field' );
		unset(
			$_POST['billing_email'],
			$_POST['post_data'],
			$_POST['quantity'],
			$_GET['quantity'],
			$_REQUEST['modal_checkout'],
			$_REQUEST['post_data'],
			$_REQUEST['quantity'],
			$_SERVER['HTTP_REFERER']
		);
		parent::tear_down();
	}

	/**
	 * A requested quantity, once a plugin has claimed the product's quantity rules.
	 *
	 * @return array<string,array{0:?string,1:int}>
	 */
	public function requested_quantity_provider() {
		return [
			'absent'   => [ null, 1 ],
			'zero'     => [ '0', 1 ],
			// (int), not absint(): absint() takes the absolute value, so ?quantity=-5
			// would become 5 rather than flooring at 1 like any other out-of-range ask.
			'negative' => [ '-5', 1 ],
			'in range' => [ '7', 7 ],
		];
	}

	/**
	 * @dataProvider requested_quantity_provider
	 *
	 * @param string|null $requested The quantity in the request, or null for none.
	 * @param int         $expected  The quantity the checkout will use.
	 */
	public function test_requested_quantity_is_floored_at_one( $requested, $expected ) {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		if ( null === $requested ) {
			unset( $_GET['quantity'], $_REQUEST['quantity'] );
		} else {
			$_GET['quantity']     = $requested;
			$_REQUEST['quantity'] = $requested;
		}

		$this->assertSame( $expected, \Newspack_Blocks\Modal_Checkout::get_requested_quantity( 920 ) );

		unset( $_GET['quantity'], $_REQUEST['quantity'] );
	}

	/**
	 * `newspack_checkout=1` needs no nonce, so a quantity in the request is the
	 * caller's claim and nothing more. Without a plugin claiming the product's
	 * quantity rules, a crafted link would otherwise multiply what the reader pays.
	 */
	public function test_requested_quantity_is_one_without_a_quantity_field() {
		$_GET['quantity']     = '250';
		$_REQUEST['quantity'] = '250';

		$this->assertSame( 1, \Newspack_Blocks\Modal_Checkout::get_requested_quantity( 920 ) );

		unset( $_GET['quantity'], $_REQUEST['quantity'] );
	}

	/**
	 * The in-modal quantity form posts rather than gets; get_requested_quantity()
	 * must honor it too, since it reads $_REQUEST rather than $_GET alone.
	 */
	public function test_requested_quantity_reads_post_for_in_modal_form() {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		unset( $_GET['quantity'] );
		$_POST['quantity']    = '3';
		$_REQUEST['quantity'] = '3';

		$this->assertSame( 3, \Newspack_Blocks\Modal_Checkout::get_requested_quantity( 920 ) );

		unset( $_POST['quantity'], $_REQUEST['quantity'] );
	}

	/**
	 * Set serialized checkout data in the request.
	 *
	 * @param string $post_data Serialized checkout data.
	 */
	private function set_serialized_post_data( $post_data ) {
		$_POST['post_data']    = $post_data;
		$_REQUEST['post_data'] = $post_data;
	}

	/**
	 * It finds users from a top-level billing email field.
	 */
	public function test_get_user_id_from_email_reads_top_level_billing_email() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);

		$_POST['billing_email'] = 'repeat@example.com';

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It finds users from WooCommerce's serialized order review post_data.
	 */
	public function test_get_user_id_from_email_reads_serialized_post_data() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);

		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=repeat%40example.com&modal_checkout=1' );

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It preserves plus-addresses in WooCommerce's serialized order review post_data.
	 */
	public function test_get_user_id_from_email_reads_plus_address_from_serialized_post_data() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'admin+donationsrecaptcha@example.com',
			]
		);

		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=admin%2Bdonationsrecaptcha%40example.com&modal_checkout=1' );

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It prefers a top-level billing email over serialized post_data.
	 */
	public function test_get_user_id_from_email_prefers_top_level_billing_email() {
		$top_level_user_id = self::factory()->user->create(
			[
				'user_email' => 'top-level@example.com',
			]
		);
		self::factory()->user->create(
			[
				'user_email' => 'serialized@example.com',
			]
		);

		$_POST['billing_email'] = 'top-level@example.com';
		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=serialized%40example.com&modal_checkout=1' );

		$this->assertSame( $top_level_user_id, \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It returns false when no billing email is present.
	 */
	public function test_get_user_id_from_email_returns_false_without_email() {
		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It returns false when serialized post_data has no billing email.
	 */
	public function test_get_user_id_from_email_returns_false_for_post_data_without_billing_email() {
		$this->set_serialized_post_data( 'billing_first_name=Repeat&modal_checkout=1' );

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It ignores non-string request data.
	 */
	public function test_get_user_id_from_email_ignores_non_string_request_data() {
		$_POST['billing_email'] = [ 'repeat@example.com' ];
		$_POST['post_data']     = [ 'billing_email=repeat%40example.com&modal_checkout=1' ];

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * Unknown emails should not resolve to a user.
	 */
	public function test_get_user_id_from_email_returns_false_for_unknown_email() {
		$this->set_serialized_post_data( 'billing_first_name=New&billing_email=fresh%40example.com&modal_checkout=1' );

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::get_user_id_from_email() );
	}

	/**
	 * It associates modal checkout with an existing user found in serialized post_data.
	 */
	public function test_associate_existing_user_reads_serialized_post_data() {
		$user_id = self::factory()->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);

		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=repeat%40example.com&modal_checkout=1' );

		$this->assertSame( $user_id, \Newspack_Blocks\Modal_Checkout::associate_existing_user( 0 ) );
	}

	/**
	 * It does not associate standard checkout requests with users from serialized post_data.
	 */
	public function test_associate_existing_user_ignores_serialized_post_data_outside_modal_checkout() {
		self::factory()->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);

		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=repeat%40example.com' );

		$this->assertSame( 123, \Newspack_Blocks\Modal_Checkout::associate_existing_user( 123 ) );
	}

	/**
	 * It keeps the current customer ID when serialized post_data has a fresh email.
	 */
	public function test_associate_existing_user_keeps_customer_id_for_fresh_email() {
		$this->set_serialized_post_data( 'billing_first_name=New&billing_email=fresh%40example.com&modal_checkout=1' );

		$this->assertSame( 123, \Newspack_Blocks\Modal_Checkout::associate_existing_user( 123 ) );
	}

	/**
	 * It resolves subscription limits from serialized post_data outside modal checkout.
	 */
	public function test_subscriptions_product_limited_for_user_resolves_serialized_post_data_outside_modal_checkout() {
		global $newspack_blocks_test_limited_product_id, $newspack_blocks_test_limited_user_id;

		$user_id = self::factory()->user->create(
			[
				'user_email' => 'repeat@example.com',
			]
		);
		$product = new class() {
			/**
			 * Get product ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return 123;
			}
		};

		$newspack_blocks_test_limited_product_id = 123;
		$newspack_blocks_test_limited_user_id    = $user_id;
		$this->set_serialized_post_data( 'billing_first_name=Repeat&billing_email=repeat%40example.com' );

		$this->assertTrue( \Newspack_Blocks\Modal_Checkout::subscriptions_product_limited_for_user( false, $product, 0 ) );
	}

	/**
	 * Set WC()->cart to a cart double with a controllable needs_shipping_address().
	 *
	 * @param bool $needs_shipping Whether the cart needs a shipping address.
	 */
	private function set_mock_checkout_cart( $needs_shipping = false ) {
		WC()->cart = new class( $needs_shipping ) {
			/**
			 * Whether the cart needs a shipping address.
			 *
			 * @var bool
			 */
			private $needs_shipping;

			/**
			 * Constructor.
			 *
			 * @param bool $needs_shipping Whether the cart needs a shipping address.
			 */
			public function __construct( $needs_shipping ) {
				$this->needs_shipping = $needs_shipping;
			}

			/**
			 * Whether the cart needs a shipping address.
			 *
			 * @return bool
			 */
			public function needs_shipping_address() {
				return $this->needs_shipping;
			}
		};
	}

	/**
	 * Configure the billing fields returned by the config filter.
	 *
	 * @param string[] $billing_fields Billing field keys.
	 */
	private function set_configured_billing_fields( $billing_fields ) {
		add_filter(
			'newspack_blocks_donate_billing_fields_keys',
			function() use ( $billing_fields ) {
				return $billing_fields;
			}
		);
	}

	/**
	 * Mark the current request as a modal checkout request.
	 */
	private function set_modal_checkout_request() {
		$_REQUEST['modal_checkout'] = '1';
	}

	/**
	 * Set a referer pointing at the modal checkout iframe, as express checkout
	 * Store API requests carry.
	 */
	private function set_modal_checkout_referer() {
		$_SERVER['HTTP_REFERER'] = 'https://example.com/checkout/?modal_checkout=1';
	}

	/**
	 * Checkout fields fixture resembling the WooCommerce default structure.
	 *
	 * @return array
	 */
	private function get_checkout_fields_fixture() {
		return [
			'billing'  => [
				'billing_first_name' => [ 'label' => 'First name' ],
				'billing_last_name'  => [ 'label' => 'Last name' ],
				'billing_country'    => [ 'label' => 'Country' ],
				'billing_state'      => [ 'label' => 'State' ],
				'billing_phone'      => [ 'label' => 'Phone' ],
				'billing_email'      => [ 'label' => 'Email' ],
			],
			'shipping' => [
				'shipping_first_name' => [ 'label' => 'First name' ],
			],
			'order'    => [
				'order_comments' => [ 'label' => 'Order notes' ],
			],
		];
	}

	/**
	 * Configured-off billing fields are removed on modal checkout requests.
	 */
	public function test_checkout_fields_removes_configured_off_fields_on_modal_requests() {
		$this->set_modal_checkout_request();
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$fields = \Newspack_Blocks\Modal_Checkout::woocommerce_checkout_fields( $this->get_checkout_fields_fixture() );

		$this->assertSame(
			[ 'billing_first_name', 'billing_email' ],
			array_keys( $fields['billing'] ),
			'Billing fields not in the configured list should be removed.'
		);
		$this->assertArrayHasKey(
			'shipping_first_name',
			$fields['shipping'],
			'Shipping fields should be untouched.'
		);
	}

	/**
	 * Standard (non-modal) Woo checkouts keep the stock field set, by design:
	 * publisher flows that predate Audience Management must not change.
	 */
	public function test_checkout_fields_noop_on_standard_checkout() {
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$fields = $this->get_checkout_fields_fixture();

		$this->assertSame(
			$fields,
			\Newspack_Blocks\Modal_Checkout::woocommerce_checkout_fields( $fields ),
			'Fields should be unchanged on standard checkout requests.'
		);
	}

	/**
	 * With no custom billing fields configured, checkout fields are unchanged.
	 */
	public function test_checkout_fields_noop_when_no_fields_configured() {
		$this->set_modal_checkout_request();
		$this->set_mock_checkout_cart();

		$fields = $this->get_checkout_fields_fixture();

		$this->assertSame(
			$fields,
			\Newspack_Blocks\Modal_Checkout::woocommerce_checkout_fields( $fields ),
			'Fields should be unchanged when no custom billing fields are configured.'
		);
	}

	/**
	 * Carts that need a shipping address keep the full field set.
	 */
	public function test_checkout_fields_noop_when_cart_needs_shipping() {
		$this->set_modal_checkout_request();
		$this->set_mock_checkout_cart( true );
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$fields = $this->get_checkout_fields_fixture();

		$this->assertSame(
			$fields,
			\Newspack_Blocks\Modal_Checkout::woocommerce_checkout_fields( $fields ),
			'Fields should be unchanged when the cart needs a shipping address.'
		);
	}

	/**
	 * Contexts without a cart keep the full field set.
	 */
	public function test_checkout_fields_noop_when_cart_unavailable() {
		$this->set_modal_checkout_request();
		WC()->cart = null;
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$fields = $this->get_checkout_fields_fixture();

		$this->assertSame(
			$fields,
			\Newspack_Blocks\Modal_Checkout::woocommerce_checkout_fields( $fields ),
			'Fields should be unchanged when no cart is available.'
		);
	}

	/**
	 * My Account checkouts keep the full field set (they relax required flags
	 * instead of removing fields).
	 */
	public function test_checkout_fields_noop_for_my_account_checkout() {
		if ( ! property_exists( \Newspack\WooCommerce_My_Account::class, 'is_from_my_account' ) ) {
			$this->markTestSkipped( 'The WooCommerce_My_Account mock is not in use.' );
		}

		$this->set_modal_checkout_request();
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );
		\Newspack\WooCommerce_My_Account::$is_from_my_account = true;

		$fields = $this->get_checkout_fields_fixture();

		$this->assertSame(
			$fields,
			\Newspack_Blocks\Modal_Checkout::woocommerce_checkout_fields( $fields ),
			'Fields should be unchanged for My Account checkouts.'
		);
	}

	/**
	 * The billing phone field gets the form-row-last class when configured.
	 */
	public function test_checkout_fields_billing_phone_gets_form_row_last_class() {
		$this->set_modal_checkout_request();
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email', 'billing_phone' ] );

		$fields = \Newspack_Blocks\Modal_Checkout::woocommerce_checkout_fields( $this->get_checkout_fields_fixture() );

		$this->assertSame(
			'form-row-last',
			$fields['billing']['billing_phone']['class'],
			'The billing phone field should get the form-row-last class.'
		);
	}

	/**
	 * Build a Store API checkout request with the given billing address.
	 *
	 * @param array $billing_address Billing address.
	 *
	 * @return WP_REST_Request
	 */
	private function get_store_api_checkout_request( $billing_address ) {
		$this->set_modal_checkout_referer();

		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'billing_address', $billing_address );

		return $request;
	}

	/**
	 * An invalid state is scrubbed from Store API checkout requests when the
	 * state field is configured off.
	 */
	public function test_store_api_scrub_drops_invalid_state_when_configured_off() {
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$request = $this->get_store_api_checkout_request(
			[
				'country'  => 'US',
				'state'    => 'REMUERA',
				'postcode' => '94043',
			]
		);

		\Newspack_Blocks\Modal_Checkout::scrub_store_api_checkout_address( null, null, $request );

		$address = $request->get_param( 'billing_address' );

		$this->assertSame( '', $address['state'], 'An invalid state should be scrubbed.' );
		$this->assertSame( '94043', $address['postcode'], 'A valid postcode should be kept.' );
	}

	/**
	 * Valid state values pass through untouched, whether provided as a code or
	 * a name.
	 */
	public function test_store_api_scrub_keeps_valid_state_values() {
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		foreach ( [ 'CA', 'ca', 'California' ] as $state ) {
			$request = $this->get_store_api_checkout_request(
				[
					'country' => 'US',
					'state'   => $state,
				]
			);

			\Newspack_Blocks\Modal_Checkout::scrub_store_api_checkout_address( null, null, $request );

			$this->assertSame(
				$state,
				$request->get_param( 'billing_address' )['state'],
				"A valid state value ({$state}) should be left untouched."
			);
		}
	}

	/**
	 * An invalid postcode is scrubbed when the postcode field is configured off.
	 */
	public function test_store_api_scrub_drops_invalid_postcode_when_configured_off() {
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$request = $this->get_store_api_checkout_request(
			[
				'country'  => 'US',
				'postcode' => 'INVALID',
			]
		);

		\Newspack_Blocks\Modal_Checkout::scrub_store_api_checkout_address( null, null, $request );

		$this->assertSame( '', $request->get_param( 'billing_address' )['postcode'], 'An invalid postcode should be scrubbed.' );
	}

	/**
	 * Nothing is scrubbed when the fields are part of the configured list.
	 */
	public function test_store_api_scrub_noop_when_fields_configured_on() {
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email', 'billing_state', 'billing_postcode' ] );

		$address = [
			'country'  => 'US',
			'state'    => 'REMUERA',
			'postcode' => 'INVALID',
		];
		$request = $this->get_store_api_checkout_request( $address );

		\Newspack_Blocks\Modal_Checkout::scrub_store_api_checkout_address( null, null, $request );

		$this->assertSame( $address, $request->get_param( 'billing_address' ), 'Configured-on fields should never be scrubbed.' );
	}

	/**
	 * Nothing is scrubbed with the default (empty) configuration.
	 */
	public function test_store_api_scrub_noop_for_default_config() {
		$this->set_mock_checkout_cart();

		$address = [
			'country' => 'US',
			'state'   => 'REMUERA',
		];
		$request = $this->get_store_api_checkout_request( $address );

		\Newspack_Blocks\Modal_Checkout::scrub_store_api_checkout_address( null, null, $request );

		$this->assertSame( $address, $request->get_param( 'billing_address' ), 'Default configuration should never be scrubbed.' );
	}

	/**
	 * Carts needing a shipping address are never scrubbed.
	 */
	public function test_store_api_scrub_bails_for_shipping_carts() {
		$this->set_mock_checkout_cart( true );
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$address = [
			'country' => 'US',
			'state'   => 'REMUERA',
		];
		$request = $this->get_store_api_checkout_request( $address );

		\Newspack_Blocks\Modal_Checkout::scrub_store_api_checkout_address( null, null, $request );

		$this->assertSame( $address, $request->get_param( 'billing_address' ), 'Shipping carts should never be scrubbed.' );
	}

	/**
	 * Requests not originating from the modal checkout are never scrubbed,
	 * keeping standard Woo and blocks checkout flows stock.
	 */
	public function test_store_api_scrub_noop_without_modal_referer() {
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$address = [
			'country' => 'US',
			'state'   => 'REMUERA',
		];
		$request = $this->get_store_api_checkout_request( $address );
		unset( $_SERVER['HTTP_REFERER'] );

		\Newspack_Blocks\Modal_Checkout::scrub_store_api_checkout_address( null, null, $request );

		$this->assertSame( $address, $request->get_param( 'billing_address' ), 'Requests without a modal checkout referer should never be scrubbed.' );
	}

	/**
	 * Array-valued state/postcode do not trigger a fatal on the string transforms;
	 * the schema is left to reject them with its own clean error.
	 */
	public function test_store_api_scrub_ignores_non_string_values() {
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$address = [
			'country'  => 'US',
			'state'    => [ 'REMUERA' ],
			'postcode' => [ 'INVALID' ],
		];
		$request = $this->get_store_api_checkout_request( $address );

		\Newspack_Blocks\Modal_Checkout::scrub_store_api_checkout_address( null, null, $request );

		$this->assertSame( $address, $request->get_param( 'billing_address' ), 'Non-string address values should be left for the schema to reject.' );
	}

	/**
	 * Locale required flags are relaxed for configured-off state and postcode.
	 */
	public function test_locale_relaxation_for_configured_off_fields() {
		$this->set_modal_checkout_referer();
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );

		$locale = [
			'US' => [
				'state'    => [ 'required' => true ],
				'postcode' => [ 'required' => true ],
				'city'     => [ 'required' => true ],
			],
		];

		$relaxed = \Newspack_Blocks\Modal_Checkout::relax_configured_off_locale_fields( $locale );

		$this->assertFalse( $relaxed['US']['state']['required'], 'Configured-off state should not be required.' );
		$this->assertFalse( $relaxed['US']['postcode']['required'], 'Configured-off postcode should not be required.' );
		$this->assertTrue( $relaxed['US']['city']['required'], 'Fields without Store API validation should be untouched.' );
	}

	/**
	 * Locale required flags are untouched outside modal requests and, within
	 * them, for shipping carts, configured-on fields, and the default
	 * configuration.
	 */
	public function test_locale_relaxation_noop_cases() {
		$locale = [
			'US' => [ 'state' => [ 'required' => true ] ],
		];

		// No modal checkout referer or request param.
		$this->set_mock_checkout_cart();
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );
		$this->assertSame( $locale, \Newspack_Blocks\Modal_Checkout::relax_configured_off_locale_fields( $locale ), 'Non-modal requests should not relax locale fields.' );
		remove_all_filters( 'newspack_blocks_donate_billing_fields_keys' );

		// Default (empty) configuration.
		$this->set_modal_checkout_referer();
		$this->assertSame( $locale, \Newspack_Blocks\Modal_Checkout::relax_configured_off_locale_fields( $locale ), 'Default configuration should not relax locale fields.' );

		// Configured-on fields.
		$this->set_configured_billing_fields( [ 'billing_state', 'billing_postcode' ] );
		$this->assertSame( $locale, \Newspack_Blocks\Modal_Checkout::relax_configured_off_locale_fields( $locale ), 'Configured-on fields should not be relaxed.' );

		// Shipping carts.
		remove_all_filters( 'newspack_blocks_donate_billing_fields_keys' );
		$this->set_configured_billing_fields( [ 'billing_first_name', 'billing_email' ] );
		$this->set_mock_checkout_cart( true );
		$this->assertSame( $locale, \Newspack_Blocks\Modal_Checkout::relax_configured_off_locale_fields( $locale ), 'Shipping carts should not be relaxed.' );
	}

	/**
	 * Set WC()->cart to a coupon-aware cart double.
	 *
	 * @param string[] $applied Initially-applied coupon codes.
	 */
	private function set_mock_coupon_cart( $applied = [] ) {
		WC()->cart = new class( $applied ) {
			/**
			 * Applied coupon codes.
			 *
			 * @var string[]
			 */
			private $applied;

			/**
			 * Constructor.
			 *
			 * @param string[] $applied Applied coupon codes.
			 */
			public function __construct( $applied ) {
				$this->applied = $applied;
			}

			/**
			 * Apply a coupon: record it (formatted) and queue a success notice,
			 * mirroring WC_Cart::apply_coupon().
			 *
			 * @param string $code Coupon code.
			 *
			 * @return bool
			 */
			public function apply_coupon( $code ) {
				$this->applied[] = wc_format_coupon_code( $code );
				wc_add_notice( 'Coupon code applied successfully.', 'success' );
				return true;
			}

			/**
			 * Get the applied coupon codes.
			 *
			 * @return string[]
			 */
			public function get_applied_coupons() {
				return $this->applied;
			}
		};
	}

	/**
	 * Set WC()->session to a simple array-backed session double.
	 *
	 * @param array $data Initial session data.
	 */
	private function set_mock_session( $data = [] ) {
		WC()->session = new class( $data ) {
			/**
			 * Session data.
			 *
			 * @var array
			 */
			private $data;

			/**
			 * Constructor.
			 *
			 * @param array $data Session data.
			 */
			public function __construct( $data ) {
				$this->data = $data;
			}

			/**
			 * Get a session value.
			 *
			 * @param string $key           Key.
			 * @param mixed  $default_value Default value.
			 *
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default_value;
			}

			/**
			 * Set a session value.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 */
			public function set( $key, $value ) {
				$this->data[ $key ] = $value;
			}
		};
	}

	/**
	 * Configure which coupon codes the WC_Discounts stub treats as valid.
	 *
	 * @param string[] $codes Valid coupon codes.
	 */
	private function set_valid_coupons( $codes ) {
		global $newspack_blocks_test_valid_coupons;
		$newspack_blocks_test_valid_coupons = $codes;
	}

	/**
	 * A valid attached coupon is applied to the cart, tracked in the session
	 * (formatted), and its success notice is cleared so the auto-apply is silent.
	 */
	public function test_auto_apply_valid_coupon_applies_and_tracks_silently() {
		global $newspack_blocks_test_notices, $newspack_blocks_test_cleared_notices_count;
		$this->set_mock_coupon_cart();
		$this->set_mock_session();
		$this->set_valid_coupons( [ 'SUMMER25' ] );

		\Newspack_Blocks\Modal_Checkout::maybe_auto_apply_coupon( 'SUMMER25' );

		$this->assertContains( 'summer25', WC()->cart->get_applied_coupons(), 'A valid coupon should be applied to the cart.' );
		$this->assertSame(
			'summer25',
			WC()->session->get( \Newspack_Blocks\Modal_Checkout::AUTO_APPLIED_COUPON_SESSION_KEY ),
			'The applied coupon should be tracked in the session as the formatted code.'
		);
		$this->assertSame( [], $newspack_blocks_test_notices, 'The success notice should be cleared so the auto-apply is silent.' );
		$this->assertSame( 1, $newspack_blocks_test_cleared_notices_count, 'wc_clear_notices() should be called once on a successful auto-apply.' );
	}

	/**
	 * An invalid coupon (one that fails validation) is never applied, tracks
	 * nothing, and surfaces no error notice — the reader never typed it.
	 */
	public function test_auto_apply_invalid_coupon_is_skipped_silently() {
		global $newspack_blocks_test_notices;
		$this->set_mock_coupon_cart();
		$this->set_mock_session();
		$this->set_valid_coupons( [ 'SUMMER25' ] );

		\Newspack_Blocks\Modal_Checkout::maybe_auto_apply_coupon( 'BOGUS' );

		$this->assertSame( [], WC()->cart->get_applied_coupons(), 'An invalid coupon should not be applied.' );
		$this->assertNull(
			WC()->session->get( \Newspack_Blocks\Modal_Checkout::AUTO_APPLIED_COUPON_SESSION_KEY ),
			'No coupon should be tracked for an invalid code.'
		);
		$this->assertSame( [], $newspack_blocks_test_notices, 'No error notice should be shown for a coupon the reader never typed.' );
	}

	/**
	 * An empty coupon code applies nothing and clears any prior auto-apply
	 * marker from the session.
	 */
	public function test_auto_apply_empty_coupon_resets_tracking() {
		$this->set_mock_coupon_cart();
		$this->set_mock_session( [ \Newspack_Blocks\Modal_Checkout::AUTO_APPLIED_COUPON_SESSION_KEY => 'stale' ] );
		$this->set_valid_coupons( [ 'SUMMER25' ] );

		\Newspack_Blocks\Modal_Checkout::maybe_auto_apply_coupon( '' );

		$this->assertNull(
			WC()->session->get( \Newspack_Blocks\Modal_Checkout::AUTO_APPLIED_COUPON_SESSION_KEY ),
			'An empty coupon should clear any prior auto-apply marker.'
		);
	}

	/**
	 * When coupons are disabled site-wide, no coupon is applied or tracked.
	 */
	public function test_auto_apply_skipped_when_coupons_disabled() {
		global $newspack_blocks_test_coupons_enabled;
		$newspack_blocks_test_coupons_enabled = false;
		$this->set_mock_coupon_cart();
		$this->set_mock_session();
		$this->set_valid_coupons( [ 'SUMMER25' ] );

		\Newspack_Blocks\Modal_Checkout::maybe_auto_apply_coupon( 'SUMMER25' );

		$this->assertSame( [], WC()->cart->get_applied_coupons(), 'No coupon should be applied when coupons are disabled.' );
		$this->assertNull(
			WC()->session->get( \Newspack_Blocks\Modal_Checkout::AUTO_APPLIED_COUPON_SESSION_KEY ),
			'No coupon should be tracked when coupons are disabled.'
		);
	}

	/**
	 * The coupon form is hidden when an auto-applied coupon is still on the cart.
	 */
	public function test_should_hide_coupon_form_true_when_auto_applied_and_in_cart() {
		$this->set_mock_coupon_cart( [ 'summer25' ] );
		$this->set_mock_session( [ \Newspack_Blocks\Modal_Checkout::AUTO_APPLIED_COUPON_SESSION_KEY => 'summer25' ] );

		$this->assertTrue( \Newspack_Blocks\Modal_Checkout::should_hide_coupon_form() );
	}

	/**
	 * The coupon form reappears when the reader removes the auto-applied coupon.
	 */
	public function test_should_hide_coupon_form_false_when_auto_applied_coupon_removed() {
		$this->set_mock_coupon_cart( [] );
		$this->set_mock_session( [ \Newspack_Blocks\Modal_Checkout::AUTO_APPLIED_COUPON_SESSION_KEY => 'summer25' ] );

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::should_hide_coupon_form() );
	}

	/**
	 * A coupon the reader typed themselves (no session marker) does not hide the
	 * form, even though it is applied to the cart.
	 */
	public function test_should_hide_coupon_form_false_without_auto_applied_marker() {
		$this->set_mock_coupon_cart( [ 'summer25' ] );
		$this->set_mock_session();

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::should_hide_coupon_form() );
	}

	/**
	 * With no session, the form is shown (never hidden).
	 */
	public function test_should_hide_coupon_form_false_without_session() {
		$this->set_mock_coupon_cart( [ 'summer25' ] );
		WC()->session = null;

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::should_hide_coupon_form() );
	}

	/**
	 * With no cart, the form is shown (never hidden).
	 */
	public function test_should_hide_coupon_form_false_without_cart() {
		$this->set_mock_session( [ \Newspack_Blocks\Modal_Checkout::AUTO_APPLIED_COUPON_SESSION_KEY => 'summer25' ] );
		WC()->cart = null;

		$this->assertFalse( \Newspack_Blocks\Modal_Checkout::should_hide_coupon_form() );
	}

	/**
	 * Set WC()->cart to a cart double holding a single line item, as the modal
	 * checkout always does.
	 *
	 * @param int $product_id Product ID of the line item.
	 * @param int $quantity   Quantity of the line item.
	 */
	private function set_mock_single_item_cart( $product_id = 920, $quantity = 2, $extra = [] ) {
		$product = new class( $product_id ) {
			/**
			 * Product ID.
			 *
			 * @var int
			 */
			private $id;

			/**
			 * Constructor.
			 *
			 * @param int $id Product ID.
			 */
			public function __construct( $id ) {
				$this->id = $id;
			}

			/**
			 * Get the product ID.
			 *
			 * @return int
			 */
			public function get_id() {
				return $this->id;
			}

			/**
			 * Whether the product exists.
			 *
			 * @return bool
			 */
			public function exists() {
				return true;
			}
		};

		$cart_item = array_merge(
			$extra,
			[
				'product_id'   => $product_id,
				'variation_id' => 0,
				'quantity'     => $quantity,
				'data'         => $product,
			]
		);

		WC()->cart = new class( $cart_item ) {
			/**
			 * Cart contents, keyed by cart item key.
			 *
			 * @var array
			 */
			private $contents;

			/**
			 * Constructor.
			 *
			 * @param array $cart_item The single cart item.
			 */
			public function __construct( $cart_item ) {
				$this->contents = [ 'abc123' => $cart_item ];
			}

			/**
			 * Get the cart contents.
			 *
			 * @return array
			 */
			public function get_cart() {
				return $this->contents;
			}

			/**
			 * Get a single cart item.
			 *
			 * @param string $key Cart item key.
			 *
			 * @return array|false
			 */
			public function get_cart_item( $key ) {
				return isset( $this->contents[ $key ] ) ? $this->contents[ $key ] : false;
			}
		};
	}

	/**
	 * Hook the quantity field filter, returning the given args.
	 *
	 * @param array $args Field args to return.
	 */
	private function set_quantity_field_args( $args ) {
		add_filter(
			'newspack_blocks_modal_checkout_quantity_field',
			function () use ( $args ) {
				return $args;
			}
		);
	}

	/**
	 * Field args a per-seat product's filter callback would return.
	 *
	 * @return array
	 */
	private function get_quantity_field_args_fixture() {
		return [
			'label' => 'Number of team seats',
			'min'   => 2,
			'max'   => 10,
			'help'  => 'Seats include the team owner.',
		];
	}

	/**
	 * A filter returning field args renders the quantity form, seeded with the
	 * quantity already in the cart.
	 */
	public function test_quantity_form_renders_when_filter_returns_args() {
		$this->set_modal_checkout_request();
		$this->set_mock_single_item_cart( 920, 2 );
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );

		ob_start();
		\Newspack_Blocks\Modal_Checkout::render_quantity_form();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'form class="modal_checkout_quantity"', $output );
		$this->assertStringContainsString( 'name="quantity"', $output );
		$this->assertStringContainsString( 'value="2"', $output );
		$this->assertStringContainsString( 'min="2"', $output );
		$this->assertStringContainsString( 'max="10"', $output );
		$this->assertStringContainsString( 'Number of team seats', $output );
		$this->assertStringContainsString( 'Seats include the team owner.', $output );
		$this->assertStringContainsString( 'name="product_id" value="920"', $output );
	}

	/**
	 * The label is associated with the input, not just placed near it: a screen
	 * reader landing on the number field must hear what the number means.
	 */
	public function test_quantity_form_label_is_associated_with_the_input() {
		$this->set_modal_checkout_request();
		$this->set_mock_single_item_cart( 920, 2 );
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );

		ob_start();
		\Newspack_Blocks\Modal_Checkout::render_quantity_form();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<label for="modal_checkout_quantity">Number of team seats</label>', $output );
		$this->assertStringContainsString( 'id="modal_checkout_quantity"', $output );
	}

	/**
	 * An unlimited maximum omits the max attribute rather than emitting max="0".
	 */
	public function test_quantity_form_omits_max_when_unlimited() {
		$this->set_modal_checkout_request();
		$this->set_mock_single_item_cart( 920, 3 );
		$args        = $this->get_quantity_field_args_fixture();
		$args['max'] = 0;
		$this->set_quantity_field_args( $args );

		ob_start();
		\Newspack_Blocks\Modal_Checkout::render_quantity_form();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'form class="modal_checkout_quantity"', $output );
		$this->assertStringNotContainsString( 'max=', $output );
	}

	/**
	 * Without a filter enabling it, the form is not rendered at all: the modal
	 * stays single-quantity for every product that has no opinion about seats.
	 */
	public function test_quantity_form_renders_nothing_without_filter() {
		$this->set_modal_checkout_request();
		$this->set_mock_single_item_cart( 920, 2 );

		ob_start();
		\Newspack_Blocks\Modal_Checkout::render_quantity_form();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Outside modal checkout the form never renders, even with the filter on.
	 */
	public function test_quantity_form_renders_nothing_outside_modal_checkout() {
		$this->set_mock_single_item_cart( 920, 2 );
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );

		ob_start();
		\Newspack_Blocks\Modal_Checkout::render_quantity_form();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The filter receives the cart's product and cart item, so a consumer can
	 * decide per product whether the field applies.
	 */
	public function test_quantity_form_filter_receives_product_and_cart_item() {
		$this->set_modal_checkout_request();
		$this->set_mock_single_item_cart( 920, 2 );

		$received = [];
		add_filter(
			'newspack_blocks_modal_checkout_quantity_field',
			function ( $args, $product, $cart_item ) use ( &$received ) {
				$received = [
					'product_id' => $product->get_id(),
					'quantity'   => $cart_item['quantity'],
				];
				return $args;
			},
			10,
			3
		);

		ob_start();
		\Newspack_Blocks\Modal_Checkout::render_quantity_form();
		ob_get_clean();

		$this->assertSame(
			[
				'product_id' => 920,
				'quantity'   => 2,
			],
			$received
		);
	}

	/**
	 * Set WC()->cart to a mutable cart double holding one line item.
	 *
	 * @param int   $product_id Product ID of the line item.
	 * @param int   $quantity   Quantity of the line item.
	 * @param array $extra      Extra cart item data (e.g. preserved keys).
	 *
	 * @return Newspack_Blocks_Test_Mutable_Cart
	 */
	private function set_mutable_cart( $product_id = 920, $quantity = 2, $extra = [] ) {
		if ( ! class_exists( 'Newspack_Blocks_Test_Mutable_Cart' ) ) {
			$this->markTestSkipped( 'Requires the WC_Cart stub from test-modal-checkout-data.php.' );
		}
		$cart = new Newspack_Blocks_Test_Mutable_Cart(
			[
				'existing' => array_merge(
					$extra,
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'quantity'     => $quantity,
						'data'         => new WC_Product( $product_id, 'simple', [], '10', 'Product ' . $product_id ),
					]
				),
			]
		);
		WC()->cart = $cart;
		return $cart;
	}

	/**
	 * WooCommerce Subscriptions writes `subscription_switch` onto the cart item from
	 * the request that started the switch, and the quantity AJAX carries no such
	 * request. Re-adding would drop the record, and the checkout would raise a second
	 * full-price subscription instead of switching the one the reader already pays
	 * for -- so the rebuild is refused and the cart is left exactly as it was.
	 */
	public function test_update_cart_quantity_refuses_a_switch_cart() {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		$cart = $this->set_mutable_cart( 920, 4, [ 'subscription_switch' => [ 'subscription_id' => 77 ] ] );

		$result = \Newspack_Blocks\Modal_Checkout::update_cart_quantity( 920, 8 );

		$this->assertWPError( $result );
		$this->assertSame( 'newspack_blocks_quantity_switch_cart', $result->get_error_code() );
		$item = reset( $cart->contents );
		$this->assertSame( 4, $item['quantity'], 'The switch cart item is untouched.' );
		$this->assertSame( [ 'subscription_id' => 77 ], $item['subscription_switch'] );
	}

	/**
	 * A control the reader could use on a switch cart would only ever produce the
	 * refusal above, so it is not offered.
	 */
	public function test_quantity_form_is_not_rendered_on_a_switch_cart() {
		$this->set_modal_checkout_request();
		$this->set_mock_single_item_cart( 920, 4, [ 'subscription_switch' => [ 'subscription_id' => 77 ] ] );
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );

		ob_start();
		\Newspack_Blocks\Modal_Checkout::render_quantity_form();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The happy path: the line item comes back at the new quantity, the data the
	 * original add attached survives the round trip, and coupons are re-applied.
	 */
	public function test_update_cart_quantity_re_adds_at_the_new_quantity() {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		$cart          = $this->set_mutable_cart( 920, 2, [ 'gate_post_id' => 55 ] );
		$cart->applied = [ 'summer25' ];

		$result = \Newspack_Blocks\Modal_Checkout::update_cart_quantity( 920, 5 );

		$this->assertIsString( $result );
		$this->assertCount( 1, $cart->get_cart() );
		$item = reset( $cart->contents );
		$this->assertSame( 5, $item['quantity'] );
		$this->assertSame( 55, $item['gate_post_id'] );
		$this->assertSame( [ 'summer25', 'summer25' ], $cart->applied, 'The saved coupon should be re-applied to the rebuilt cart.' );
	}

	/**
	 * A rejected re-add must not leave the cart empty. WooCommerce answers
	 * update_order_review on an empty cart by replacing the whole checkout form
	 * with a session-expired notice, so the reader's original line item has to go
	 * back before the rejection is reported.
	 */
	public function test_update_cart_quantity_restores_the_original_item_when_rejected() {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		$cart                      = $this->set_mutable_cart( 920, 2, [ 'gate_post_id' => 55 ] );
		$cart->applied             = [ 'summer25' ];
		$cart->rejected_quantities = [ 99 ];

		$result = \Newspack_Blocks\Modal_Checkout::update_cart_quantity( 920, 99 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'Choose at least 2 seats.', $result->get_error_message(), 'The cart\'s own rejection notice should be surfaced.' );
		$this->assertCount( 1, $cart->get_cart(), 'The cart must not be left empty.' );
		$item = reset( $cart->contents );
		$this->assertSame( 2, $item['quantity'], 'The original quantity should be restored.' );
		$this->assertSame( 55, $item['gate_post_id'], 'The restored item should keep its preserved data.' );
		$this->assertSame( [ 'summer25', 'summer25' ], $cart->applied, 'Coupons should survive a rejected change.' );
	}

	/**
	 * A product that is not in the cart is not a quantity change: emptying the
	 * cart for it would destroy whatever the reader is actually buying.
	 */
	public function test_update_cart_quantity_bails_when_the_product_is_not_in_the_cart() {
		$cart = $this->set_mutable_cart( 920, 2 );

		$result = \Newspack_Blocks\Modal_Checkout::update_cart_quantity( 999, 5 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'newspack_blocks_quantity_not_in_cart', $result->get_error_code() );
		$this->assertCount( 1, $cart->get_cart() );
		$item = reset( $cart->contents );
		$this->assertSame( 920, $item['product_id'], 'The untouched cart should still hold the original product.' );
		$this->assertSame( 2, $item['quantity'] );
	}

	/**
	 * The payload the AJAX handler returns alongside its message reports the new
	 * seat count. `update_checkout` replaces only the review-order table and the
	 * payment box, so `#modal-checkout-product-details` — which carries this data
	 * to the GA4 events — has to be rewritten from the response instead.
	 */
	public function test_update_cart_quantity_payload_reports_the_new_quantity() {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		$cart = $this->set_mutable_cart( 920, 2 );

		\Newspack_Blocks\Modal_Checkout::update_cart_quantity( 920, 5 );
		$data = \Newspack_Blocks\Modal_Checkout\Checkout_Data::get_checkout_data( $cart );

		$this->assertSame( 5, $data['quantity'] );
	}

	/**
	 * The modal checkout doesn't know which products may be sold in multiples, so
	 * a requested quantity is offered to whoever does — with the product it is for
	 * — and their answer is the one used.
	 */
	public function test_requested_quantity_is_filtered_with_the_product() {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		$_GET['quantity']     = '5';
		$_REQUEST['quantity'] = '5';
		$seen                 = [];

		$filter = function ( $quantity, $product_id ) use ( &$seen ) {
			$seen[] = [ $quantity, $product_id ];
			return 920 === $product_id ? $quantity : 1;
		};
		add_filter( 'newspack_blocks_modal_checkout_quantity', $filter, 10, 2 );

		$this->assertSame( 5, \Newspack_Blocks\Modal_Checkout::get_requested_quantity( 920 ) );
		$this->assertSame( 1, \Newspack_Blocks\Modal_Checkout::get_requested_quantity( 921 ) );
		$this->assertSame(
			[ [ 5, 920 ], [ 5, 921 ] ],
			$seen,
			'The callback should see the floored request and the product it is for.'
		);

		remove_filter( 'newspack_blocks_modal_checkout_quantity', $filter, 10 );
		unset( $_GET['quantity'], $_REQUEST['quantity'] );
	}

	/**
	 * A callback cannot push the quantity below one: the floor is re-applied to
	 * whatever it returns, so a stray 0 can't reach the cart as a silent removal.
	 */
	public function test_filtered_quantity_is_floored_at_one() {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		$_GET['quantity']     = '4';
		$_REQUEST['quantity'] = '4';

		$filter = function () {
			return 0;
		};
		add_filter( 'newspack_blocks_modal_checkout_quantity', $filter );

		$this->assertSame( 1, \Newspack_Blocks\Modal_Checkout::get_requested_quantity( 920 ) );

		remove_filter( 'newspack_blocks_modal_checkout_quantity', $filter );
		unset( $_GET['quantity'], $_REQUEST['quantity'] );
	}

	/**
	 * The in-modal quantity form runs through the same clamp as the initial add,
	 * so a product that may not be sold in multiples cannot be raised past one
	 * from inside the modal either.
	 */
	public function test_update_cart_quantity_respects_the_filter() {
		$this->set_quantity_field_args( $this->get_quantity_field_args_fixture() );
		$cart = $this->set_mutable_cart( 920, 1 );

		$filter = function () {
			return 1;
		};
		add_filter( 'newspack_blocks_modal_checkout_quantity', $filter );

		\Newspack_Blocks\Modal_Checkout::update_cart_quantity( 920, 5 );

		$item = reset( $cart->contents );
		$this->assertSame( 1, $item['quantity'], 'The clamped quantity should be the one added.' );

		remove_filter( 'newspack_blocks_modal_checkout_quantity', $filter );
	}
}
