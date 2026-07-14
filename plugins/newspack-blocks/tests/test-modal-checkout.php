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

class ModalCheckoutTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * Clean up request data.
	 */
	public function tear_down() {
		global $newspack_blocks_test_limited_product_id, $newspack_blocks_test_limited_user_id, $newspack_blocks_test_wc;
		$newspack_blocks_test_limited_product_id = null;
		$newspack_blocks_test_limited_user_id    = null;
		$newspack_blocks_test_wc                 = null;
		if ( property_exists( \Newspack\WooCommerce_My_Account::class, 'is_from_my_account' ) ) {
			\Newspack\WooCommerce_My_Account::$is_from_my_account = false;
		}
		remove_all_filters( 'woocommerce_cart_item_removed_message' );
		remove_all_filters( 'newspack_blocks_donate_billing_fields_keys' );
		unset( $_POST['billing_email'], $_POST['post_data'], $_REQUEST['modal_checkout'], $_REQUEST['post_data'], $_SERVER['HTTP_REFERER'] );
		parent::tear_down();
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
	 * Invoke the private cart product summary helper.
	 *
	 * @param object $cart Cart-like object.
	 *
	 * @return string
	 */
	private function get_cart_product_summary( $cart ) {
		$method = new ReflectionMethod( \Newspack_Blocks\Modal_Checkout::class, 'get_cart_product_summary' );
		$method->setAccessible( true );
		return $method->invoke( null, $cart );
	}

	/**
	 * Create a cart-like object for product summary tests.
	 *
	 * @param array  $items Cart items.
	 * @param string $subtotal Product subtotal HTML.
	 *
	 * @return object
	 */
	private function get_mock_cart( $items, $subtotal = '<span class="amount"><bdi>$29.00</bdi></span>' ) {
		return new class( $items, $subtotal ) {
			/**
			 * Cart items.
			 *
			 * @var array
			 */
			private $items;

			/**
			 * Product subtotal HTML.
			 *
			 * @var string
			 */
			private $subtotal;

			/**
			 * Constructor.
			 *
			 * @param array  $items Cart items.
			 * @param string $subtotal Product subtotal HTML.
			 */
			public function __construct( $items, $subtotal ) {
				$this->items    = $items;
				$this->subtotal = $subtotal;
			}

			/**
			 * Get the cart contents count.
			 *
			 * @return int
			 */
			public function get_cart_contents_count() {
				return array_sum( array_column( $this->items, 'quantity' ) );
			}

			/**
			 * Get cart items.
			 *
			 * @return array
			 */
			public function get_cart() {
				return $this->items;
			}

			/**
			 * Get a cart item.
			 *
			 * @param string $key Cart item key.
			 *
			 * @return array
			 */
			public function get_cart_item( $key ) {
				return $this->items[ $key ];
			}

			/**
			 * Get the product subtotal.
			 *
			 * @param object $product Product.
			 * @param int    $quantity Quantity.
			 *
			 * @return string
			 */
			public function get_product_subtotal( $product = null, $quantity = 1 ) {
				unset( $product, $quantity );
				return $this->subtotal;
			}
		};
	}

	/**
	 * Create a product-like object for product summary tests.
	 *
	 * @param string $name Product name.
	 *
	 * @return object
	 */
	private function get_mock_product( $name = 'Newsroom Pro' ) {
		return new class( $name ) {
			/**
			 * Product name.
			 *
			 * @var string
			 */
			private $name;

			/**
			 * Constructor.
			 *
			 * @param string $name Product name.
			 */
			public function __construct( $name ) {
				$this->name = $name;
			}

			/**
			 * Whether the product exists.
			 *
			 * @return bool
			 */
			public function exists() {
				return true;
			}

			/**
			 * Get the product name.
			 *
			 * @return string
			 */
			public function get_name() {
				return $this->name;
			}
		};
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
	 * It returns a sanitized product summary for a single-item cart.
	 */
	public function test_get_cart_product_summary_returns_sanitized_single_item_summary() {
		$cart = $this->get_mock_cart(
			[
				'abc123' => [
					'data'     => $this->get_mock_product( 'Newsroom Pro <script>alert(1)</script>' ),
					'quantity' => 1,
				],
			],
			'<span class="amount"><bdi>$29.00</bdi></span><script>alert(1)</script>'
		);

		$summary = $this->get_cart_product_summary( $cart );

		$this->assertStringContainsString( 'Newsroom Pro', $summary );
		$this->assertStringContainsString( '<span class="amount"><bdi>$29.00</bdi></span>', $summary );
		$this->assertStringNotContainsString( '<script', $summary );
	}

	/**
	 * It returns an empty summary for empty and multi-item carts.
	 */
	public function test_get_cart_product_summary_returns_empty_for_unsupported_cart_counts() {
		$this->assertSame( '', $this->get_cart_product_summary( $this->get_mock_cart( [] ) ) );
		$this->assertSame(
			'',
			$this->get_cart_product_summary(
				$this->get_mock_cart(
					[
						'abc123' => [
							'data'     => $this->get_mock_product( 'Monthly' ),
							'quantity' => 1,
						],
						'def456' => [
							'data'     => $this->get_mock_product( 'Annual' ),
							'quantity' => 1,
						],
					]
				)
			)
		);
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
}
