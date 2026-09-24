<?php
/**
 * Tests the On-Hold Duration retry-cancellation expiration handling.
 *
 * @package Newspack\Tests
 */

use Newspack\On_Hold_Duration;

require_once __DIR__ . '/../../../mocks/wc-mocks.php';
require_once __DIR__ . '/../../../mocks/on-hold-duration-mocks.php';

/**
 * Test On_Hold_Duration expiration (re)scheduling when a payment retry ends.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Newspack_Test_On_Hold_Duration extends WP_UnitTestCase {
	/**
	 * Action Scheduler group used by On_Hold_Duration.
	 */
	const AS_GROUP = 'newspack';

	/**
	 * Reset mock state before each test.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['teams_mock_subscriptions'] = [];
		update_option( 'newspack_subscriptions_on_hold_duration', 30 );
		update_option( 'woocommerce_subscriptions_enable_retry', 'yes' );
	}

	/**
	 * Reset mock state after each test.
	 */
	public function tear_down() {
		$GLOBALS['teams_mock_subscriptions'] = [];
		parent::tear_down();
	}

	/**
	 * A minimal WCS_Retry double. The handler only reads get_order_id(), and the
	 * mocked wcs_get_subscriptions_for_renewal_order() ignores the id, so it's fixed.
	 *
	 * @return object
	 */
	private function mock_retry() {
		return new class() {
			/**
			 * Stubbed renewal order ID.
			 *
			 * @return int
			 */
			public function get_order_id() {
				return 1;
			}
		};
	}

	/**
	 * Build a mock subscription with the given id/status/payment_retry.
	 *
	 * @param int      $id            Subscription ID.
	 * @param string   $status        Subscription status.
	 * @param int      $payment_retry payment_retry date (0 = none).
	 * @param int|null $next_payment  next_payment time (defaults to one month out).
	 * @return WC_Subscription
	 */
	private function make_subscription( $id, $status, $payment_retry = 0, $next_payment = null ) {
		return new WC_Subscription(
			[
				'id'        => $id,
				'status'    => $status,
				'is_manual' => false,
				'dates'     => [ 'payment_retry' => $payment_retry ],
				'times'     => [ 'next_payment' => $next_payment ?? time() + MONTH_IN_SECONDS ],
			]
		);
	}

	/**
	 * Whether an expiration action is scheduled for a subscription id.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return bool
	 */
	private function expiration_is_scheduled( $subscription_id ) {
		return false !== as_next_scheduled_action( On_Hold_Duration::AS_HOOK, [ $subscription_id ], self::AS_GROUP );
	}

	/**
	 * A terminal retry that leaves the subscription on-hold with no pending
	 * retry should (re)schedule the expiration.
	 */
	public function test_schedules_expiration_when_retry_ends_and_subscription_stuck_on_hold() {
		$sub_id                              = 90001;
		$next_payment                        = time() + MONTH_IN_SECONDS;
		$GLOBALS['teams_mock_subscriptions'] = [ $this->make_subscription( $sub_id, 'on-hold', 0, $next_payment ) ];

		On_Hold_Duration::maybe_reschedule_expiration_on_retry_end( $this->mock_retry(), 'cancelled' );

		$scheduled = as_next_scheduled_action( On_Hold_Duration::AS_HOOK, [ $sub_id ], self::AS_GROUP );
		$this->assertNotFalse( $scheduled );
		// Automatic subscription: expiry lands at next_payment + on-hold duration (no manual grace).
		$expected = $next_payment + ( On_Hold_Duration::get_on_hold_duration() * DAY_IN_SECONDS );
		$this->assertEqualsWithDelta( $expected, $scheduled, 2 );
	}

	/**
	 * Pending/processing retries still drive their own progression, so the
	 * handler must not schedule an expiration for them.
	 *
	 * @dataProvider non_terminal_statuses
	 * @param string $status A non-terminal retry status.
	 */
	public function test_does_nothing_for_non_terminal_retry( $status ) {
		$sub_id                              = 90002;
		$GLOBALS['teams_mock_subscriptions'] = [ $this->make_subscription( $sub_id, 'on-hold', 0 ) ];

		On_Hold_Duration::maybe_reschedule_expiration_on_retry_end( $this->mock_retry(), $status );

		$this->assertFalse( $this->expiration_is_scheduled( $sub_id ) );
	}

	/**
	 * Non-terminal retry statuses.
	 *
	 * @return array
	 */
	public function non_terminal_statuses() {
		return [
			'pending'    => [ 'pending' ],
			'processing' => [ 'processing' ],
		];
	}

	/**
	 * A subscription that is not on-hold (e.g. reactivated) is not stranded,
	 * so no expiration should be scheduled.
	 */
	public function test_skips_when_subscription_not_on_hold() {
		$sub_id                              = 90003;
		$GLOBALS['teams_mock_subscriptions'] = [ $this->make_subscription( $sub_id, 'active', 0 ) ];

		On_Hold_Duration::maybe_reschedule_expiration_on_retry_end( $this->mock_retry(), 'cancelled' );

		$this->assertFalse( $this->expiration_is_scheduled( $sub_id ) );
	}

	/**
	 * When a pending retry remains, the retry progression still drives
	 * expiration, so the handler must not schedule anything.
	 */
	public function test_skips_when_payment_retry_still_pending() {
		$sub_id                              = 90004;
		$GLOBALS['teams_mock_subscriptions'] = [ $this->make_subscription( $sub_id, 'on-hold', time() + DAY_IN_SECONDS ) ];

		On_Hold_Duration::maybe_reschedule_expiration_on_retry_end( $this->mock_retry(), 'failed' );

		$this->assertFalse( $this->expiration_is_scheduled( $sub_id ) );
	}
}
