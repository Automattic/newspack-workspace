<?php
/**
 * Tests the admin action that reactivates a cancelled or expired subscription.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscription_Reactivation;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';

/**
 * WooCommerce Subscriptions won't move a Cancelled or Expired subscription back to
 * Active. These tests pin which subscriptions get the admin's one-click way around
 * that, and what running it leaves behind. Auto-renewing subscriptions are excluded
 * because reactivating one would start charging the saved payment method again.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_Subscription_Reactivation extends WP_UnitTestCase {
	/**
	 * Reset the mock subscription store and role recorder.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $wcs_mock_made_active_user_ids;
		$subscriptions_database        = [];
		$wcs_mock_made_active_user_ids = [];
	}

	/**
	 * Create a subscription in the mock store.
	 *
	 * @param string $status         Subscription status.
	 * @param bool   $manual_renewal Whether the subscription is set to renew manually.
	 * @param array  $data           Extra data for the mock.
	 * @return WC_Subscription
	 */
	private function subscription( $status, $manual_renewal = true, $data = [] ) {
		return wcs_create_subscription(
			array_merge(
				[
					'status'                  => $status,
					'customer_id'             => 123,
					'requires_manual_renewal' => $manual_renewal,
				],
				$data
			)
		);
	}

	/**
	 * Whether the subscription's actions dropdown offers reactivation.
	 *
	 * @param mixed $order The object the edit screen is showing.
	 * @return bool
	 */
	private function offers_reactivation( $order ) {
		$actions = Subscription_Reactivation::add_order_action( [], $order );
		return isset( $actions[ Subscription_Reactivation::ORDER_ACTION ] );
	}

	/**
	 * Statuses WooCommerce Subscriptions treats as final.
	 *
	 * @return array
	 */
	public function ended_statuses() {
		return [
			'cancelled' => [ 'cancelled' ],
			'expired'   => [ 'expired' ],
		];
	}

	/**
	 * Statuses WooCommerce Subscriptions can already move to Active, or that are Active.
	 *
	 * @return array
	 */
	public function other_statuses() {
		return [
			'active'         => [ 'active' ],
			'on-hold'        => [ 'on-hold' ],
			'pending'        => [ 'pending' ],
			'pending-cancel' => [ 'pending-cancel' ],
		];
	}

	/**
	 * A manual-renewal subscription that has ended gets the action.
	 *
	 * @dataProvider ended_statuses
	 * @param string $status Subscription status.
	 */
	public function test_action_offered_for_ended_manual_subscription( $status ) {
		$this->assertTrue( $this->offers_reactivation( $this->subscription( $status ) ) );
	}

	/**
	 * A subscription that hasn't ended doesn't get the action; WCS's own status
	 * dropdown already handles it.
	 *
	 * @dataProvider other_statuses
	 * @param string $status Subscription status.
	 */
	public function test_action_withheld_for_subscription_that_has_not_ended( $status ) {
		$this->assertFalse( $this->offers_reactivation( $this->subscription( $status ) ) );
	}

	/**
	 * An auto-renewing subscription doesn't get the action, even while it's
	 * treated as manual because its gateway is unavailable: once the gateway
	 * returns, it would start charging again.
	 *
	 * @dataProvider ended_statuses
	 * @param string $status Subscription status.
	 */
	public function test_action_withheld_for_auto_renewing_subscription( $status ) {
		$auto_renewing                = $this->subscription( $status, false );
		$auto_renewing_without_gateway = $this->subscription( $status, false, [ 'is_manual' => true ] );

		$this->assertFalse( $this->offers_reactivation( $auto_renewing ) );
		$this->assertFalse( $this->offers_reactivation( $auto_renewing_without_gateway ) );
	}

	/**
	 * Regular orders share the actions dropdown and never get the action.
	 */
	public function test_action_withheld_for_non_subscription() {
		$order = new WC_Order( [ 'status' => 'cancelled' ] );

		$this->assertFalse( $this->offers_reactivation( $order ) );
		$this->assertFalse( $this->offers_reactivation( null ) );
	}

	/**
	 * Reactivating leaves the subscription Active with no end or cancelled date,
	 * so nothing is scheduled to end it again.
	 *
	 * @dataProvider ended_statuses
	 * @param string $status Subscription status.
	 */
	public function test_reactivate_makes_subscription_active_with_no_end_date( $status ) {
		$subscription = $this->subscription(
			$status,
			true,
			[
				'dates' => [
					'start'     => '2026-01-01 00:00:00',
					'end'       => '2026-09-30 00:00:00',
					'cancelled' => '2026-09-30 00:00:00',
				],
			]
		);

		Subscription_Reactivation::reactivate( $subscription );

		$this->assertSame( 'active', $subscription->get_status() );
		$this->assertSame( [ 'active' ], $subscription->data['saved_statuses'] ?? [] );
		$this->assertSame( 0, $subscription->get_date( 'end' ) );
		$this->assertSame( 0, $subscription->get_date( 'cancelled' ) );
		$this->assertSame( '2026-01-01 00:00:00', $subscription->get_date( 'start' ) );
	}

	/**
	 * The status change is flagged as the admin's, so the note WCS adds for it
	 * names the admin who ran the action.
	 *
	 * @dataProvider ended_statuses
	 * @param string $status Subscription status.
	 */
	public function test_reactivate_records_note_attributed_to_admin( $status ) {
		$subscription = $this->subscription( $status );

		Subscription_Reactivation::reactivate( $subscription );

		$status_set = end( $subscription->data['status_sets'] );
		$this->assertNotEmpty( $status_set['note'] );
		$this->assertTrue( $status_set['manual'] );
	}

	/**
	 * Cancelling can demote the customer's user role. WCS restores it only on the
	 * status change path that refuses ended subscriptions, so reactivation has to.
	 */
	public function test_reactivate_restores_customer_role() {
		global $wcs_mock_made_active_user_ids;

		Subscription_Reactivation::reactivate( $this->subscription( 'cancelled' ) );

		$this->assertSame( [ 123 ], $wcs_mock_made_active_user_ids );
	}

	/**
	 * A request naming the action for a subscription it isn't offered on changes
	 * nothing. The dropdown hides it, but the form value can be submitted anyway.
	 */
	public function test_reactivate_ignores_auto_renewing_subscription() {
		global $wcs_mock_made_active_user_ids;
		$subscription = $this->subscription( 'cancelled', false, [ 'dates' => [ 'end' => '2026-09-30 00:00:00' ] ] );

		Subscription_Reactivation::reactivate( $subscription );

		$this->assertSame( 'cancelled', $subscription->get_status() );
		$this->assertSame( '2026-09-30 00:00:00', $subscription->get_date( 'end' ) );
		$this->assertSame( [], $wcs_mock_made_active_user_ids );
	}
}
