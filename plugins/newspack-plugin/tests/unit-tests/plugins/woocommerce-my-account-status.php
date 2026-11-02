<?php
/**
 * Tests for the My Account status mapping.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account_UI_V1;
use Newspack\Newspack_UI;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Test file mixes WooCommerce stubs with the test class.

if ( ! function_exists( 'wc_get_notices' ) ) {
	/**
	 * Stub for WooCommerce wc_get_notices(), reading the queue a test sets.
	 *
	 * @return array[]
	 */
	function wc_get_notices() {
		return $GLOBALS['newspack_test_wc_notices'] ?? [];
	}
}

if ( ! function_exists( 'wc_set_notices' ) ) {
	/**
	 * Stub for WooCommerce wc_set_notices().
	 *
	 * @param array[] $notices Notices by type.
	 */
	function wc_set_notices( $notices ) {
		$GLOBALS['newspack_test_wc_notices'] = $notices;
	}
}

if ( ! function_exists( 'wc_get_notice_data_attr' ) ) {
	/**
	 * Stub for WooCommerce wc_get_notice_data_attr().
	 *
	 * @param array $notice Notice.
	 * @return string
	 */
	function wc_get_notice_data_attr( $notice ) {
		return '';
	}
}

if ( ! function_exists( 'wc_kses_notice' ) ) {
	/**
	 * Stub for WooCommerce wc_kses_notice().
	 *
	 * @param string $message Notice message.
	 * @return string
	 */
	function wc_kses_notice( $message ) {
		return wp_kses_post( $message );
	}
}

if ( ! function_exists( 'wc_get_account_orders_actions' ) ) {
	/**
	 * Stub for WooCommerce wc_get_account_orders_actions(): Pay for an order awaiting
	 * payment, then the same filter plugins use to drop it.
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
		return apply_filters( 'woocommerce_my_account_my_orders_actions', $actions, $order );
	}
}

/**
 * Test which status modifier each order and subscription status gets.
 */
class Newspack_Test_My_Account_Status extends WP_UnitTestCase {

	/**
	 * Settled statuses read as success, ended ones as errors, and the rest stay
	 * neutral; an on-hold order stays neutral, unlike an on-hold subscription.
	 */
	public function test_status_modifiers() {
		$this->assertSame( 'success', My_Account_UI_V1::get_status_modifier( 'active' ) );
		$this->assertSame( 'success', My_Account_UI_V1::get_status_modifier( 'wc-completed' ) );
		$this->assertSame( 'error', My_Account_UI_V1::get_status_modifier( 'cancelled' ) );
		$this->assertSame( 'warning', My_Account_UI_V1::get_status_modifier( 'failed' ) );
		$this->assertSame( 'error', My_Account_UI_V1::get_status_modifier( 'expired' ) );
		$this->assertSame( '', My_Account_UI_V1::get_status_modifier( 'on-hold' ) );
		$this->assertSame( '', My_Account_UI_V1::get_status_modifier( 'pending-cancel' ) );
		$this->assertSame( 'warning', My_Account_UI_V1::get_status_modifier( 'pending' ) );
	}

	/**
	 * A status prints a hidden icon before its escaped label, with the modifier for its state.
	 */
	public function test_render_status() {
		ob_start();
		My_Account_UI_V1::render_status( 'expired', 'Expired <b>now</b>' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'class="newspack-ui__status newspack-ui__status--error"', $html );
		$this->assertMatchesRegularExpression( '/<svg[^>]*aria-hidden="true"[^>]*class="newspack-ui__svg-icon--not-allowed"/', $html );
		$this->assertMatchesRegularExpression( '/<\/svg>Expired &lt;b&gt;now&lt;\/b&gt;<\/span>/', $html );

		ob_start();
		My_Account_UI_V1::render_status( 'on-hold', 'On hold' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'class="newspack-ui__status"', $html );
		$this->assertStringContainsString( 'newspack-ui__svg-icon--pending', $html );

		$icons = [
			'active'         => 'published',
			'wc-completed'   => 'published',
			'cancelled'      => 'not-allowed',
			'expired'        => 'not-allowed',
			'failed'         => 'error',
			'pending-cancel' => 'scheduled',
			'pending'        => 'pending',
			'unknown-status' => 'pending',
		];
		foreach ( $icons as $status => $icon ) {
			ob_start();
			My_Account_UI_V1::render_status( $status, $status );
			$html = ob_get_clean();
			$this->assertStringContainsString( 'newspack-ui__svg-icon--' . $icon . '"', $html, $status );
		}
	}

	/**
	 * Build an on-hold subscription whose latest renewal order has the given status.
	 *
	 * @param string|null $renewal_status Renewal order status, or null for no renewal.
	 * @return WC_Subscription
	 */
	private function make_on_hold_subscription( ?string $renewal_status ): WC_Subscription {
		$renewals = [];
		if ( $renewal_status ) {
			$renewals[] = new WC_Order(
				[
					'status' => $renewal_status,
					'total'  => 15,
				]
			);
		}
		return new WC_Subscription(
			[
				'id'             => 366,
				'status'         => 'on-hold',
				'related_orders' => [ 'renewal' => $renewals ],
			]
		);
	}

	/**
	 * Render a subscription's status.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @return string
	 */
	private function render_subscription_status( $subscription ): string {
		ob_start();
		My_Account_UI_V1::render_subscription_status( $subscription );
		return ob_get_clean();
	}

	/**
	 * An on-hold subscription whose renewal payment failed reads "Payment failed", as a warning.
	 */
	public function test_subscription_on_hold_after_failed_renewal() {
		foreach ( [ 'failed', 'pending' ] as $renewal_status ) {
			$subscription = $this->make_on_hold_subscription( $renewal_status );

			$this->assertTrue( My_Account_UI_V1::subscription_payment_failed( $subscription ), $renewal_status );
			$this->assertSame( 'Payment failed', My_Account_UI_V1::get_subscription_status_label( $subscription ), $renewal_status );
			$this->assertSame( 'warning', My_Account_UI_V1::get_subscription_status_modifier( $subscription ), $renewal_status );

			$html = $this->render_subscription_status( $subscription );
			$this->assertStringContainsString( 'class="newspack-ui__status newspack-ui__status--warning"', $html, $renewal_status );
			$this->assertStringContainsString( 'newspack-ui__svg-icon--error"', $html, $renewal_status );
			$this->assertMatchesRegularExpression( '/<\/svg>Payment failed<\/span>/', $html, $renewal_status );
		}
	}

	/**
	 * An on-hold subscription with no payable renewal keeps "On hold", still as a warning.
	 */
	public function test_subscription_on_hold_without_failed_renewal() {
		foreach ( [ null, 'completed' ] as $renewal_status ) {
			$subscription = $this->make_on_hold_subscription( $renewal_status );

			$this->assertFalse( My_Account_UI_V1::subscription_payment_failed( $subscription ) );
			$this->assertSame( 'On-hold', My_Account_UI_V1::get_subscription_status_label( $subscription ) );
			$this->assertSame( 'warning', My_Account_UI_V1::get_subscription_status_modifier( $subscription ) );

			$html = $this->render_subscription_status( $subscription );
			$this->assertStringContainsString( 'class="newspack-ui__status newspack-ui__status--warning"', $html );
			$this->assertStringContainsString( 'newspack-ui__svg-icon--pending"', $html );
		}
	}

	/**
	 * A failed renewal the reader can no longer pay does not read "Payment failed".
	 */
	public function test_subscription_on_hold_with_unpayable_renewal() {
		$no_pay = fn( $actions ) => array_diff_key( $actions, [ 'pay' => true ] );
		add_filter( 'woocommerce_my_account_my_orders_actions', $no_pay );
		$subscription = $this->make_on_hold_subscription( 'failed' );
		$label        = My_Account_UI_V1::get_subscription_status_label( $subscription );
		remove_filter( 'woocommerce_my_account_my_orders_actions', $no_pay );

		$this->assertSame( 'On-hold', $label );
	}

	/**
	 * Other subscription statuses keep their shared modifier and name.
	 */
	public function test_subscription_status_outside_on_hold() {
		$subscription = new WC_Subscription(
			[
				'id'     => 367,
				'status' => 'active',
			]
		);

		$this->assertFalse( My_Account_UI_V1::subscription_payment_failed( $subscription ) );
		$this->assertSame( 'success', My_Account_UI_V1::get_subscription_status_modifier( $subscription ) );
		$this->assertSame( 'Active', My_Account_UI_V1::get_subscription_status_label( $subscription ) );
	}

	/**
	 * A payable order gets a "Pay now" link after its status, named with the amount and order number.
	 */
	public function test_render_order_status_with_pay_link() {
		$order = new WC_Order(
			[
				'status' => 'failed',
				'total'  => 15,
			]
		);

		$id = $order->get_id();

		ob_start();
		My_Account_UI_V1::render_order_status( $order, 'Failed', true );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'class="newspack-ui__status newspack-ui__status--warning"', $html );
		$this->assertStringContainsString( '<a class="newspack-ui__link pay" href="https://example.test/checkout/order-pay/' . $id . '/?pay_for_order=true&#038;key=wc_order_' . $id . '"', $html );
		$this->assertStringContainsString( 'aria-label="Pay now, $15.00 for order #' . $id . '"', $html );
		$this->assertStringContainsString( '>Pay now</a>', $html );

		ob_start();
		My_Account_UI_V1::render_order_status( $order, 'Failed', false );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'Pay now', $html );
	}

	/**
	 * A completed order reads as paid, in the Orders table as on the order's page.
	 */
	public function test_completed_order_reads_as_paid() {
		$order = new WC_Order( [ 'status' => 'completed' ] );

		ob_start();
		My_Account_UI_V1::render_order_status_column( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( '>Paid<', $html );
		$this->assertStringNotContainsString( '>Completed<', $html );
	}

	/**
	 * The Orders table has no actions column.
	 */
	public function test_orders_columns_drop_actions() {
		$columns = My_Account_UI_V1::orders_columns(
			[
				'order-number'  => 'Order',
				'order-status'  => 'Status',
				'order-actions' => 'Actions',
			]
		);

		$this->assertSame( [ 'order-number', 'order-status' ], array_keys( $columns ) );
	}

	/**
	 * Queue an urgent notice among others, and mark it urgent.
	 *
	 * @return callable The urgency filter, to remove after the test.
	 */
	private function queue_urgent_notice(): callable {
		$GLOBALS['newspack_test_wc_notices'] = [
			'notice'  => [
				[
					'notice' => 'Your recurring donation has stopped.',
					'data'   => [],
				],
				[
					'notice' => 'Heads up.',
					'data'   => [],
				],
			],
			'success' => [
				[
					'notice' => 'Saved.',
					'data'   => [],
				],
			],
		];
		$is_urgent = fn( $urgent, $notice ) => str_contains( $notice, 'has stopped' ) ? true : $urgent;
		add_filter( 'newspack_ui_notice_is_urgent', $is_urgent, 10, 2 );
		return $is_urgent;
	}

	/**
	 * Urgent notices leave WooCommerce's queue and print inline; the rest stay queued.
	 */
	public function test_urgent_notices_print_inline() {
		wp_set_current_user( self::factory()->user->create() );
		$is_urgent = $this->queue_urgent_notice();

		ob_start();
		My_Account_UI_V1::print_urgent_notices();
		$html = ob_get_clean();
		remove_filter( 'newspack_ui_notice_is_urgent', $is_urgent );
		$remaining = $GLOBALS['newspack_test_wc_notices'];
		unset( $GLOBALS['newspack_test_wc_notices'] );

		$this->assertStringContainsString( 'class="newspack-ui__notice newspack-ui__notice--warning"', $html );
		$this->assertStringContainsString( 'Your recurring donation has stopped.', $html );
		$this->assertSame( [ 'Heads up.' ], array_column( $remaining['notice'], 'notice' ) );
		$this->assertSame( [ 'Saved.' ], array_column( $remaining['success'], 'notice' ) );
	}

	/**
	 * The subscription page titles itself in its header, so urgent notices follow that header.
	 */
	public function test_urgent_notices_follow_the_subscription_header() {
		wp_set_current_user( self::factory()->user->create() );
		$is_urgent = $this->queue_urgent_notice();
		set_query_var( 'view-subscription', 366 );

		ob_start();
		My_Account_UI_V1::print_urgent_notices();
		$before_header = ob_get_clean();
		ob_start();
		do_action( 'newspack_woocommerce_after_subscription_header', null, [] );
		$after_header = ob_get_clean();

		set_query_var( 'view-subscription', '' );
		remove_filter( 'newspack_ui_notice_is_urgent', $is_urgent );
		unset( $GLOBALS['newspack_test_wc_notices'] );

		$this->assertSame( '', $before_header );
		$this->assertStringContainsString( 'Your recurring donation has stopped.', $after_header );
	}

	/**
	 * Nothing prints when no notice is urgent, and the queue is left as it was.
	 */
	public function test_no_urgent_notices_leave_the_queue_untouched() {
		$queue                               = [
			'success' => [
				[
					'notice' => 'Saved.',
					'data'   => [],
				],
			],
		];
		$GLOBALS['newspack_test_wc_notices'] = $queue;

		ob_start();
		My_Account_UI_V1::print_urgent_notices();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
		$this->assertSame( $queue, $GLOBALS['newspack_test_wc_notices'] );
		unset( $GLOBALS['newspack_test_wc_notices'] );
	}

	/**
	 * Signed-out account forms sit outside newspack-ui, so their notices queue as
	 * snackbars, which print in their own newspack-ui container, instead of inline.
	 */
	public function test_signed_out_notices_queue_as_snackbars() {
		wp_set_current_user( 0 );
		$notices = [
			[
				'notice' => 'Invalid username or email.',
				'data'   => [],
			],
		];

		ob_start();
		include NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/v1/notices/error.php';
		$inline = ob_get_clean();
		ob_start();
		Newspack_UI::print_notices();
		$snackbars = ob_get_clean();
		$queue     = new ReflectionProperty( Newspack_UI::class, 'notices' );
		$queue->setAccessible( true );
		$queue->setValue( null, [] );

		$this->assertSame( '', trim( $inline ) );
		$this->assertMatchesRegularExpression( '/<div id="newspack-ui__notices" class="newspack-ui">.*data-type="error".*Invalid username or email\./s', $snackbars );
	}
}
