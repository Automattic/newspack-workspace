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

class ModalCheckoutTest extends WP_UnitTestCase_Blocks { // phpcs:ignore
	/**
	 * Clean up request data.
	 */
	public function tear_down() {
		global $newspack_blocks_test_limited_product_id, $newspack_blocks_test_limited_user_id;
		$newspack_blocks_test_limited_product_id = null;
		$newspack_blocks_test_limited_user_id    = null;
		remove_all_filters( 'woocommerce_cart_item_removed_message' );
		unset( $_POST['billing_email'], $_POST['post_data'], $_REQUEST['modal_checkout'], $_REQUEST['post_data'] );
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
}
