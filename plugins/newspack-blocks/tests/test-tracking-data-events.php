<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class TrackingDataEventsTest
 *
 * @package Newspack_Blocks
 */

require_once __DIR__ . '/mocks/newspack-plugin-mocks.php';
require_once __DIR__ . '/mocks/newspack-plugin-data-events-utils-mock.php';

/**
 * Tests for the modal checkout Data Events tracking integration.
 *
 * The suite runs without newspack-plugin or WooCommerce loaded: the recording
 * Data_Events mock captures listener registrations, the Utils mock serves
 * order-data fixtures, and callbacks are invoked directly.
 */
class TrackingDataEventsTest extends WP_UnitTestCase_Blocks {
	/**
	 * Reset mock state.
	 */
	public function set_up() {
		parent::set_up();
		\Newspack\Data_Events::$registered_listeners          = [];
		\Newspack\Data_Events\Utils::$order_data_fixtures     = [];
	}

	/**
	 * Clean up request data.
	 */
	public function tear_down() {
		\Newspack\Data_Events::$registered_listeners      = [];
		\Newspack\Data_Events\Utils::$order_data_fixtures = [];
		unset( $_POST['express_payment_type'], $_REQUEST['modal_checkout'], $_REQUEST['post_data'], $_POST['post_data'], $_SERVER['HTTP_REFERER'] );
		parent::tear_down();
	}

	/**
	 * Order-data fixture shaped like Newspack\Data_Events\Utils::get_order_data().
	 *
	 * @param int   $order_id   Order ID.
	 * @param int[] $product_id Product IDs.
	 * @param bool  $is_renewal Whether the order is a subscription renewal.
	 *
	 * @return array
	 */
	private function get_order_data_fixture( $order_id, $product_id = [ 55 ], $is_renewal = false ) {
		return [
			'user_id'         => 7,
			'email'           => 'reader@example.test',
			'amount'          => 10,
			'currency'        => 'USD',
			'recurrence'      => 'once',
			'platform'        => 'wc',
			'referer'         => '',
			'popup_id'        => '',
			'is_renewal'      => $is_renewal,
			'subscription_id' => '',
			'platform_data'   => [
				'order_id'   => $order_id,
				'product_id' => $product_id,
				'client_id'  => '',
			],
			'user_first_name' => 'Reader',
			'user_last_name'  => 'Example',
		];
	}

	/**
	 * The purchase event listens for the order's status change into a paid
	 * status, and not for the order-processed hooks, which both checkout
	 * pipelines fire after creating the order and before taking payment, on
	 * every submission, including declined ones.
	 */
	public function test_register_listeners_dispatches_on_paid_status_not_order_creation() {
		\Newspack_Blocks\Tracking\Data_Events::register_listeners();

		$listeners_by_hook = [];
		foreach ( \Newspack\Data_Events::$registered_listeners as $listener ) {
			$listeners_by_hook[ $listener['hook'] ] = $listener;
		}

		$this->assertArrayHasKey( 'woocommerce_order_status_changed', $listeners_by_hook );
		$this->assertSame( 'modal_checkout_interaction', $listeners_by_hook['woocommerce_order_status_changed']['action'] );
		$this->assertSame( [ \Newspack_Blocks\Tracking\Data_Events::class, 'order_status_changed' ], $listeners_by_hook['woocommerce_order_status_changed']['callable'] );

		$this->assertArrayNotHasKey( 'woocommerce_checkout_order_processed', $listeners_by_hook );
		$this->assertArrayNotHasKey( 'woocommerce_store_api_checkout_order_processed', $listeners_by_hook );
	}

	/**
	 * A modal checkout order with the given status.
	 *
	 * @param string $status Order status, without the wc- prefix.
	 * @param int    $id     Order ID.
	 *
	 * @return WC_Order
	 */
	private function get_modal_order( $status, $id = 123 ) {
		$order = new WC_Order( $id, 'wc_order_testkey', [], [ \Newspack_Blocks\Modal_Checkout::ORDER_META_KEY => 1 ] );
		$order->set_status( $status );
		return $order;
	}

	/**
	 * A modal order that reaches a paid status builds the standard payload
	 * with no request signal at all, as when a gateway webhook, a 3D Secure
	 * return or an admin confirms the payment.
	 */
	public function test_paid_modal_order_builds_payload_without_request_signals() {
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];
		$order = $this->get_modal_order( 'processing' );

		$data = \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 123, 'pending', 'processing', $order );

		$this->assertIsArray( $data );
		$this->assertSame( 'form_submission_success', $data['action'] );
		$this->assertSame( 'checkout_button', $data['action_type'] );
		$this->assertSame( 'product', $data['product_type'] );
		$this->assertSame( 'once', $data['recurrence'] );
		$this->assertSame( 55, $data['product_id'] );
		$this->assertNotEmpty( $order->get_meta( \Newspack_Blocks\Tracking\Data_Events::INTERACTION_DISPATCHED_META_KEY ) );
	}

	/**
	 * Statuses that are not paid never dispatch: a declined payment leaves the
	 * order pending or failed, and on-hold still awaits payment.
	 */
	public function test_unpaid_statuses_do_not_dispatch() {
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];

		foreach ( [ 'pending', 'failed', 'on-hold', 'cancelled' ] as $unpaid_status ) {
			$order = $this->get_modal_order( $unpaid_status );

			$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 123, 'checkout-draft', $unpaid_status, $order ), $unpaid_status );
			$this->assertEmpty( $order->get_meta( \Newspack_Blocks\Tracking\Data_Events::INTERACTION_DISPATCHED_META_KEY ), $unpaid_status );
		}
	}

	/**
	 * An order dispatches once: a later paid transition, such as processing to
	 * completed or a refunded order completed again, does not dispatch again.
	 */
	public function test_order_dispatches_once() {
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];
		$order = $this->get_modal_order( 'processing' );

		$this->assertIsArray( \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 123, 'pending', 'processing', $order ) );

		$order->set_status( 'completed' );
		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 123, 'processing', 'completed', $order ) );
	}

	/**
	 * A paid order without the modal marker does not dispatch, even when the
	 * current request carries modal signals: origin is read from the order.
	 */
	public function test_unmarked_paid_order_does_not_dispatch() {
		$_REQUEST['modal_checkout'] = '1';
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];
		$order = new WC_Order( 123, 'wc_order_testkey' );
		$order->set_status( 'processing' );

		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 123, 'pending', 'processing', $order ) );
	}

	/**
	 * A transition out of a paid status never dispatches, even on an order
	 * object that has not seen the flag. A status change nested inside another,
	 * such as an auto-complete hooked on processing, runs on a second copy of
	 * the order: that copy moves processing to completed and saves the flag
	 * while the first copy's pending to processing is still being reported.
	 */
	public function test_transition_from_a_paid_status_does_not_dispatch() {
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];
		$order = $this->get_modal_order( 'completed' );

		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 123, 'processing', 'completed', $order ) );
		$this->assertEmpty( $order->get_meta( \Newspack_Blocks\Tracking\Data_Events::INTERACTION_DISPATCHED_META_KEY ) );
	}

	/**
	 * A renewal checked out through the modal, such as an early renewal, is
	 * reported like any other modal purchase, and the payload's is_renewal
	 * tells the two apart.
	 */
	public function test_modal_renewal_order_dispatches_with_is_renewal() {
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 456 => $this->get_order_data_fixture( 456, [ 55 ], true ) ];
		$order = $this->get_modal_order( 'processing', 456 );

		$data = \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 456, 'pending', 'processing', $order );

		$this->assertIsArray( $data );
		$this->assertTrue( $data['is_renewal'] );
	}

	/**
	 * WooCommerce Subscriptions copies order meta from the parent order to the
	 * subscription and on to every renewal and resubscribe order. The marker
	 * and the flag are dropped from those copies, so a scheduled renewal is not
	 * reported as a modal checkout purchase, and other meta still copies.
	 */
	public function test_subscription_copy_drops_marker_and_flag() {
		$copied_meta = \Newspack_Blocks\Tracking\Data_Events::exclude_order_meta_from_subscription_copy(
			[
				\Newspack_Blocks\Modal_Checkout::ORDER_META_KEY => 1,
				\Newspack_Blocks\Tracking\Data_Events::INTERACTION_DISPATCHED_META_KEY => 1793000000,
				'_newspack_referer'                             => 'https://example.test/donate/',
			]
		);

		$this->assertSame( [ '_newspack_referer' => 'https://example.test/donate/' ], $copied_meta );
	}

	/**
	 * The exclusion is attached to every Subscriptions copy type.
	 */
	public function test_subscription_copy_filters_are_registered() {
		foreach ( [ 'subscription', 'parent', 'renewal_order', 'resubscribe_order' ] as $copy_type ) {
			$this->assertSame(
				10,
				has_filter( "wc_subscriptions_{$copy_type}_data", [ \Newspack_Blocks\Tracking\Data_Events::class, 'exclude_order_meta_from_subscription_copy' ] ),
				$copy_type
			);
		}
	}

	/**
	 * The status change callback ignores values that are not a WC_Order rather
	 * than trusting them.
	 */
	public function test_status_change_ignores_non_order_values() {
		\Newspack\Data_Events\Utils::$order_data_fixtures = [ 123 => $this->get_order_data_fixture( 123 ) ];

		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 123, 'pending', 'processing', null ) );
		$this->assertEmpty( \Newspack_Blocks\Tracking\Data_Events::order_status_changed( 123, 'pending', 'processing', 'not-an-order' ) );
	}
}
