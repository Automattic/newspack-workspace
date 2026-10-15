<?php
/**
 * Tests the WooCommerce Subscriptions integration class.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscriptions_Meta;
use Newspack\WooCommerce_Subscriptions;

/**
 * Test WooCommerce Subscriptions integration functionality.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscriptions_Meta extends WP_UnitTestCase {
	/**
	 * Test Subscriptions_Meta::maybe_record_cancelled_subscription_meta.
	 */
	public function test_maybe_record_cancelled_subscription_meta() {
		$subscription = wcs_create_subscription();
		$this->assertEquals(
			'',
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY ),
			'Cancellation reason meta should be empty before any updates.'
		);
		Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, 'pending-cancel', 'cancelled' );
		$this->assertEquals(
			'',
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY ),
			'Cancellation reason meta should be empty when subscription from-status is cancelled.'
		);
		Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, 'pending-cancel', 'active' );
		$this->assertEquals(
			Subscriptions_Meta::CANCELLATION_REASON_USER_PENDING_CANCEL,
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY ),
			'Cancellation reason meta should be user-pending-cancel when subscription is updated to pending-cancel from active status.'
		);
		Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, 'active', 'pending-cancel' );
		$this->assertEquals(
			'',
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY ),
			'Cancellation reason meta should be reset when subscription is updated to active from pending-cancel status.'
		);
		Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, 'cancelled', 'pending-cancel' );
		$this->assertEquals(
			Subscriptions_Meta::CANCELLATION_REASON_USER_CANCELLED,
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY ),
			'Cancellation reason meta should be set to user-cancelled when subscription is cancelled from pending-cancel status.'
		);
		Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, 'expired', 'active' );
		$this->assertEquals(
			Subscriptions_Meta::CANCELLATION_REASON_EXPIRED,
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY ),
			'Cancellation reason meta should be set to expired when subscription is expired from active status.'
		);
	}

	/**
	 * Ended statuses and the reason each one records.
	 *
	 * @return array
	 */
	public function ended_statuses() {
		return [
			'cancelled' => [ 'cancelled', Subscriptions_Meta::CANCELLATION_REASON_ADMIN_CANCELLED ],
			'expired'   => [ 'expired', Subscriptions_Meta::CANCELLATION_REASON_EXPIRED ],
		];
	}

	/**
	 * A reactivated subscription stops carrying the reason it ended, so the ESP
	 * sync stops reporting one.
	 *
	 * @dataProvider ended_statuses
	 * @param string $from_status The ended status.
	 * @param string $reason      The reason recorded when it ended.
	 */
	public function test_reactivation_clears_cancellation_reason( $from_status, $reason ) {
		$subscription = wcs_create_subscription( [ 'meta' => [ Subscriptions_Meta::CANCELLATION_REASON_META_KEY => $reason ] ] );

		Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, 'active', $from_status );

		$this->assertSame( '', $subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY ) );
	}
}
