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
	 * Drop the test route.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

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

	/**
	 * Cancel-type transitions a staff member can make over REST, and the
	 * reason each one should record.
	 *
	 * @return array
	 */
	public function staff_rest_transitions(): array {
		return [
			'cancelled'      => [ 'cancelled', Subscriptions_Meta::CANCELLATION_REASON_ADMIN_CANCELLED ],
			'pending-cancel' => [ 'pending-cancel', Subscriptions_Meta::CANCELLATION_REASON_ADMIN_PENDING_CANCEL ],
		];
	}

	/**
	 * The Subscribers wizard and WooCommerce's REST API change subscriptions
	 * over REST, where is_admin() is false. A staff member's change there is
	 * still a manual one.
	 *
	 * @dataProvider staff_rest_transitions
	 * @param string $to_status The status the subscription moves to.
	 * @param string $reason    The reason that should be recorded.
	 */
	public function test_staff_change_over_rest_records_manual_reason( $to_status, $reason ) {
		$reader_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$subscription = wcs_create_subscription( [ 'customer_id' => $reader_id ] );
		wp_set_current_user( $this->create_staff_user() );

		$this->record_during_rest_request( $subscription, $to_status, 'active' );

		$this->assertSame( $reason, $subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY ) );
	}

	/**
	 * Over REST, someone who can't manage the store is never staff, whoever
	 * owns the subscription.
	 */
	public function test_reader_change_over_rest_records_user_reason() {
		$subscription = wcs_create_subscription();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->record_during_rest_request( $subscription, 'cancelled', 'active' );

		$this->assertSame(
			Subscriptions_Meta::CANCELLATION_REASON_USER_CANCELLED,
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY )
		);
	}

	/**
	 * A staff member changing their own subscription over REST is acting as a
	 * reader, as they are on the front end.
	 */
	public function test_staff_change_to_own_subscription_over_rest_records_user_reason() {
		$staff_id     = $this->create_staff_user();
		$subscription = wcs_create_subscription( [ 'customer_id' => $staff_id ] );
		wp_set_current_user( $staff_id );

		$this->record_during_rest_request( $subscription, 'pending-cancel', 'active' );

		$this->assertSame(
			Subscriptions_Meta::CANCELLATION_REASON_USER_PENDING_CANCEL,
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY )
		);
	}

	/**
	 * Outside wp-admin and REST, a staff member is acting as a reader: for
	 * example, cancelling their own subscription from My Account.
	 */
	public function test_staff_change_on_front_end_records_user_reason() {
		$subscription = wcs_create_subscription();
		wp_set_current_user( $this->create_staff_user() );
		// A page render can hold an idle REST server after an in-process request; that alone isn't REST.
		rest_get_server();

		Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, 'cancelled', 'active' );

		$this->assertSame(
			Subscriptions_Meta::CANCELLATION_REASON_USER_CANCELLED,
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY )
		);
	}

	/**
	 * A change made on a wp-admin screen is a manual one.
	 */
	public function test_staff_change_in_wp_admin_records_manual_reason() {
		$subscription = wcs_create_subscription();
		wp_set_current_user( $this->create_staff_user() );
		set_current_screen( 'edit.php' );

		Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, 'cancelled', 'active' );

		$this->assertSame(
			Subscriptions_Meta::CANCELLATION_REASON_ADMIN_CANCELLED,
			$subscription->get_meta( Subscriptions_Meta::CANCELLATION_REASON_META_KEY )
		);
	}

	/**
	 * An administrator who can manage the store, as on a WooCommerce site.
	 *
	 * @return int The user ID.
	 */
	private function create_staff_user(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_userdata( $user_id )->add_cap( 'manage_woocommerce' );
		return $user_id;
	}

	/**
	 * Record a status change from inside a real REST dispatch, as the
	 * Subscribers wizard's cancel endpoint does.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @param string          $to_status    The status it moves to.
	 * @param string          $from_status  The status it moves from.
	 */
	private function record_during_rest_request( $subscription, string $to_status, string $from_status ): void {
		$register = function () use ( $subscription, $to_status, $from_status ) {
			register_rest_route(
				'newspack-test/v1',
				'/subscription-status',
				[
					'methods'             => 'POST',
					'callback'            => function () use ( $subscription, $to_status, $from_status ) {
						Subscriptions_Meta::maybe_record_cancelled_subscription_meta( $subscription, $to_status, $from_status );
						return rest_ensure_response( true );
					},
					'permission_callback' => '__return_true',
				]
			);
		};
		// A fresh server fires rest_api_init, which registers the route.
		$GLOBALS['wp_rest_server'] = null;
		add_action( 'rest_api_init', $register );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/newspack-test/v1/subscription-status' ) );
		remove_action( 'rest_api_init', $register );

		$this->assertSame( 200, $response->get_status() );
	}
}
