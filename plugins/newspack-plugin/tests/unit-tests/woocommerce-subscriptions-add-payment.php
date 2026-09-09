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
 *     WCS refusal we deliberately keep in place (manual-renewal store setting, zero
 *     total, no capable gateway, gateway that cannot cancel).
 *   - Follow-through: for an active subscription a next payment date is calculated
 *     and set; every other status, an unschedulable date, a gateway that refuses
 *     date changes, and a failing write are all left alone.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Subscriptions;

require_once __DIR__ . '/../mocks/wc-mocks.php';
require_once __DIR__ . '/../mocks/wcs-payment-gateways-mocks.php';
require_once __DIR__ . '/../mocks/wcs-add-payment-mocks.php';

/**
 * Tests for opening the add-payment-method flow to subscriptions with no next payment.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_WC_Subscriptions_Add_Payment extends WP_UnitTestCase {

	/**
	 * The payment result WCS passes once the card has been accepted.
	 */
	const SUCCESS = [ 'result' => 'success' ];

	/**
	 * The payment result WCS passes when the card was declined.
	 */
	const FAILURE = [ 'result' => 'failure' ];

	/**
	 * Reset staged gateway support between tests.
	 */
	public function set_up() {
		parent::set_up();
		WC_Subscriptions_Payment_Gateways::$supports = true;
	}

	/**
	 * Reset the staged store setting.
	 */
	public function tear_down() {
		WC_Subscriptions_Add_Payment_Store_Double::$store_requires_manual_renewal = false;
		parent::tear_down();
	}

	/**
	 * Build a subscription double.
	 *
	 * @param array $data Overrides for the subscription data.
	 *
	 * @return WC_Subscription_Add_Payment_Double
	 */
	private function make_subscription( $data = [] ) {
		return new WC_Subscription_Add_Payment_Double(
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
	 * Every status we deliberately cover. pending-cancel is not among them.
	 */
	public function test_grants_eligibility_for_each_covered_status() {
		foreach ( [ 'pending', 'on-hold', 'active' ] as $status ) {
			$subscription = $this->make_subscription( [ 'status' => $status ] );

			$this->assertTrue(
				WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription ),
				sprintf( 'Expected the flow to be open for a "%s" subscription.', $status )
			);
		}
	}

	/**
	 * A pending-cancel subscription always has next payment cleared, so it would
	 * always match the override; it is excluded because the action does nothing for
	 * a subscription winding down (reactivating restores WCS's own flow).
	 */
	public function test_leaves_pending_cancel_alone() {
		$subscription = $this->make_subscription( [ 'status' => 'pending-cancel' ] );

		$this->assertFalse(
			WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription )
		);
	}

	/**
	 * A store that has switched automatic payments off — WCS manual renewals with
	 * the auto-renew toggle disabled — keeps the flow closed for a subscription
	 * that otherwise qualifies. Exercised through a subclass that overrides the
	 * store-setting read, via late static binding.
	 */
	public function test_respects_the_manual_renewal_store_setting() {
		$subscription = $this->make_subscription();

		WC_Subscriptions_Add_Payment_Store_Double::$store_requires_manual_renewal = true;
		$this->assertFalse(
			WC_Subscriptions_Add_Payment_Store_Double::allow_add_payment_method_without_next_payment( false, $subscription ),
			'Expected the flow to stay closed when the store requires manual renewals.'
		);

		WC_Subscriptions_Add_Payment_Store_Double::$store_requires_manual_renewal = false;
		$this->assertTrue(
			WC_Subscriptions_Add_Payment_Store_Double::allow_add_payment_method_without_next_payment( false, $subscription ),
			'Expected the flow to open when automatic payments are available.'
		);
	}

	/**
	 * A gateway that cannot cancel keeps the flow closed, matching the WCS bail. A
	 * card-less subscription reads as manual and so passes this check.
	 */
	public function test_leaves_subscriptions_alone_when_the_gateway_cannot_cancel() {
		$subscription = $this->make_subscription( [ 'payment_method_supports' => [ 'subscription_amount_changes' ] ] );

		$this->assertFalse(
			WooCommerce_Subscriptions::allow_add_payment_method_without_next_payment( false, $subscription )
		);
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
	 * schedules the exact date calculate_date() returns, and records it in an
	 * order note — which is what unlocks WCS's early renewal as a self-serve way
	 * to pay.
	 */
	public function test_sets_a_next_payment_date_once_a_card_is_attached() {
		$scheduled    = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) );
		$subscription = $this->make_subscription(
			[
				'status'         => 'active',
				'calculate_date' => $scheduled,
			]
		);

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::SUCCESS, $subscription );

		$this->assertSame(
			$scheduled,
			$subscription->get_date( 'next_payment' ),
			'Expected the calculated date to be written verbatim.'
		);
	}

	/**
	 * When calculate_date() returns 0 — the next period would fall past the end
	 * date — a subscription winding down to a fixed end has nothing further to
	 * bill, so no date is written.
	 */
	public function test_does_not_schedule_when_the_calculated_date_is_zero() {
		$subscription = $this->make_subscription(
			[
				'status'         => 'active',
				'calculate_date' => '0',
			]
		);

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::SUCCESS, $subscription );

		// Strict identity, not assertEmpty: the mock returns int 0 when the date was
		// never written, but string '0' if the guard were removed and '0' written —
		// and assertEmpty() would pass on both, so it could not catch the regression.
		$this->assertSame( 0, $subscription->get_date( 'next_payment' ) );
	}

	/**
	 * A gateway that refuses date changes gets no Woo-side schedule, so its dates
	 * and Woo's never diverge.
	 */
	public function test_does_not_schedule_when_the_gateway_refuses_date_changes() {
		$subscription = $this->make_subscription(
			[
				'status'              => 'active',
				'can_date_be_updated' => false,
				'calculate_date'      => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
			]
		);

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::SUCCESS, $subscription );

		$this->assertEmpty( $subscription->get_date( 'next_payment' ) );
	}

	/**
	 * A failing date write is caught, not fatal: the reader keeps the card they
	 * just added, and no date is written.
	 */
	public function test_swallows_a_failing_date_write() {
		$subscription = $this->make_subscription(
			[
				'status'              => 'active',
				'update_dates_throws' => true,
				'calculate_date'      => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
			]
		);

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::SUCCESS, $subscription );

		$this->assertEmpty( $subscription->get_date( 'next_payment' ) );
	}

	/**
	 * Statuses where WooCommerce sets the date itself when the outstanding order
	 * is paid. Writing one here would grant a billing period nobody paid for, and
	 * would withdraw the "Add payment method" action while the reader still has
	 * no way to pay.
	 */
	public function test_does_not_schedule_for_statuses_where_payment_sets_the_date() {
		foreach ( [ 'pending', 'on-hold', 'pending-cancel' ] as $status ) {
			$subscription = $this->make_subscription(
				[
					'status'         => $status,
					'calculate_date' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
				]
			);

			WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::SUCCESS, $subscription );

			$this->assertEmpty(
				$subscription->get_date( 'next_payment' ),
				sprintf( 'Expected no next payment date to be written for a "%s" subscription.', $status )
			);
		}
	}

	/**
	 * A declined card writes nothing. WCS applies this filter after
	 * process_payment() and bails on a non-success result, so scheduling here must
	 * be conditional on that result — otherwise a reader whose card was refused
	 * keeps access until a renewal runs against a card that was never accepted.
	 */
	public function test_does_not_schedule_when_the_payment_failed() {
		$subscription = $this->make_subscription(
			[
				'status'         => 'active',
				'calculate_date' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) ),
			]
		);

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::FAILURE, $subscription );

		$this->assertSame( 0, $subscription->get_date( 'next_payment' ) );
	}

	/**
	 * The filter must hand back whatever WCS gave it, success or not.
	 */
	public function test_returns_the_payment_result_unchanged() {
		$subscription = $this->make_subscription( [ 'status' => 'active' ] );

		$this->assertSame(
			self::SUCCESS,
			WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::SUCCESS, $subscription )
		);
		$this->assertSame(
			self::FAILURE,
			WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::FAILURE, $subscription )
		);
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

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::SUCCESS, $subscription );

		$this->assertSame( $existing, $subscription->get_date( 'next_payment' ) );
	}

	/**
	 * No chargeable gateway on the subscription means nothing to schedule against.
	 * Asked of the subscription rather than inferred from a method string, since
	 * this also runs for admin and bulk updates.
	 */
	public function test_does_not_schedule_when_no_gateway_is_attached() {
		$subscription = $this->make_subscription(
			[
				'status'              => 'active',
				'has_payment_gateway' => false,
			]
		);

		WooCommerce_Subscriptions::schedule_next_payment_after_payment_method_added( self::SUCCESS, $subscription );

		$this->assertEmpty( $subscription->get_date( 'next_payment' ) );
	}
}
