<?php
/**
 * Tests adding a payment method to a subscription that has no next payment date.
 *
 * WooCommerce Subscriptions withholds the "change payment method" action from any
 * subscription whose next payment date is unset, via
 * WC_Subscriptions_Change_Payment_Gateway::can_subscription_be_updated_to_new_payment_method().
 * A subscription created by hand in wp-admin never gets one, so the reader is left
 * with no way to put a card on file — the subscription can never be paid or resumed.
 *
 * Coverage:
 *   - Eligibility: the narrow set of subscriptions we open the flow for, and each
 *     condition that keeps it closed.
 *   - Follow-through: once a payment method is attached, the next payment date is
 *     calculated and set, so auto-renewal resumes.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions;

require_once __DIR__ . '/../mocks/wcs-payment-gateways-mocks.php';

/**
 * Tests for opening the add-payment-method flow to subscriptions with no next payment.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_WC_Subscriptions_Add_Payment extends WP_UnitTestCase {

	/**
	 * Reset staged gateway support between tests.
	 */
	public function set_up() {
		parent::set_up();
		WC_Subscriptions_Payment_Gateways::$supports = true;
	}

	/**
	 * Build a subscription double.
	 *
	 * @param array $data Overrides for the subscription data.
	 *
	 * @return WC_Subscription
	 */
	private function make_subscription( $data = [] ) {
		return new WC_Subscription(
			array_merge(
				[
					'id'               => 1,
					'status'           => 'pending',
					'total'            => '125.00',
					'times'            => [ 'next_payment' => 0 ],
					'dates'            => [ 'start' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ) ],
					'billing_interval' => 1,
					'billing_period'   => 'year',
				],
				$data
			)
		);
	}

	/**
	 * The reported case: a subscription awaiting its first payment, with no next
	 * payment date, gets the flow opened.
	 */
	public function test_grants_eligibility_when_no_next_payment_date() {
		$subscription = $this->make_subscription();

		$this->assertTrue(
			WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription )
		);
	}

	/**
	 * Every status we deliberately cover.
	 */
	public function test_grants_eligibility_for_each_covered_status() {
		foreach ( [ 'pending', 'on-hold', 'active', 'pending-cancel' ] as $status ) {
			$subscription = $this->make_subscription( [ 'status' => $status ] );

			$this->assertTrue(
				WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription ),
				sprintf( 'Expected the flow to be open for a "%s" subscription.', $status )
			);
		}
	}

	/**
	 * A scheduled next payment means WCS's own rule already applies; leave it alone.
	 */
	public function test_leaves_subscriptions_with_a_next_payment_alone() {
		$subscription = $this->make_subscription( [ 'times' => [ 'next_payment' => time() + DAY_IN_SECONDS ] ] );

		$this->assertFalse(
			WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription )
		);
	}

	/**
	 * Nothing recurring to charge means nothing to add a card for.
	 */
	public function test_leaves_zero_total_subscriptions_alone() {
		$subscription = $this->make_subscription( [ 'total' => '0.00' ] );

		$this->assertFalse(
			WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription )
		);
	}

	/**
	 * Terminal statuses stay closed.
	 */
	public function test_leaves_terminal_statuses_alone() {
		foreach ( [ 'cancelled', 'expired', 'switched' ] as $status ) {
			$subscription = $this->make_subscription( [ 'status' => $status ] );

			$this->assertFalse(
				WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription ),
				sprintf( 'Expected the flow to stay closed for a "%s" subscription.', $status )
			);
		}
	}

	/**
	 * With no gateway able to take a card from the reader, offering the flow would
	 * lead to a dead end.
	 */
	public function test_leaves_subscriptions_alone_when_no_gateway_supports_the_change() {
		WC_Subscriptions_Payment_Gateways::$supports = false;
		$subscription                                = $this->make_subscription();

		$this->assertFalse(
			WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription )
		);
	}

	/**
	 * When WCS already allows the change, pass its answer straight through.
	 */
	public function test_passes_through_an_existing_yes() {
		$subscription = $this->make_subscription( [ 'status' => 'cancelled' ] );

		$this->assertTrue(
			WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( true, $subscription )
		);
	}

	/**
	 * Attaching a card to an active subscription with no next payment date
	 * schedules one, which is what resumes auto-renewal and, for a subscription
	 * with no orders, unlocks WCS's early renewal as a self-serve way to pay.
	 */
	public function test_sets_a_next_payment_date_once_a_card_is_attached() {
		$subscription = $this->make_subscription( [ 'status' => 'active' ] );

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( $subscription, 'stripe', '' );

		$next_payment = $subscription->get_date( 'next_payment' );

		$this->assertNotEmpty( $next_payment, 'Expected a next payment date to be scheduled.' );
		$this->assertGreaterThan(
			time(),
			strtotime( $next_payment ),
			'Expected the scheduled next payment to be in the future.'
		);
	}

	/**
	 * Statuses where WooCommerce sets the date itself when the outstanding order
	 * is paid. Writing one here would grant a billing period nobody paid for, and
	 * would withdraw the "Add payment method" action while the reader still has
	 * no way to pay.
	 */
	public function test_does_not_schedule_for_statuses_where_payment_sets_the_date() {
		foreach ( [ 'pending', 'on-hold', 'pending-cancel' ] as $status ) {
			$subscription = $this->make_subscription( [ 'status' => $status ] );

			WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( $subscription, 'stripe', '' );

			$this->assertEmpty(
				$subscription->get_date( 'next_payment' ),
				sprintf( 'Expected no next payment date to be written for a "%s" subscription.', $status )
			);
		}
	}

	/**
	 * An already-scheduled next payment is never rewritten.
	 */
	public function test_does_not_reschedule_an_existing_next_payment() {
		$existing     = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );
		$subscription = $this->make_subscription(
			[
				'status' => 'active',
				'times'  => [ 'next_payment' => strtotime( '+30 days' ) ],
				'dates'  => [
					'start'        => gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ),
					'next_payment' => $existing,
				],
			]
		);

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( $subscription, 'stripe', '' );

		$this->assertSame( $existing, $subscription->get_date( 'next_payment' ) );
	}

	/**
	 * No gateway attached means nothing to schedule against.
	 */
	public function test_does_not_schedule_when_no_payment_method_was_attached() {
		$subscription = $this->make_subscription( [ 'status' => 'active' ] );

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( $subscription, '', '' );

		$this->assertEmpty( $subscription->get_date( 'next_payment' ) );
	}
}
