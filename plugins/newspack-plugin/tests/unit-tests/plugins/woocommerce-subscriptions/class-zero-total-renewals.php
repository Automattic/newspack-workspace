<?php
/**
 * Tests renewal messaging withheld for free subscriptions and renewal orders.
 *
 * @package Newspack\Tests
 */

use Newspack\Zero_Total_Renewals;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';

/**
 * A free subscription still renews every cycle — WooCommerce Subscriptions creates a
 * renewal order and completes it immediately, because nothing is owed. These tests pin
 * the reader-facing messages that renewal must not produce, and the boundary that keeps
 * a paying reader's messages intact: a 100% recurring coupon zeroes the total but not
 * the pre-discount subtotal.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Zero_Total_Renewals extends WP_UnitTestCase {
	/**
	 * Every notification type WooCommerce Subscriptions can schedule for a subscription.
	 */
	const ALL_NOTIFICATIONS = [ 'next_payment', 'trial_end', 'end' ];

	/**
	 * Build a subscription double carrying a total and a pre-discount subtotal.
	 *
	 * @param float $total    Recurring total.
	 * @param float $subtotal Pre-discount subtotal.
	 * @return WC_Subscription
	 */
	private function subscription( $total, $subtotal ) {
		return new WC_Subscription(
			[
				'id'       => 1,
				'total'    => $total,
				'subtotal' => $subtotal,
			]
		);
	}

	/**
	 * Build a renewal order double carrying a total and a pre-discount subtotal.
	 *
	 * The mock requires a status and writes customer meta on a completed one, which
	 * this fixture has no use for — `get_total()` and `get_subtotal()` are all the filter
	 * reads.
	 *
	 * @param float $total    Order total.
	 * @param float $subtotal Pre-discount subtotal.
	 * @return WC_Order
	 */
	private function renewal_order( $total, $subtotal ) {
		return new WC_Order(
			[
				'status'   => 'pending',
				'total'    => $total,
				'subtotal' => $subtotal,
			]
		);
	}

	/**
	 * A free subscription loses its upcoming-renewal reminder, and nothing else.
	 *
	 * Trial-end and expiration notices tell the reader their access is about to change,
	 * which is true whether or not they pay for it.
	 */
	public function test_free_subscription_keeps_only_access_notifications() {
		$this->assertSame(
			[ 'trial_end', 'end' ],
			Zero_Total_Renewals::skip_renewal_notification( self::ALL_NOTIFICATIONS, $this->subscription( 0, 0 ) )
		);
	}

	/**
	 * A subscription the publisher bills is left alone, whether it charges this period or
	 * is fully discounted for it. Both callbacks read the same free/paid test, so pinning
	 * the discounted case here pins it for the receipt too, where it decides the outcome.
	 */
	public function test_a_paying_subscription_keeps_the_renewal_reminder() {
		$this->assertSame(
			self::ALL_NOTIFICATIONS,
			Zero_Total_Renewals::skip_renewal_notification( self::ALL_NOTIFICATIONS, $this->subscription( 10.00, 10.00 ) )
		);
		$this->assertSame(
			self::ALL_NOTIFICATIONS,
			Zero_Total_Renewals::skip_renewal_notification( self::ALL_NOTIFICATIONS, $this->subscription( 0, 10.00 ) )
		);
	}

	/**
	 * The $0.00 receipt is the message readers actually see today, so it is the one that
	 * has to stop — and only for a renewal that was free rather than discounted.
	 */
	public function test_receipt_is_withheld_only_from_a_free_renewal_order() {
		$this->assertFalse( Zero_Total_Renewals::is_renewal_receipt_enabled( true, $this->renewal_order( 0, 0 ) ) );
		$this->assertTrue( Zero_Total_Renewals::is_renewal_receipt_enabled( true, $this->renewal_order( 25.00, 25.00 ) ) );
		$this->assertTrue( Zero_Total_Renewals::is_renewal_receipt_enabled( true, $this->renewal_order( 0, 25.00 ) ) );
	}

	/**
	 * A publisher who turned the receipt off keeps it off.
	 */
	public function test_the_filter_never_enables_a_disabled_receipt() {
		$this->assertFalse( Zero_Total_Renewals::is_renewal_receipt_enabled( false, $this->renewal_order( 25.00, 25.00 ) ) );
	}

	/**
	 * WooCommerce evaluates the filter with no order in hand every time a publisher opens
	 * the email settings screen, so the guard that lets that through is a hot path, not a
	 * defensive flourish: without it the screen fatals on a method call against null.
	 */
	public function test_the_publisher_setting_survives_an_evaluation_with_no_order() {
		$this->assertTrue( Zero_Total_Renewals::is_renewal_receipt_enabled( true, null ) );
	}
}
