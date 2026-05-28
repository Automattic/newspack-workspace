<?php
/**
 * Tests CLI scripts for WooComerce.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_Cli;

/**
 * Tests the Webhooks functionality.
 */
class Newspack_Test_WooCommerce_Cli extends WP_UnitTestCase {
	/**
	 * A user to assign test subscriptions/orders.
	 *
	 * @var int
	 */
	private static $user_id = null;

	/**
	 * Set up before class.
	 */
	public static function set_up_before_class() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		self::$user_id = wp_insert_user(
			[
				'user_login' => 'test_user',
				'user_email' => 'test@example.com',
				'user_pass'  => 'password',
				'meta_input' => [
					'first_name'     => 'John',
					'last_name'      => 'Doe',
					'wc_total_spent' => 100,
				],
			]
		);
	}

	/**
	 * Test a healthy subscription that has a future next_payment date.
	 */
	public function test_healthy_subscription() {
		$subscription = new WC_Subscription(
			[
				'id'               => 1,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'total'            => 10, // Amount of recurring payment.
				'dates'            => [
					'start'        => gmdate( 'Y-m-d H:i:s', strtotime( 'first day of -6 month' ) ),
					'next_payment' => gmdate( 'Y-m-d H:i:s', strtotime( 'first day of +1 month' ) ),
				],
				'orders'           => [
					wc_create_order(
						[
							'customer_id'    => self::$user_id,
							'status'         => 'completed',
							'total'          => 10,
							'date_completed' => gmdate( 'Y-m-d H:i:s', strtotime( 'first day of -1 month' ) ),
						]
					),
				],
			]
		);
		$result = WooCommerce_Cli::validate_subscription_dates( $subscription );
		$this->assertFalse( $result, 'Healthy subscription wasn’t processed.' );
	}

	/**
	 * Test a subscription that's missing a next payment date and has no successful orders.
	 */
	public function test_missing_next_payment_date() {
		$subscription = new WC_Subscription(
			[
				'id'               => 2,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'total'            => 10, // Amount of recurring payment.
				'dates'            => [
					'start' => gmdate( 'Y-m-d H:i:s', strtotime( 'first day of -6 month' ) ),
				],
			]
		);
		$result = WooCommerce_Cli::validate_subscription_dates( $subscription );
		$this->assertEquals(
			$result,
			[
				'ID'                => $subscription->get_id(),
				'status'            => $subscription->get_status(),
				'start_date'        => $subscription->get_date( 'start' ),
				'next_payment_date' => $subscription->calculate_date( 'next_payment' ),
				'end_date'          => $subscription->get_date( 'end' ),
				'billing_period'    => $subscription->get_billing_period(),
				'billing_interval'  => $subscription->get_billing_interval(),
				'missed_periods'    => 6,
				'missed_total'      => 60,
			],
			'Subscription was processed.'
		);
		$this->assertGreaterThan( time(), strtotime( $subscription->get_date( 'next_payment' ) ), 'Next payment date is now in the future.' );
	}

	/**
	 * Test a subscription that's missing a next payment date but has a successful order.
	 */
	public function test_missing_next_payment_date_with_order() {
		$subscription = new WC_Subscription(
			[
				'id'               => 3,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'total'            => 10, // Amount of recurring payment.
				'dates'            => [
					'start' => gmdate( 'Y-m-d H:i:s', strtotime( 'first day of -6 month' ) ),
				],
				'orders'           => [
					wc_create_order(
						[
							'customer_id'    => self::$user_id,
							'status'         => 'completed',
							'total'          => 10,
							'date_completed' => gmdate( 'Y-m-d H:i:s', strtotime( 'first day of -3 month' ) ),
						]
					),
				],
			]
		);
		$result = WooCommerce_Cli::validate_subscription_dates( $subscription );
		$this->assertEquals(
			$result,
			[
				'ID'                => $subscription->get_id(),
				'status'            => $subscription->get_status(),
				'start_date'        => $subscription->get_date( 'start' ),
				'next_payment_date' => $subscription->calculate_date( 'next_payment' ),
				'end_date'          => $subscription->get_date( 'end' ),
				'billing_period'    => $subscription->get_billing_period(),
				'billing_interval'  => $subscription->get_billing_interval(),
				'missed_periods'    => 3,
				'missed_total'      => 30,
			],
			'Subscription was processed.'
		);
		$this->assertGreaterThan( time(), strtotime( $subscription->get_date( 'next_payment' ) ), 'Next payment date is now in the future.' );
	}

	/**
	 * Test a subscription that's missing a next payment date but has an end date before the calculated next payment date.
	 */
	public function test_missing_next_payment_date_with_end_date() {
		$subscription = new WC_Subscription(
			[
				'id'               => 4,
				'status'           => 'active',
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'total'            => 10, // Amount of recurring payment.
				'dates'            => [
					'start' => gmdate( 'Y-m-d H:i:s', strtotime( 'first day of -6 month' ) ),
					'end'   => gmdate( 'Y-m-d H:i:s', strtotime( 'last day of' ) ),
				],
			]
		);
		$result = WooCommerce_Cli::validate_subscription_dates( $subscription );
		$this->assertEquals(
			$result,
			[
				'ID'                => $subscription->get_id(),
				'status'            => $subscription->get_status(),
				'start_date'        => $subscription->get_date( 'start' ),
				'next_payment_date' => 0,
				'end_date'          => $subscription->get_date( 'end' ),
				'billing_period'    => $subscription->get_billing_period(),
				'billing_interval'  => $subscription->get_billing_interval(),
				'missed_periods'    => 6,
				'missed_total'      => 60,
			],
			'Subscription was processed.'
		);
		$this->assertEmpty( $subscription->get_date( 'next_payment' ), 'Next payment date not set because the end date will occur first.' );
	}
}
