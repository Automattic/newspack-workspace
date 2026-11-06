<?php
/**
 * Tests for the My Account order header.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account_UI_V1;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Test file mixes WooCommerce stubs with the test class.

require_once __DIR__ . '/../../mocks/wc-mocks.php';

if ( ! function_exists( 'wc_get_account_orders_actions' ) ) {
	/**
	 * Stub for WooCommerce wc_get_account_orders_actions(): Pay for an order awaiting
	 * payment, View for every order, then the filter plugins use to change them.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	function wc_get_account_orders_actions( $order ) {
		$actions = [];
		if ( $order->needs_payment() ) {
			$actions['pay'] = [
				'url'  => $order->get_checkout_payment_url(),
				'name' => 'Pay',
			];
		}
		$actions['view'] = [
			'url'  => $order->get_view_order_url(),
			'name' => 'View',
		];
		return apply_filters( 'woocommerce_my_account_my_orders_actions', $actions, $order );
	}
}

if ( ! function_exists( 'woocommerce_order_again_button' ) ) {
	/**
	 * Stub for WooCommerce woocommerce_order_again_button(): no button.
	 *
	 * @param WC_Order $order Order.
	 */
	function woocommerce_order_again_button( $order ) {}
}

if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
	/**
	 * Stub for WooCommerce wc_get_account_menu_items(): the default account menu.
	 *
	 * @return string[]
	 */
	function wc_get_account_menu_items() {
		return [
			'dashboard'       => 'Dashboard',
			'orders'          => 'Orders',
			'edit-account'    => 'Account details',
			'customer-logout' => 'Log out',
		];
	}
}

if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	/**
	 * Stub for WooCommerce wc_get_account_endpoint_url().
	 *
	 * @param string $endpoint Endpoint.
	 * @return string
	 */
	function wc_get_account_endpoint_url( $endpoint ) {
		return 'https://example.test/my-account/' . $endpoint . '/';
	}
}

if ( ! function_exists( 'wc_format_datetime' ) ) {
	/**
	 * Stub for WooCommerce wc_format_datetime().
	 *
	 * @param WC_DateTime $date Date.
	 * @return string
	 */
	function wc_format_datetime( $date ) {
		return $date->date( 'F j, Y' );
	}
}

if ( ! function_exists( 'wc_get_order_status_name' ) ) {
	/**
	 * Stub for WooCommerce wc_get_order_status_name().
	 *
	 * @param string $status Order status.
	 * @return string
	 */
	function wc_get_order_status_name( $status ) {
		return ucfirst( $status );
	}
}

/**
 * Test the order header: the subscriptions an order pays for, and the pay flow.
 *
 * Runs in separate processes so the renewal-order mock stays out of other suites.
 *
 * @group My_Account_Order_Header
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Newspack_Test_My_Account_Order_Header extends WP_UnitTestCase {

	/**
	 * Reset the mock databases.
	 */
	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/../../mocks/on-hold-duration-mocks.php';
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
	}

	/**
	 * Register a subscription for a product.
	 *
	 * @param array $product  Mock product data.
	 * @param bool  $donation Whether the product is a donation product.
	 * @param array $data     Extra subscription data.
	 * @return WC_Subscription
	 */
	private function make_subscription( array $product, bool $donation, array $data = [] ): WC_Subscription {
		$product = wc_create_mock_product( $product );
		if ( $donation ) {
			update_post_meta( $product->get_id(), \Newspack\WooCommerce_Products::DONATION_FLAG_META_KEY, 'yes' );
		}
		return wcs_create_subscription(
			array_merge(
				[
					'status'           => 'active',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'total'            => 15,
					'items'            => [ new WC_Order_Item_Product( [ 'product_id' => $product->get_id() ] ) ],
				],
				$data
			)
		);
	}

	/**
	 * A renewal links to its donation, named by how often it renews.
	 */
	public function test_renewal_order_names_its_donation() {
		$subscription = $this->make_subscription(
			[ 'id' => 4301 ],
			true,
			[
				'id'             => 366,
				'view_order_url' => 'https://example.test/my-account/view-subscription/366/',
			]
		);
		$order        = wcs_create_renewal_order( $subscription );

		$this->assertSame(
			[ 'Renewal for <a class="newspack-ui__link" href="https://example.test/my-account/view-subscription/366/">your monthly donation</a>' ],
			My_Account_UI_V1::get_order_subscription_lines( $order )
		);
	}

	/**
	 * A parent order is the subscription's first payment, and a subscription that
	 * isn't a donation is named by its product.
	 */
	public function test_parent_order_names_its_subscription_product() {
		$order = new WC_Order(
			[
				'status' => 'completed',
				'total'  => 10,
			]
		);
		$this->make_subscription(
			[
				'id'   => 4302,
				'name' => 'Premium',
			],
			false,
			[
				'parent_id'      => $order->get_id(),
				'view_order_url' => 'https://example.test/my-account/view-subscription/400/',
			]
		);

		$this->assertSame(
			[ 'First payment for <a class="newspack-ui__link" href="https://example.test/my-account/view-subscription/400/">your Premium</a>' ],
			My_Account_UI_V1::get_order_subscription_lines( $order )
		);
	}

	/**
	 * An order that starts several subscriptions gets a line for each.
	 */
	public function test_order_for_several_subscriptions_gets_a_line_each() {
		$order = new WC_Order(
			[
				'status' => 'completed',
				'total'  => 30,
			]
		);
		$this->make_subscription( [ 'id' => 4303 ], true, [ 'parent_id' => $order->get_id() ] );
		$this->make_subscription(
			[
				'id'   => 4304,
				'name' => 'Print edition',
			],
			false,
			[
				'parent_id'      => $order->get_id(),
				'billing_period' => 'year',
			]
		);

		$lines = array_map( 'wp_strip_all_tags', My_Account_UI_V1::get_order_subscription_lines( $order ) );

		$this->assertSame( [ 'First payment for your monthly donation', 'First payment for your Print edition' ], $lines );
	}

	/**
	 * A resubscribe order is the new subscription's parent, so it gets one first
	 * payment line, not another for the cancelled subscription it replaces.
	 */
	public function test_resubscribe_order_names_only_the_new_subscription() {
		$old = $this->make_subscription(
			[ 'id' => 4308 ],
			true,
			[
				'id'             => 367,
				'status'         => 'cancelled',
				'view_order_url' => 'https://example.test/my-account/view-subscription/367/',
			]
		);
		$order = new WC_Order(
			[
				'status' => 'completed',
				'total'  => 15,
			]
		);
		$order->update_meta_data( '_subscription_resubscribe', $old->get_id() );
		$this->make_subscription(
			[ 'id' => 4309 ],
			true,
			[
				'id'             => 368,
				'parent_id'      => $order->get_id(),
				'view_order_url' => 'https://example.test/my-account/view-subscription/368/',
			]
		);

		$this->assertSame(
			[ 'First payment for <a class="newspack-ui__link" href="https://example.test/my-account/view-subscription/368/">your monthly donation</a>' ],
			My_Account_UI_V1::get_order_subscription_lines( $order )
		);
	}

	/**
	 * An order outside any subscription gets no line.
	 */
	public function test_plain_order_gets_no_line() {
		$order = new WC_Order(
			[
				'status' => 'completed',
				'total'  => 20,
			]
		);

		$this->assertSame( [], My_Account_UI_V1::get_order_subscription_lines( $order ) );
	}

	/**
	 * Donations renewing on other schedules keep a period word, or none.
	 */
	public function test_donation_reference_follows_its_schedule() {
		$annual    = $this->make_subscription( [ 'id' => 4305 ], true, [ 'billing_period' => 'year' ] );
		$quarterly = $this->make_subscription(
			[ 'id' => 4306 ],
			true,
			[
				'billing_period'   => 'month',
				'billing_interval' => 3,
			]
		);

		$this->assertSame( 'your annual donation', My_Account_UI_V1::get_subscription_reference( $annual ) );
		$this->assertSame( 'your recurring donation', My_Account_UI_V1::get_subscription_reference( $quarterly ) );
	}

	/**
	 * A failed order's header moves Pay into the payment notice, drops View, and
	 * keeps actions other plugins add.
	 */
	public function test_failed_order_header_pay_flow() {
		$subscription                        = $this->make_subscription( [ 'id' => 4307 ], true, [ 'status' => 'on-hold' ] );
		$GLOBALS['teams_mock_subscriptions'] = [ $subscription ];
		$order                               = wcs_create_renewal_order( $subscription );
		$order->data['status']               = 'failed';
		$extra_action                        = function ( $actions ) {
			$actions['renew-listing'] = [
				'url'  => 'https://example.test/listing/renew/',
				'name' => 'Renew listing',
			];
			return $actions;
		};
		add_filter( 'woocommerce_my_account_my_orders_actions', $extra_action );

		ob_start();
		load_template(
			NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/v1/order-header.php',
			false,
			[
				'order'   => $order,
				'actions' => array_diff_key( wc_get_account_orders_actions( $order ), [ 'view' => true ] ),
			]
		);
		$html = ob_get_clean();
		remove_filter( 'woocommerce_my_account_my_orders_actions', $extra_action );
		unset( $GLOBALS['teams_mock_subscriptions'] );

		[ $header, $notice ] = explode( '</header>', $html );

		$this->assertSame( 1, preg_match_all( '/<a class="[^"]*\bpay\b[^"]*"/', $html ) );
		$this->assertStringContainsString( '>Pay $15.00</a>', $notice );
		$this->assertStringContainsString( 'Pay $15.00 to restart your monthly donation.', $notice );
		$this->assertStringContainsString( 'href="https://example.test/listing/renew/">Renew listing</a>', $header );
		$this->assertStringNotContainsString( '>View</a>', $html );
		$this->assertStringContainsString( 'Renewal for <a class="newspack-ui__link"', $header );
		$this->assertStringContainsString( 'aria-label="Back to orders"', $header );
	}
}
