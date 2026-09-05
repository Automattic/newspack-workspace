<?php
/**
 * Tests for the Subscribers wizard on-hold recovery write endpoints (NPPD-1753).
 *
 * The person profile's first write path: reactivating an on-hold individual
 * subscription. Three admin actions — reactivate for free, charge the saved
 * payment method now, and send the customer a payment link. These tests pin the
 * boundaries that are not obvious from the code: the permission gate, the
 * on-hold-only state rule, the individual-only rule (groups are managed from
 * the group surface), how a charge outcome is decided, and when a renewal
 * order is reused versus created.
 *
 * @package Newspack\Tests
 */

use Newspack\Group_Subscription_Settings;

/**
 * POST /wizard/newspack-subscribers/subscriptions/<id>/reactivate
 * POST /wizard/newspack-subscribers/subscriptions/<id>/payment-link
 *
 * @group WooCommerce_Subscriptions_Integration
 * @group subscribers-wizard
 */
class Test_Subscribers_Wizard_Reactivate_Endpoints extends WP_UnitTestCase {

	const ROUTE = '/newspack/v1/wizard/newspack-subscribers/subscriptions/';

	/**
	 * Track created user IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Include the WC mocks before the class boots.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';
		// The wizard rides the Access Control feature flag; enable it so its routes register.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Reset the mock databases and register REST routes.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		$this->user_ids         = [];
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down: reset databases and delete users.
	 */
	public function tear_down() {
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database = [];
		$products_database      = [];
		$orders_database        = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		Group_Subscription_Settings::clear_group_subscription_ids_cache();
		parent::tear_down();
	}

	/**
	 * Create a reader user and track it for cleanup.
	 *
	 * @return int The new user ID.
	 */
	private function create_reader(): int {
		$suffix  = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			[
				'user_login'   => 'reader-' . $suffix,
				'user_pass'    => wp_generate_password(),
				'user_email'   => 'reader-' . $suffix . '@test.com',
				'display_name' => 'Reader ' . $suffix,
				'role'         => 'subscriber',
			]
		);
		update_user_meta( $user_id, '_newspack_reader', true );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Create an admin and make it the current user.
	 *
	 * @return int The admin user ID.
	 */
	private function login_admin(): int {
		$admin_id         = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->user_ids[] = $admin_id;
		wp_set_current_user( $admin_id );
		return $admin_id;
	}

	/**
	 * Create an individual subscription owned by $owner_id.
	 *
	 * Defaults to a manual on-hold subscription — the state the endpoints act
	 * on. Pass overrides to stage other statuses or a tokenized payment method.
	 *
	 * @param int   $owner_id  The owner user ID.
	 * @param array $overrides Fixture overrides.
	 *
	 * @return WC_Subscription
	 */
	private function create_subscription( int $owner_id, array $overrides = [] ): WC_Subscription {
		return wcs_create_subscription(
			array_merge(
				[
					'customer_id'      => $owner_id,
					'status'           => 'on-hold',
					'is_manual'        => true,
					'total'            => 12.5,
					'currency'         => 'USD',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'dates'            => [
						'start'        => '2024-02-01 09:00:00',
						'next_payment' => '2026-08-01 09:00:00',
					],
				],
				$overrides
			)
		);
	}

	/**
	 * Create a subscription that a saved payment method can charge.
	 *
	 * Not manual, has a gateway, and the gateway does not schedule its own
	 * payments (i.e. WCS triggers the renewal charge) — the shape a Stripe
	 * tokenized subscription has.
	 *
	 * @param int   $owner_id  The owner user ID.
	 * @param array $overrides Fixture overrides.
	 *
	 * @return WC_Subscription
	 */
	private function create_chargeable_subscription( int $owner_id, array $overrides = [] ): WC_Subscription {
		return $this->create_subscription(
			$owner_id,
			array_merge(
				[
					'is_manual'               => false,
					'payment_method'          => 'stripe',
					'payment_method_supports' => [ 'subscriptions' ],
				],
				$overrides
			)
		);
	}

	/**
	 * Stage a renewal order for a subscription.
	 *
	 * @param WC_Subscription $subscription The subscription.
	 * @param string          $status       Order status; 'pending' needs payment.
	 * @param array           $extra        Extra order data, e.g. a transaction_id.
	 *
	 * @return WC_Order
	 */
	private function stage_renewal_order( WC_Subscription $subscription, string $status = 'pending', array $extra = [] ): WC_Order {
		$order = new WC_Order(
			array_merge(
				[
					'status'      => $status,
					'customer_id' => $subscription->get_customer_id(),
					'total'       => $subscription->get_total(),
					'currency'    => $subscription->get_currency(),
				],
				$extra
			)
		);

		$subscription->data['related_orders']['renewal'][ $order->get_id() ] = $order;
		return $order;
	}

	/**
	 * Dispatch a POST to one of the on-hold recovery endpoints.
	 *
	 * @param int    $subscription_id The subscription ID.
	 * @param string $action          'reactivate' or 'payment-link'.
	 * @param array  $params          Body params.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch( int $subscription_id, string $action, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . $subscription_id . '/' . $action );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The write routes are admin-only: a reader hitting them is refused, before
	 * any state is read.
	 */
	public function test_endpoints_require_manage_options() {
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );
		wp_set_current_user( $reader_id );

		$reactivate = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'free' ] );
		$this->assertSame( 403, $reactivate->get_status() );
		$this->assertSame( 'newspack_rest_forbidden', $reactivate->as_error()->get_error_code() );

		$link = $this->dispatch( $subscription->get_id(), 'payment-link' );
		$this->assertSame( 403, $link->get_status() );
		$this->assertSame( 'newspack_rest_forbidden', $link->as_error()->get_error_code() );

		// The refused calls changed nothing.
		$this->assertSame( 'on-hold', $subscription->get_status() );
	}

	/**
	 * Free reactivation flips the subscription to active — WCS itself
	 * recalculates the next payment date on that transition — and leaves an
	 * audit note naming the acting admin.
	 */
	public function test_free_reactivation_activates_and_leaves_audit_note() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );

		$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'free' ] );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'active', $data['status'] );
		$this->assertSame( 'active', $subscription->get_status() );

		// The audit trail records who did it and that no payment was taken.
		$notes = implode( ' | ', $subscription->data['order_notes'] ?? [] );
		$this->assertStringContainsString( wp_get_current_user()->user_login, $notes );
	}

	/**
	 * When WCS itself refuses the transition (e.g. the subscription's product no
	 * longer exists), the refusal surfaces as a 409 — not a server error — and
	 * no audit note claims a reactivation that never happened.
	 */
	public function test_free_reactivation_refusal_is_conflict_without_audit_note() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id, [ 'can_update_to' => [ 'cancelled' ] ] );

		$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'free' ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_subscribers_reactivate_failed', $response->as_error()->get_error_code() );
		$this->assertSame( 'on-hold', $subscription->get_status() );
		$notes = implode( ' | ', $subscription->data['order_notes'] ?? [] );
		$this->assertStringNotContainsString( 'Reactivated without payment', $notes );
	}

	/**
	 * `mode` is enum-validated: anything outside free|charge is rejected by the
	 * REST layer before the callback runs.
	 */
	public function test_reactivate_rejects_invalid_mode() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );

		$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'comp' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'on-hold', $subscription->get_status() );
	}

	/**
	 * Reactivation is an on-hold recovery action: any other status is refused
	 * with a 409 and the status is left alone.
	 */
	public function test_reactivate_refuses_subscription_not_on_hold() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id, [ 'status' => 'active' ] );

		$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'free' ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_subscribers_not_on_hold', $response->as_error()->get_error_code() );
		$this->assertSame( 'active', $subscription->get_status() );
	}

	/**
	 * A group subscription is not an individual subscription: its money actions
	 * live with the group surface, so this route reports it as not found.
	 */
	public function test_reactivate_refuses_group_subscription() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		Group_Subscription_Settings::clear_group_subscription_ids_cache();

		$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'free' ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'on-hold', $subscription->get_status() );
	}

	/**
	 * A missing subscription is a 404, not an exposure of whether the ID ever
	 * existed.
	 */
	public function test_reactivate_missing_subscription_is_404() {
		$this->login_admin();

		$response = $this->dispatch( 99999, 'reactivate', [ 'mode' => 'free' ] );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Charge mode needs a payment method that can actually be charged: a manual
	 * subscription has none, so the request is refused before any order is
	 * created.
	 */
	public function test_charge_refuses_manual_subscription() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );

		$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_subscribers_cannot_charge', $response->as_error()->get_error_code() );
		$this->assertSame( 'on-hold', $subscription->get_status() );
		$this->assertCount( 0, $orders_database );
	}

	/**
	 * A successful charge: the endpoint guarantees an unpaid renewal order
	 * exists, dispatches the gateway leg of WCS's renewal chain, and reports
	 * success from the subscription's resulting status — the gateway
	 * reactivates the subscription by completing payment on the renewal order,
	 * exactly as it does for a scheduled renewal.
	 */
	public function test_charge_success_reports_active() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_chargeable_subscription( $reader_id );

		// Simulate the gateway leg of the renewal chain: payment succeeds and
		// payment_complete() reactivates the subscription.
		$charged  = [];
		$listener = function ( $subscription_id ) use ( &$charged ) {
			$charged[]    = $subscription_id;
			$subscription = wcs_get_subscription( $subscription_id );
			$subscription->update_status( 'active' );
		};
		add_action( 'woocommerce_scheduled_subscription_payment', $listener );

		try {
			$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );
		} finally {
			// A listener left registered would let a later test's "no gateway"
			// path silently exercise the success path.
			remove_action( 'woocommerce_scheduled_subscription_payment', $listener );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()['status'] );
		$this->assertSame( [ $subscription->get_id() ], $charged );
		// With no unpaid renewal order staged, the endpoint created one for the
		// gateway to charge.
		$this->assertCount( 1, $orders_database );
	}

	/**
	 * A charge whose money is in flight: the gateway settled the renewal order
	 * (it no longer needs payment) but did not reactivate the subscription —
	 * asynchronous capture, manual review, or a webhook-confirmed gateway. That
	 * is a pending outcome, not a failure: reporting it as a decline is what
	 * gets a live charge retried and a subscriber double-billed.
	 */
	public function test_charge_in_flight_reports_pending_not_failure() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_chargeable_subscription( $reader_id );

		$listener = function ( $subscription_id ) {
			// The gateway records the payment on the order but leaves the
			// subscription untouched until its webhook confirms.
			$order = wcs_get_subscription( $subscription_id )->get_last_order( 'all', [ 'renewal' ] );
			$order->data['status'] = 'processing';
		};
		add_action( 'woocommerce_scheduled_subscription_payment', $listener );

		try {
			$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );
		} finally {
			remove_action( 'woocommerce_scheduled_subscription_payment', $listener );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['pendingConfirmation'] );
		$this->assertSame( 'on-hold', $subscription->get_status() );
	}

	/**
	 * The charge is guarded by a per-subscription lock: while one attempt is
	 * processing, a second request is refused rather than firing a second
	 * charge — neither WCS nor the gateways deduplicate renewal attempts.
	 */
	public function test_concurrent_charge_is_refused_by_lock() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_chargeable_subscription( $reader_id );

		set_transient( 'newspack_subscribers_charge_' . $subscription->get_id(), 1, MINUTE_IN_SECONDS );

		try {
			$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );
		} finally {
			delete_transient( 'newspack_subscribers_charge_' . $subscription->get_id() );
		}

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_subscribers_charge_in_progress', $response->as_error()->get_error_code() );
		// The refused attempt created nothing and charged nothing.
		$this->assertCount( 0, $orders_database );
		$this->assertSame( 'on-hold', $subscription->get_status() );
	}

	/**
	 * A renewal order parked on-hold with a transaction recorded marks a
	 * gateway payment in flight (asynchronous capture, manual review) — both
	 * recovery writes refuse rather than raising a second order under money
	 * that may already have moved.
	 */
	public function test_in_flight_payment_blocks_new_charge_and_link() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_chargeable_subscription( $reader_id );
		$this->stage_renewal_order( $subscription, 'on-hold', [ 'transaction_id' => 'txn_in_flight' ] );
		$staged_count = count( $orders_database );

		$charge = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );
		$this->assertSame( 409, $charge->get_status() );
		$this->assertSame( 'newspack_subscribers_payment_unresolved', $charge->as_error()->get_error_code() );

		$link = $this->dispatch( $subscription->get_id(), 'payment-link' );
		$this->assertSame( 409, $link->get_status() );

		// Neither refused write created an order.
		$this->assertCount( $staged_count, $orders_database );
	}

	/**
	 * An on-hold renewal order with NO transaction is an offline payment
	 * (BACS, cheque) awaiting manual confirmation, not money in flight — the
	 * payment link is the admin's remedy there and must not dead-end.
	 */
	public function test_offline_on_hold_order_does_not_block_payment_link() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );
		$this->stage_renewal_order( $subscription, 'on-hold' );
		$staged_count = count( $orders_database );

		$response = $this->dispatch( $subscription->get_id(), 'payment-link' );

		$this->assertSame( 200, $response->get_status() );
		// The offline order is settled history for payment purposes: a fresh
		// payable order was created for the link.
		$this->assertCount( $staged_count + 1, $orders_database );
	}

	/**
	 * The charge lock covers the payment-link route too: emailing a pay link
	 * for the order a gateway is charging right now invites a second real
	 * payment.
	 */
	public function test_payment_link_refused_while_charge_in_progress() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );

		set_transient( 'newspack_subscribers_charge_' . $subscription->get_id(), 1, MINUTE_IN_SECONDS );

		try {
			$response = $this->dispatch( $subscription->get_id(), 'payment-link' );
		} finally {
			delete_transient( 'newspack_subscribers_charge_' . $subscription->get_id() );
		}

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_subscribers_charge_in_progress', $response->as_error()->get_error_code() );
	}

	/**
	 * A gateway that schedules its own payments (PayPal-Standard shape) must
	 * never be charged from here — WCS is not the one triggering its renewals,
	 * so an admin-fired charge would double-bill. This is the clause of the
	 * chargeability rule that exists purely to protect readers' money.
	 */
	public function test_charge_refuses_gateway_scheduled_gateway() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_chargeable_subscription(
			$reader_id,
			[ 'payment_method_supports' => [ 'subscriptions', 'gateway_scheduled_payments' ] ]
		);

		$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_subscribers_cannot_charge', $response->as_error()->get_error_code() );
	}

	/**
	 * A failed charge: the gateway does not reactivate the subscription, so the
	 * endpoint reports payment failure and the subscription stays on hold. The
	 * renewal order remains as the record of the attempt, matching how WCS
	 * leaves a failed scheduled renewal.
	 */
	public function test_charge_failure_reports_payment_failed() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_chargeable_subscription( $reader_id );

		// No listener on the renewal hook: the "gateway" never completes payment.
		$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );

		$this->assertSame( 402, $response->get_status() );
		$this->assertSame( 'newspack_subscribers_charge_failed', $response->as_error()->get_error_code() );
		$this->assertSame( 'on-hold', $subscription->get_status() );
	}

	/**
	 * The charge reuses the newest unpaid renewal order rather than stacking a
	 * second one — the common on-hold case is a failed renewal whose order is
	 * still awaiting payment.
	 */
	public function test_charge_reuses_existing_unpaid_renewal_order() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_chargeable_subscription( $reader_id );
		$this->stage_renewal_order( $subscription );
		$staged_count = count( $orders_database );

		$listener = function ( $subscription_id ) {
			wcs_get_subscription( $subscription_id )->update_status( 'active' );
		};
		add_action( 'woocommerce_scheduled_subscription_payment', $listener );

		try {
			$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );
		} finally {
			remove_action( 'woocommerce_scheduled_subscription_payment', $listener );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( $staged_count, $orders_database );
	}

	/**
	 * Order selection matches the code that charges: WCS's gateway leg acts on
	 * the LATEST renewal order regardless of paid state, so when the latest is
	 * settled — even with an older unpaid one behind it — a fresh order is
	 * created rather than reusing the older one. Reusing it would name and
	 * email an order the gateway will never charge.
	 */
	public function test_charge_ignores_older_unpaid_order_behind_settled_latest() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_chargeable_subscription( $reader_id );
		$older_unpaid = $this->stage_renewal_order( $subscription );
		$this->stage_renewal_order( $subscription, 'completed' );
		$staged_count = count( $orders_database );

		$charged_order_ids = [];
		$listener          = function ( $subscription_id ) use ( &$charged_order_ids ) {
			$subscription        = wcs_get_subscription( $subscription_id );
			$order               = $subscription->get_last_order( 'all', [ 'renewal' ] );
			$charged_order_ids[] = $order->get_id();
			$order->data['status'] = 'completed';
			$subscription->update_status( 'active' );
		};
		add_action( 'woocommerce_scheduled_subscription_payment', $listener );

		try {
			$response = $this->dispatch( $subscription->get_id(), 'reactivate', [ 'mode' => 'charge' ] );
		} finally {
			remove_action( 'woocommerce_scheduled_subscription_payment', $listener );
		}

		$this->assertSame( 200, $response->get_status() );
		// A fresh renewal order was created; the stale unpaid one was not the target.
		$this->assertCount( $staged_count + 1, $orders_database );
		$this->assertNotContains( $older_unpaid->get_id(), $charged_order_ids );
	}

	/**
	 * The payment link is the unpaid renewal order's checkout payment URL. An
	 * existing unpaid renewal order is reused; the customer pays the order that
	 * already records the failed renewal.
	 */
	public function test_payment_link_returns_existing_order_pay_url() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );
		$order        = $this->stage_renewal_order( $subscription );
		$staged_count = count( $orders_database );

		$response = $this->dispatch( $subscription->get_id(), 'payment-link' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $order->get_checkout_payment_url(), $data['paymentUrl'] );
		$this->assertCount( $staged_count, $orders_database );
		// The mock environment has no WC() mailer; the response says so rather
		// than claiming an email went out.
		$this->assertFalse( $data['emailSent'] );
	}

	/**
	 * With no unpaid renewal order on file — e.g. a subscription suspended by
	 * hand — the endpoint creates one so there is something to pay.
	 */
	public function test_payment_link_creates_renewal_order_when_none_unpaid() {
		global $orders_database;
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );
		// A paid renewal order on file must not be reused as a payment target.
		$this->stage_renewal_order( $subscription, 'completed' );
		$staged_count = count( $orders_database );

		$response = $this->dispatch( $subscription->get_id(), 'payment-link' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( $staged_count + 1, $orders_database );
		$this->assertNotEmpty( $response->get_data()['paymentUrl'] );
	}

	/**
	 * The payment link is for a subscription the customer still can pay: any
	 * status other than on-hold is refused, same as reactivation.
	 */
	public function test_payment_link_refuses_subscription_not_on_hold() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id, [ 'status' => 'cancelled' ] );

		$response = $this->dispatch( $subscription->get_id(), 'payment-link' );

		$this->assertSame( 409, $response->get_status() );
	}

	/**
	 * The group rule holds on the payment-link route too, not only reactivate:
	 * group money actions belong to the group surface.
	 */
	public function test_payment_link_refuses_group_subscription() {
		$this->login_admin();
		$reader_id    = $this->create_reader();
		$subscription = $this->create_subscription( $reader_id );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		Group_Subscription_Settings::clear_group_subscription_ids_cache();

		$response = $this->dispatch( $subscription->get_id(), 'payment-link' );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * The person profile's detailed payload carries the two capability flags the
	 * reactivate flow gates on: `canCharge` (is there anything to charge) and
	 * `canReactivate` (does the write endpoint's raw-status rule apply — the
	 * mapped status can't be trusted for this, since unknown WCS statuses map
	 * into the "on-hold" bucket the endpoint refuses).
	 */
	public function test_detail_payload_carries_capability_flags() {
		$this->login_admin();
		$reader_id = $this->create_reader();
		$this->create_subscription( $reader_id );
		$chargeable_reader_id = $this->create_reader();
		$this->create_chargeable_subscription( $chargeable_reader_id );
		$paused_reader_id = $this->create_reader();
		// An unrecognized raw status maps to the "on-hold" bucket in the read
		// payload, but must NOT be offered reactivation.
		$this->create_subscription( $paused_reader_id, [ 'status' => 'custom-paused' ] );

		$manual_request  = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-subscribers/subscribers/' . $reader_id );
		$manual_entry    = rest_get_server()->dispatch( $manual_request )->get_data()['subscriptions'][0];
		$this->assertFalse( $manual_entry['canCharge'] );
		$this->assertTrue( $manual_entry['canReactivate'] );

		$tokenized_request = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-subscribers/subscribers/' . $chargeable_reader_id );
		$tokenized_entry   = rest_get_server()->dispatch( $tokenized_request )->get_data()['subscriptions'][0];
		$this->assertTrue( $tokenized_entry['canCharge'] );
		$this->assertTrue( $tokenized_entry['canReactivate'] );

		$paused_request = new WP_REST_Request( 'GET', '/newspack/v1/wizard/newspack-subscribers/subscribers/' . $paused_reader_id );
		$paused_entry   = rest_get_server()->dispatch( $paused_request )->get_data()['subscriptions'][0];
		$this->assertSame( 'on-hold', $paused_entry['status'] );
		$this->assertFalse( $paused_entry['canReactivate'] );
	}
}
