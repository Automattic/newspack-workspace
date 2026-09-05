<?php
/**
 * Tests for the Subscribers wizard payment actions (NPPD-1753).
 *
 * These are admin-on-behalf money endpoints, so the tests pin the guards more
 * than the happy paths: who may call them, which card a subscription may be
 * re-pointed to, when a refund is refused, and that a gateway decline surfaces
 * verbatim instead of half-applying a refund-and-cancel.
 *
 * @package Newspack\Tests
 */

use Newspack\Subscribers_Payments;

/**
 * The payment-action routes under /wizard/newspack-subscribers/.
 *
 * @group WooCommerce_Subscriptions_Integration
 * @group subscribers-wizard
 */
class Test_Subscribers_Wizard_Payments_Endpoints extends WP_UnitTestCase {

	const BASE = '/newspack/v1/wizard/newspack-subscribers';

	/**
	 * Track created user IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Include the WC + token mocks before the class boots.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 3 ) . '/mocks/payment-tokens-mocks.php';
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Reset the mock stores, mirror the payment-meta contract of a tokenized
	 * gateway, and register REST routes.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database, $orders_database,
			$wc_mock_refunds, $wc_mock_refund_result, $wc_mock_gateways_by_order;
		$subscriptions_database    = [];
		$products_database         = [];
		$orders_database           = [];
		\WC_Payment_Tokens::$tokens = [];
		$wc_mock_refunds           = [];
		$wc_mock_refund_result     = null;
		$wc_mock_gateways_by_order = [];
		$this->user_ids            = [];
		// The payment-meta contract a tokenized gateway declares to WCS: one meta
		// slot, `_mock_token`, holding the charged token's value.
		add_filter( 'woocommerce_subscription_payment_meta', [ $this, 'mock_gateway_payment_meta' ], 10, 2 );
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down: drop the filter, reset stores, delete users.
	 */
	public function tear_down() {
		remove_filter( 'woocommerce_subscription_payment_meta', [ $this, 'mock_gateway_payment_meta' ], 10 );
		global $subscriptions_database, $products_database, $orders_database;
		$subscriptions_database     = [];
		$products_database          = [];
		$orders_database            = [];
		\WC_Payment_Tokens::$tokens = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		parent::tear_down();
	}

	/**
	 * The mock gateway's WCS payment-meta table.
	 *
	 * @param array           $meta         Payment meta, keyed by gateway.
	 * @param WC_Subscription $subscription The subscription.
	 * @return array
	 */
	public function mock_gateway_payment_meta( $meta, $subscription ) {
		$meta['mock_gateway'] = [
			'post_meta' => [
				'_mock_token' => [
					'value' => $subscription->get_meta( '_mock_token' ),
					'label' => 'Mock token',
				],
			],
		];
		return $meta;
	}

	/**
	 * Create a user with a role, tracked for cleanup, and return its ID.
	 *
	 * @param string $role The role.
	 * @return int
	 */
	private function create_user( $role = 'subscriber' ) {
		$user_id          = $this->factory()->user->create( [ 'role' => $role ] );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	/**
	 * Log in as an administrator.
	 *
	 * @return int The admin user ID.
	 */
	private function login_as_admin() {
		$admin_id = $this->create_user( 'administrator' );
		wp_set_current_user( $admin_id );
		return $admin_id;
	}

	/**
	 * Save a credit-card token for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Token value.
	 * @param array  $args    Overrides: brand, last4, month, year, default, gateway.
	 * @return WC_Payment_Token_CC
	 */
	private function save_card( $user_id, $token, $args = [] ) {
		$card = new WC_Payment_Token_CC();
		$card->set_token( $token );
		$card->set_gateway_id( $args['gateway'] ?? 'mock_gateway' );
		$card->set_card_type( $args['brand'] ?? 'visa' );
		$card->set_last4( $args['last4'] ?? '4242' );
		$card->set_expiry_month( $args['month'] ?? '12' );
		$card->set_expiry_year( $args['year'] ?? (string) ( (int) gmdate( 'Y' ) + 3 ) );
		$card->set_user_id( $user_id );
		$card->set_default( ! empty( $args['default'] ) );
		$card->save();
		return $card;
	}

	/**
	 * Create a tokenized subscription on the mock gateway.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Extra subscription data.
	 * @return WC_Subscription
	 */
	private function create_tokenized_subscription( $user_id, $data = [] ) {
		return wcs_create_subscription(
			array_merge(
				[
					'customer_id'      => $user_id,
					'status'           => 'active',
					'total'            => 100,
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'payment_method'   => 'mock_gateway',
					'meta'             => [ '_mock_token' => 'tok_current' ],
				],
				$data
			)
		);
	}

	/**
	 * Dispatch a POST to a payments route.
	 *
	 * The real client always sends the profile's user ID as `customer_id` (the
	 * routes require it and cross-check it against the target's owner), so the
	 * helper defaults it from the target; pass an explicit `customer_id` to
	 * exercise the mismatch guard itself.
	 *
	 * @param string $route Route below BASE, e.g. '/subscriptions/1/payment-method'.
	 * @param array  $body  JSON body params.
	 * @return WP_REST_Response
	 */
	private function post( $route, $body = [] ) {
		if ( ! array_key_exists( 'customer_id', $body ) ) {
			if ( preg_match( '#/subscriptions/(\d+)/#', $route, $matches ) ) {
				$subscription = wcs_get_subscription( (int) $matches[1] );
				if ( $subscription ) {
					$body['customer_id'] = (int) $subscription->get_customer_id();
				}
			} elseif ( preg_match( '#/payment-methods/(\d+)/#', $route, $matches ) ) {
				$token = WC_Payment_Tokens::get( (int) $matches[1] );
				if ( $token ) {
					$body['customer_id'] = (int) $token->get_user_id();
				}
			}
		}
		$request = new WP_REST_Request( 'POST', self::BASE . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Every write cross-checks the claimed subscriber against the target's real
	 * owner: a stale tab or copied ID gets a 409 instead of moving another
	 * reader's money.
	 */
	public function test_writes_refuse_a_customer_mismatch() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$other_id  = $this->create_user();
		$this->save_card( $reader_id, 'tok_current', [ 'default' => true ] );
		$mastercard   = $this->save_card( $reader_id, 'tok_next', [ 'brand' => 'mastercard' ] );
		$subscription = $this->create_tokenized_subscription( $reader_id );

		$response = $this->post(
			'/subscriptions/' . $subscription->get_id() . '/payment-method',
			[
				'token_id'    => $mastercard->get_id(),
				'customer_id' => $other_id,
			]
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_payments_customer_mismatch', $response->get_data()['code'] );
		$this->assertSame( 'tok_current', $subscription->get_meta( '_mock_token' ), 'A mismatched claim must not touch the token.' );

		$default_response = $this->post( '/payment-methods/' . $mastercard->get_id() . '/default', [ 'customer_id' => $other_id ] );
		$this->assertSame( 'newspack_payments_customer_mismatch', $default_response->get_data()['code'] );
		$this->assertFalse( $mastercard->is_default() );
	}

	/**
	 * Money actions carry the same manage_options gate as the wizard reads: a
	 * logged-in reader cannot re-point anyone's payment method.
	 */
	public function test_endpoints_require_manage_options() {
		$reader_id = $this->create_user();
		wp_set_current_user( $reader_id );
		$card         = $this->save_card( $reader_id, 'tok_new' );
		$subscription = $this->create_tokenized_subscription( $reader_id );

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/payment-method', [ 'token_id' => $card->get_id() ] );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'tok_current', $subscription->get_meta( '_mock_token' ), 'A forbidden request must not touch the token.' );

		// Every money route shares the gate — pin them all, not just one.
		$this->assertSame( 403, $this->post( '/subscriptions/' . $subscription->get_id() . '/refund', [ 'cancel' => true ] )->get_status() );
		$this->assertSame( 403, $this->post( '/subscriptions/' . $subscription->get_id() . '/plan', [ 'product_id' => 1 ] )->get_status() );
		$this->assertSame( 403, $this->post( '/payment-methods/' . $card->get_id() . '/default' )->get_status() );
		$options_request = new WP_REST_Request( 'GET', self::BASE . '/subscriptions/' . $subscription->get_id() . '/plan-options' );
		$this->assertSame( 403, rest_get_server()->dispatch( $options_request )->get_status() );
	}

	/**
	 * The lesser change-payment refusals: a no-op swap to the card already on
	 * file, an unresolvable current token (the guard that keeps the swap from
	 * writing into the wrong meta slot — the branch a real gateway's meta shape
	 * is most likely to trip), and a refund request with nothing to do.
	 */
	public function test_change_payment_method_same_token_unresolved_and_no_action_refusals() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$visa      = $this->save_card( $reader_id, 'tok_current', [ 'default' => true ] );
		$this->save_card( $reader_id, 'tok_next', [ 'brand' => 'mastercard' ] );
		$subscription = $this->create_tokenized_subscription( $reader_id );

		$same = $this->post( '/subscriptions/' . $subscription->get_id() . '/payment-method', [ 'token_id' => $visa->get_id() ] );
		$this->assertSame( 'newspack_payments_same_token', $same->get_data()['code'] );

		// A subscription whose meta value matches no saved token has no slot to
		// swap through; the endpoint must refuse, not guess.
		$orphaned = $this->create_tokenized_subscription( $reader_id, [ 'meta' => [ '_mock_token' => 'tok_from_before_migration' ] ] );
		$mc       = \WC_Payment_Tokens::get( 2 );
		$response = $this->post( '/subscriptions/' . $orphaned->get_id() . '/payment-method', [ 'token_id' => $mc->get_id() ] );
		$this->assertSame( 'newspack_payments_current_unresolved', $response->get_data()['code'] );
		$this->assertSame( 'tok_from_before_migration', $orphaned->get_meta( '_mock_token' ) );

		$noop = $this->post( '/subscriptions/' . $subscription->get_id() . '/refund', [] );
		$this->assertSame( 'newspack_payments_no_action', $noop->get_data()['code'] );
	}

	/**
	 * The refund promise is transactional: when the balance drifted after the
	 * profile was loaded (renewal, manual refund, another admin), the endpoint
	 * refuses with a 409 instead of silently moving a different amount than the
	 * one the admin confirmed.
	 */
	public function test_refund_refuses_when_expected_amount_drifted() {
		global $wc_mock_refunds, $wc_mock_gateways_by_order;
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$order     = new WC_Order(
			[
				'customer_id'    => $reader_id,
				'status'         => 'completed',
				'total'          => 100,
				'total_refunded' => 40,
			]
		);
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[ 'related_orders' => [ 'any' => [ $order ] ] ]
		);
		$wc_mock_gateways_by_order[ $order->get_id() ] = new Mock_Refundable_Gateway();

		$response = $this->post(
			'/subscriptions/' . $subscription->get_id() . '/refund',
			[
				'refund'          => true,
				'expected_amount' => 100,
			]
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_payments_amount_changed', $response->get_data()['code'] );
		$this->assertEmpty( $wc_mock_refunds, 'No refund is created on a drifted amount.' );

		$matching = $this->post(
			'/subscriptions/' . $subscription->get_id() . '/refund',
			[
				'refund'          => true,
				'expected_amount' => 60,
			]
		);
		$this->assertSame( 200, $matching->get_status() );
		$this->assertSame( 60.0, (float) $matching->get_data()['refunded'] );
	}

	/**
	 * A full refund can cancel the subscription through WCS's own hooks before
	 * the endpoint's explicit cancel runs; both requested effects happened, so
	 * the response is success — not a failed transition.
	 */
	public function test_cancel_treats_already_cancelled_as_success() {
		$this->login_as_admin();
		$reader_id    = $this->create_user();
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[
				'status'        => 'cancelled',
				'can_update_to' => [],
			]
		);

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/refund', [ 'cancel' => true ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['cancelled'] );
	}

	/**
	 * The detailed subscriber payload carries the saved cards — expiry state
	 * resolved server-side — and each subscription resolves which saved card it
	 * charges, so the client never has to guess from meta.
	 */
	public function test_subscriber_payload_resolves_cards_and_subscription_token() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$visa      = $this->save_card( $reader_id, 'tok_current', [ 'default' => true ] );
		$this->save_card(
			$reader_id,
			'tok_expired',
			[
				'brand' => 'amex',
				'last4' => '0005',
				'month' => '01',
				'year'  => '2024',
			] 
		);
		$this->create_tokenized_subscription( $reader_id );

		$request  = new WP_REST_Request( 'GET', self::BASE . '/subscribers/' . $reader_id );
		$data     = rest_get_server()->dispatch( $request )->get_data();
		$by_brand = array_column( $data['paymentMethods'], null, 'brand' );

		$this->assertFalse( $by_brand['visa']['isExpired'] );
		$this->assertTrue( $by_brand['visa']['isDefault'] );
		$this->assertTrue( $by_brand['amex']['isExpired'], 'A card past its expiry month is expired.' );
		$this->assertSame( '01/24', $by_brand['amex']['expiry'] );
		$this->assertSame( $visa->get_id(), $data['subscriptions'][0]['paymentTokenId'] );
		$this->assertSame( 'mock_gateway', $data['subscriptions'][0]['paymentGatewayId'] );
		$this->assertNull( $data['subscriptions'][0]['refundableAmount'], 'No paid order means nothing to promise a refund of.' );
	}

	/**
	 * The refundable amount is the latest paid order's remaining balance — the
	 * number the refund endpoint will actually move — not the subscription's
	 * price. After a plan change or partial refund the two differ, and the
	 * refund modal's promise must match the money.
	 */
	public function test_refundable_amount_reports_order_remainder_not_plan_price() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$this->save_card( $reader_id, 'tok_current', [ 'default' => true ] );
		$order = new WC_Order(
			[
				'customer_id'    => $reader_id,
				'status'         => 'completed',
				'total'          => 100,
				'total_refunded' => 40,
			]
		);
		$this->create_tokenized_subscription(
			$reader_id,
			[
				'total'          => 150,
				'related_orders' => [ 'any' => [ $order ] ],
			]
		);

		$request = new WP_REST_Request( 'GET', self::BASE . '/subscribers/' . $reader_id );
		$data    = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 60.0, (float) $data['subscriptions'][0]['refundableAmount'] );
	}

	/**
	 * The core deliverable: re-point a subscription at another card already on
	 * file. The token meta moves to the new card's value and the change is
	 * recorded on the subscription as an audit trail.
	 */
	public function test_change_payment_method_repoints_to_selected_card() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$this->save_card( $reader_id, 'tok_current', [ 'default' => true ] );
		$mastercard   = $this->save_card(
			$reader_id,
			'tok_next',
			[
				'brand' => 'mastercard',
				'last4' => '5454',
			] 
		);
		$subscription = $this->create_tokenized_subscription( $reader_id );

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/payment-method', [ 'token_id' => $mastercard->get_id() ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'tok_next', $subscription->get_meta( '_mock_token' ), 'The subscription now charges the selected card.' );
		$this->assertNotEmpty( $subscription->data['order_notes'], 'The change leaves an order note.' );
		$this->assertStringContainsString( '5454', implode( ' ', $subscription->data['order_notes'] ) );
	}

	/**
	 * An expired card cannot be charged, so it can never become a subscription's
	 * payment method — enforced server-side, not just hidden in the UI.
	 */
	public function test_change_payment_method_rejects_expired_card() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$this->save_card( $reader_id, 'tok_current', [ 'default' => true ] );
		$expired      = $this->save_card(
			$reader_id,
			'tok_expired',
			[
				'month' => '01',
				'year'  => '2024',
			] 
		);
		$subscription = $this->create_tokenized_subscription( $reader_id );

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/payment-method', [ 'token_id' => $expired->get_id() ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'newspack_payments_token_expired', $response->get_data()['code'] );
		$this->assertSame( 'tok_current', $subscription->get_meta( '_mock_token' ) );
	}

	/**
	 * A token belonging to a different customer can never be attached — even by
	 * an admin — because renewals would charge the wrong person's card.
	 */
	public function test_change_payment_method_rejects_another_customers_card() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$other_id  = $this->create_user();
		$this->save_card( $reader_id, 'tok_current', [ 'default' => true ] );
		$foreign_card = $this->save_card( $other_id, 'tok_foreign' );
		$subscription = $this->create_tokenized_subscription( $reader_id );

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/payment-method', [ 'token_id' => $foreign_card->get_id() ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'newspack_payments_token_mismatch', $response->get_data()['code'] );
		$this->assertSame( 'tok_current', $subscription->get_meta( '_mock_token' ) );
	}

	/**
	 * A card saved on a different gateway has no meta slot on this subscription;
	 * re-pointing across gateways is not a token swap and is refused.
	 */
	public function test_change_payment_method_rejects_gateway_mismatch() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$this->save_card( $reader_id, 'tok_current', [ 'default' => true ] );
		$stripe_card  = $this->save_card( $reader_id, 'tok_stripe', [ 'gateway' => 'stripe' ] );
		$subscription = $this->create_tokenized_subscription( $reader_id );

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/payment-method', [ 'token_id' => $stripe_card->get_id() ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'newspack_payments_gateway_mismatch', $response->get_data()['code'] );
	}

	/**
	 * Refund-and-cancel: the latest paid related order is refunded through the
	 * gateway (refund_payment true) and the subscription is cancelled after.
	 */
	public function test_refund_and_cancel_refunds_latest_paid_order_then_cancels() {
		global $wc_mock_refunds, $wc_mock_gateways_by_order;
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$order     = new WC_Order(
			[
				'customer_id' => $reader_id,
				'status'      => 'completed',
				'total'       => 100,
			]
		);
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[ 'related_orders' => [ 'any' => [ $order ] ] ]
		);
		$wc_mock_gateways_by_order[ $order->get_id() ] = new Mock_Refundable_Gateway();

		$response = $this->post(
			'/subscriptions/' . $subscription->get_id() . '/refund',
			[
				'refund' => true,
				'cancel' => true,
			] 
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $wc_mock_refunds );
		$this->assertSame( $order->get_id(), $wc_mock_refunds[0]['order_id'] );
		$this->assertSame( 100.0, (float) $wc_mock_refunds[0]['amount'] );
		$this->assertTrue( $wc_mock_refunds[0]['refund_payment'], 'A refund-capable gateway refunds at the gateway.' );
		$this->assertSame( 100.0, (float) $data['refunded'] );
		$this->assertTrue( $data['gatewayRefund'] );
		$this->assertTrue( $data['cancelled'] );
		$this->assertSame( 'cancelled', $subscription->get_status() );
	}

	/**
	 * A gateway decline surfaces its own message and stops the flow: the
	 * subscription is NOT cancelled when the refund half of refund-and-cancel
	 * failed, so the admin decides with full information instead of discovering
	 * a cancelled-but-unrefunded reader later.
	 */
	public function test_refund_decline_surfaces_error_and_skips_cancel() {
		global $wc_mock_refund_result, $wc_mock_gateways_by_order;
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$order     = new WC_Order(
			[
				'customer_id' => $reader_id,
				'status'      => 'completed',
				'total'       => 100,
			]
		);
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[ 'related_orders' => [ 'any' => [ $order ] ] ]
		);
		$wc_mock_gateways_by_order[ $order->get_id() ] = new Mock_Refundable_Gateway();
		$wc_mock_refund_result                         = new WP_Error( 'mock_declined', 'The gateway declined the refund.' );

		$response = $this->post(
			'/subscriptions/' . $subscription->get_id() . '/refund',
			[
				'refund' => true,
				'cancel' => true,
			] 
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'The gateway declined the refund.', $response->get_data()['message'], 'The gateway message travels verbatim.' );
		$this->assertSame( 'active', $subscription->get_status(), 'No cancel after a failed refund.' );
	}

	/**
	 * With no paid order there is nothing to refund; the request is refused
	 * rather than silently degrading to a cancel.
	 */
	public function test_refund_without_paid_order_is_refused() {
		$this->login_as_admin();
		$reader_id    = $this->create_user();
		$subscription = $this->create_tokenized_subscription( $reader_id );

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/refund', [ 'refund' => true ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'newspack_payments_no_refundable_order', $response->get_data()['code'] );
	}

	/**
	 * An order paid by a gateway that cannot refund (or manually) still gets a
	 * bookkeeping refund record — with refund_payment false, and the response
	 * says so, so the UI can tell the admin the money moves outside WooCommerce.
	 */
	public function test_refund_without_gateway_records_manual_refund() {
		global $wc_mock_refunds;
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$order     = new WC_Order(
			[
				'customer_id' => $reader_id,
				'status'      => 'completed',
				'total'       => 40,
			]
		);
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[ 'related_orders' => [ 'any' => [ $order ] ] ]
		);

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/refund', [ 'refund' => true ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $wc_mock_refunds[0]['refund_payment'] );
		$this->assertFalse( $data['gatewayRefund'] );
		$this->assertSame( 'active', $subscription->get_status(), 'Refund-only keeps the subscription running.' );
	}

	/**
	 * Cancel respects WCS's own transition rules: a subscription WCS says cannot
	 * be cancelled is refused, not forced.
	 */
	public function test_cancel_respects_wcs_transition_guard() {
		$this->login_as_admin();
		$reader_id    = $this->create_user();
		$subscription = $this->create_tokenized_subscription( $reader_id, [ 'can_update_to' => [] ] );

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/refund', [ 'cancel' => true ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'newspack_payments_cannot_cancel', $response->get_data()['code'] );
		$this->assertSame( 'active', $subscription->get_status() );
	}

	/**
	 * Plan change swaps the line item, adopts the new product's billing
	 * schedule, recalculates totals, and leaves an audit note. It does not
	 * touch the subscription's dates: the new price bills at the next renewal.
	 */
	public function test_plan_change_swaps_line_item_and_schedule() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		wc_create_mock_product(
			[
				'id'   => 201,
				'name' => 'Digital Monthly',
				'type' => 'subscription',
				'meta' => [
					'_subscription_price'           => 10,
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => 1,
				],
			]
		);
		wc_create_mock_product(
			[
				'id'   => 202,
				'name' => 'Print + Digital Yearly',
				'type' => 'subscription',
				'meta' => [
					'_subscription_price'           => 150,
					'_subscription_period'          => 'year',
					'_subscription_period_interval' => 1,
				],
			]
		);
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[
				'items' => [
					501 => new WC_Order_Item_Product(
						[
							'id'         => 501,
							'product_id' => 201,
							'name'       => 'Digital Monthly',
							'quantity'   => 1,
						]
					),
				],
				'dates' => [ 'next_payment' => '2027-01-01 00:00:00' ],
			]
		);

		$response = $this->post( '/subscriptions/' . $subscription->get_id() . '/plan', [ 'product_id' => 202 ] );

		$this->assertSame( 200, $response->get_status() );
		$items = $subscription->get_items();
		$this->assertCount( 1, $items );
		$this->assertSame( 202, reset( $items )['product_id'] );
		$this->assertSame( 'year', $subscription->get_billing_period() );
		$this->assertTrue( $subscription->data['calculated_totals'] );
		$this->assertSame( 150.0, (float) $subscription->get_total() );
		// The central promise: the schedule does not move — the new price bills
		// at the renewal that was already booked.
		$this->assertSame( '2027-01-01 00:00:00', $subscription->get_date( 'next_payment' ) );
		$notes = implode( ' ', $subscription->data['order_notes'] );
		$this->assertStringContainsString( 'Digital Monthly', $notes );
		$this->assertStringContainsString( 'Print + Digital Yearly', $notes );
	}

	/**
	 * The two swap-safety guards on the source subscription: a group
	 * subscription must never be dismantled into an individual plan (the route
	 * takes a bare ID, so the UI's filtering is not the guard), and coupons,
	 * fees or shipping would not survive the swap intact, so those
	 * subscriptions are refused rather than half-migrated.
	 */
	public function test_plan_change_refuses_group_and_non_plain_subscriptions() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		wc_create_mock_product(
			[
				'id'   => 202,
				'name' => 'Yearly',
				'type' => 'subscription',
				'meta' => [ '_subscription_price' => 150 ],
			]
		);

		$group_subscription = $this->create_tokenized_subscription(
			$reader_id,
			[ 'meta' => [ '_newspack_group_subscription_enabled' => 'yes' ] ]
		);
		$group_refusal      = $this->post( '/subscriptions/' . $group_subscription->get_id() . '/plan', [ 'product_id' => 202 ] );
		$this->assertSame( 'newspack_payments_group_subscription', $group_refusal->get_data()['code'] );

		$couponed_subscription = $this->create_tokenized_subscription(
			$reader_id,
			[
				'items'        => [
					501 => new WC_Order_Item_Product(
						[
							'id'         => 501,
							'product_id' => 201,
							'quantity'   => 1,
						]
					),
				],
				'coupon_items' => [ 601 => new WC_Order_Item_Product( [ 'id' => 601 ] ) ],
			]
		);
		$coupon_refusal        = $this->post( '/subscriptions/' . $couponed_subscription->get_id() . '/plan', [ 'product_id' => 202 ] );
		$this->assertSame( 'newspack_payments_items_not_swappable', $coupon_refusal->get_data()['code'] );
		$this->assertNotEmpty( $couponed_subscription->get_items(), 'A refused swap leaves the items untouched.' );

		// A quantity above 1 (or a second line) would be silently dropped by the
		// wholesale swap, so that shape is refused too.
		$quantity_subscription = $this->create_tokenized_subscription(
			$reader_id,
			[
				'items' => [
					502 => new WC_Order_Item_Product(
						[
							'id'         => 502,
							'product_id' => 201,
							'quantity'   => 2,
						]
					),
				],
			]
		);
		$quantity_refusal      = $this->post( '/subscriptions/' . $quantity_subscription->get_id() . '/plan', [ 'product_id' => 202 ] );
		$this->assertSame( 'newspack_payments_items_not_swappable', $quantity_refusal->get_data()['code'] );
	}

	/**
	 * The end date is an entitlement recomputed from the new product: moving to
	 * a length-limited plan books its expiry, and moving to an ongoing plan
	 * clears a stale one instead of ending access early.
	 */
	public function test_plan_change_recomputes_end_date_from_new_product() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		wc_create_mock_product(
			[
				'id'   => 203,
				'name' => 'Fixed Term',
				'type' => 'subscription',
				'meta' => [
					'_subscription_price'  => 90,
					'_subscription_period' => 'month',
					'_subscription_length' => 12,
				],
			]
		);
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[
				'items' => [
					501 => new WC_Order_Item_Product(
						[
							'id'         => 501,
							'product_id' => 201,
							'quantity'   => 1,
						]
					),
				],
				'dates' => [
					'start' => '2026-01-01 00:00:00',
					'end'   => '2026-06-01 00:00:00',
				],
			]
		);

		$this->post( '/subscriptions/' . $subscription->get_id() . '/plan', [ 'product_id' => 203 ] );
		$this->assertSame( '2027-01-01 00:00:00', $subscription->get_date( 'end' ), 'A 12-month plan expires 12 months from the start date.' );

		wc_create_mock_product(
			[
				'id'   => 204,
				'name' => 'Ongoing',
				'type' => 'subscription',
				'meta' => [ '_subscription_price' => 10 ],
			]
		);
		$this->post( '/subscriptions/' . $subscription->get_id() . '/plan', [ 'product_id' => 204 ] );
		$this->assertSame( 0, $subscription->get_date( 'end' ), 'Moving to an ongoing plan clears the stale expiry.' );
	}

	/**
	 * The plan-change guards: only another live subscription product, on an
	 * active subscription, and never a group product (groups are managed from
	 * the group surface, and a personal sub must not silently become a group).
	 */
	public function test_plan_change_rejects_invalid_targets() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		wc_create_mock_product(
			[
				'id'   => 201,
				'name' => 'Digital Monthly',
				'type' => 'subscription',
				'meta' => [ '_subscription_price' => 10 ],
			]
		);
		wc_create_mock_product(
			[
				'id'   => 210,
				'name' => 'Tote bag',
				'type' => 'simple',
			]
		);
		wc_create_mock_product(
			[
				'id'   => 211,
				'name' => 'Team plan',
				'type' => 'subscription',
				'meta' => [
					'_subscription_price'                  => 500,
					'_newspack_group_subscription_enabled' => 'yes',
				],
			]
		);
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[
				'items' => [
					501 => new WC_Order_Item_Product(
						[
							'id'         => 501,
							'product_id' => 201,
							'quantity'   => 1,
						]
					),
				],
			]
		);
		$route = '/subscriptions/' . $subscription->get_id() . '/plan';

		$this->assertSame( 'newspack_payments_invalid_plan', $this->post( $route, [ 'product_id' => 210 ] )->get_data()['code'], 'A non-subscription product is not a plan.' );
		$this->assertSame( 'newspack_payments_invalid_plan', $this->post( $route, [ 'product_id' => 211 ] )->get_data()['code'], 'A group product is not a personal plan.' );
		$this->assertSame( 'newspack_payments_same_plan', $this->post( $route, [ 'product_id' => 201 ] )->get_data()['code'] );

		$subscription->set_status( 'on-hold' );
		wc_create_mock_product(
			[
				'id'   => 202,
				'name' => 'Yearly',
				'type' => 'subscription',
				'meta' => [ '_subscription_price' => 150 ],
			]
		);
		$this->assertSame( 'newspack_payments_not_active', $this->post( $route, [ 'product_id' => 202 ] )->get_data()['code'], 'Plan change is an active-subscription action.' );
	}

	/**
	 * Plan options: every published subscription product except the current one
	 * and group products, with the price/cadence the picker renders.
	 */
	public function test_plan_options_lists_eligible_products() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		wc_create_mock_product(
			[
				'id'   => 201,
				'name' => 'Digital Monthly',
				'type' => 'subscription',
				'meta' => [
					'_subscription_price'           => 10,
					'_subscription_period'          => 'month',
					'_subscription_period_interval' => 1,
				],
			]
		);
		wc_create_mock_product(
			[
				'id'   => 202,
				'name' => 'Yearly',
				'type' => 'subscription',
				'meta' => [
					'_subscription_price'           => 150,
					'_subscription_period'          => 'year',
					'_subscription_period_interval' => 1,
				],
			]
		);
		wc_create_mock_product(
			[
				'id'   => 210,
				'name' => 'Tote bag',
				'type' => 'simple',
			] 
		);
		wc_create_mock_product(
			[
				'id'   => 211,
				'name' => 'Team plan',
				'type' => 'subscription',
				'meta' => [ '_newspack_group_subscription_enabled' => 'yes' ],
			]
		);
		$subscription = $this->create_tokenized_subscription(
			$reader_id,
			[
				'items' => [
					501 => new WC_Order_Item_Product(
						[
							'id'         => 501,
							'product_id' => 201,
							'quantity'   => 1,
						]
					),
				],
			]
		);

		$request  = new WP_REST_Request( 'GET', self::BASE . '/subscriptions/' . $subscription->get_id() . '/plan-options' );
		$response = rest_get_server()->dispatch( $request );
		$options  = $response->get_data()['options'];

		$this->assertCount( 1, $options, 'Only the other individual subscription product is offered.' );
		$this->assertSame( 202, $options[0]['id'] );
		$this->assertSame( 150.0, (float) $options[0]['amount'] );
		$this->assertSame( 'year', $options[0]['period'] );
	}

	/**
	 * Default-card rules: a valid card becomes the customer's default; an
	 * expired card is refused — an uncharged-able default is a trap for every
	 * renewal that would fall back to it.
	 */
	public function test_set_default_payment_method_rules() {
		$this->login_as_admin();
		$reader_id = $this->create_user();
		$visa      = $this->save_card( $reader_id, 'tok_a', [ 'default' => true ] );
		$mc        = $this->save_card( $reader_id, 'tok_b', [ 'brand' => 'mastercard' ] );
		$expired   = $this->save_card(
			$reader_id,
			'tok_c',
			[
				'brand' => 'amex',
				'month' => '01',
				'year'  => '2024',
			] 
		);

		$ok = $this->post( '/payment-methods/' . $mc->get_id() . '/default' );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertTrue( $mc->is_default() );
		$this->assertFalse( $visa->is_default() );

		$refused = $this->post( '/payment-methods/' . $expired->get_id() . '/default' );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'newspack_payments_token_expired', $refused->get_data()['code'] );
		$this->assertFalse( $expired->is_default() );
		$this->assertTrue( $mc->is_default(), 'The refused request leaves the previous default in place.' );
	}
}
