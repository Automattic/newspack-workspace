<?php
/**
 * Tests the Salesforce webhook validation.
 *
 * @package Newspack\Tests
 */

use Newspack\Salesforce;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Tests the Salesforce webhook validation.
 */
class Newspack_Test_Salesforce extends WP_UnitTestCase {
	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		// The mock webhook registry is process-global; reset it so ids are deterministic per test.
		WC_Webhook::$registry = [];
	}

	/**
	 * Create a WooCommerce webhook and register it as the Salesforce sync webhook.
	 *
	 * @return WC_Webhook
	 */
	private function register_sync_webhook() {
		$webhook = new WC_Webhook();
		$webhook->set_name( 'Test Salesforce sync' );
		$webhook->set_topic( 'order.created' );
		$webhook->set_secret( 'test-secret-abc123' );
		$webhook->set_status( 'active' );
		$webhook->set_delivery_url( 'https://example.test/wp-json/newspack/salesforce/v1/sync' );
		$webhook->save();
		update_option( 'newspack_salesforce_webhook_id', $webhook->get_id() );
		return $webhook;
	}

	/**
	 * Build a sync request for the registered webhook id.
	 *
	 * @param int    $webhook_id Webhook id.
	 * @param string $body       Raw request body.
	 * @return WP_REST_Request
	 */
	private function build_sync_request( $webhook_id, $body ) {
		$request = new WP_REST_Request( 'POST', '/newspack/salesforce/v1/sync' );
		$request->set_header( 'X-WC-Webhook-ID', (string) $webhook_id );
		$request->set_body( $body );
		return $request;
	}

	/**
	 * A request without a signature must be rejected, even with a valid webhook id.
	 */
	public function test_webhook_rejects_request_without_signature() {
		$webhook = $this->register_sync_webhook();
		$request = $this->build_sync_request( $webhook->get_id(), wp_json_encode( [ 'id' => 1 ] ) );

		self::assertTrue(
			is_wp_error( Salesforce::api_validate_webhook( $request ) ),
			'A webhook request with no signature must be rejected.'
		);
	}

	/**
	 * A request with an incorrect signature must be rejected.
	 */
	public function test_webhook_rejects_request_with_invalid_signature() {
		$webhook = $this->register_sync_webhook();
		$request = $this->build_sync_request( $webhook->get_id(), wp_json_encode( [ 'id' => 1 ] ) );
		$request->set_header( 'X-WC-Webhook-Signature', 'not-a-valid-signature' );

		self::assertTrue(
			is_wp_error( Salesforce::api_validate_webhook( $request ) ),
			'A webhook request with an incorrect signature must be rejected.'
		);
	}

	/**
	 * A correctly signed request is accepted.
	 */
	public function test_webhook_accepts_correctly_signed_request() {
		$webhook = $this->register_sync_webhook();
		$body    = wp_json_encode( [ 'id' => 1 ] );
		$request = $this->build_sync_request( $webhook->get_id(), $body );
		$request->set_header( 'X-WC-Webhook-Signature', $webhook->generate_signature( $body ) );

		self::assertTrue(
			true === Salesforce::api_validate_webhook( $request ),
			'A correctly signed webhook request is accepted.'
		);
	}

	/**
	 * A request signed with the wrong secret (a well-formed but forged signature) is rejected.
	 */
	public function test_webhook_rejects_request_signed_with_wrong_secret() {
		$webhook = $this->register_sync_webhook();
		$body    = wp_json_encode( [ 'id' => 1 ] );

		$forged = new WC_Webhook();
		$forged->set_secret( 'a-different-secret' );

		$request = $this->build_sync_request( $webhook->get_id(), $body );
		$request->set_header( 'X-WC-Webhook-Signature', $forged->generate_signature( $body ) );

		self::assertTrue(
			is_wp_error( Salesforce::api_validate_webhook( $request ) ),
			'A request signed with the wrong secret must be rejected.'
		);
	}

	/**
	 * Save the credentials that make Salesforce count as connected.
	 */
	private function connect_salesforce() {
		update_option( 'newspack_salesforce_client_id', 'test-client-id' );
		update_option( 'newspack_salesforce_client_secret', 'test-client-secret' );
		update_option( 'newspack_salesforce_refresh_token', 'test-refresh-token' );
		update_option( 'newspack_salesforce_access_token', 'test-access-token' );
		update_option( 'newspack_salesforce_instance_url', 'https://salesforce.example.test' );
	}

	/**
	 * A site that never connected Salesforce gets no sync webhook, so its orders
	 * are never sent to a sync that can only fail.
	 */
	public function test_platform_check_creates_no_webhook_until_salesforce_is_connected() {
		delete_option( 'newspack_salesforce_webhook_id' );

		Salesforce::platform_check();

		self::assertSame( 0, (int) get_option( 'newspack_salesforce_webhook_id' ), 'No webhook ID is stored.' );
		self::assertSame( [], WC_Webhook::$registry, 'No webhook is created.' );
	}

	/**
	 * A webhook created before Salesforce was connected is removed.
	 */
	public function test_platform_check_removes_webhook_when_salesforce_is_not_connected() {
		$webhook = $this->register_sync_webhook();

		Salesforce::platform_check();

		self::assertSame( 0, (int) get_option( 'newspack_salesforce_webhook_id' ), 'The stored webhook ID is cleared.' );
		self::assertNull( wc_get_webhook( $webhook->get_id() ), 'The webhook itself is deleted.' );
	}

	/**
	 * A newly created sync webhook is given a signing secret.
	 */
	public function test_platform_check_creates_webhook_with_secret() {
		$this->connect_salesforce();
		delete_option( 'newspack_salesforce_webhook_id' );

		// is_platform_wc() defaults to true, so this creates the sync webhook.
		Salesforce::platform_check();

		$webhook_id = (int) get_option( 'newspack_salesforce_webhook_id' );
		self::assertNotEmpty( $webhook_id, 'A sync webhook is created.' );
		self::assertNotEmpty(
			wc_get_webhook( $webhook_id )->get_secret(),
			'The created webhook has a signing secret.'
		);
	}

	/**
	 * An existing webhook without a secret is backfilled with one.
	 */
	public function test_platform_check_backfills_missing_secret() {
		$this->connect_salesforce();
		$webhook = new WC_Webhook();
		$webhook->set_status( 'active' );
		$webhook->save();
		update_option( 'newspack_salesforce_webhook_id', $webhook->get_id() );
		self::assertSame( '', $webhook->get_secret(), 'Precondition: the webhook has no secret.' );

		Salesforce::platform_check();

		self::assertNotEmpty(
			wc_get_webhook( $webhook->get_id() )->get_secret(),
			'An existing webhook without a secret is backfilled with one.'
		);
	}

	/**
	 * A webhook whose stored secret is empty must be rejected, even with a signature
	 * that matches the empty key — such a signature is reproducible by anyone.
	 */
	public function test_webhook_rejects_empty_secret_webhook() {
		$webhook = new WC_Webhook();
		$webhook->set_status( 'active' );
		$webhook->save();
		update_option( 'newspack_salesforce_webhook_id', $webhook->get_id() );
		self::assertSame( '', $webhook->get_secret(), 'Precondition: the webhook has no secret.' );

		$body    = wp_json_encode( [ 'id' => 1 ] );
		$request = $this->build_sync_request( $webhook->get_id(), $body );
		// The empty-key signature is computable by anyone; the guard must reject regardless.
		$request->set_header( 'X-WC-Webhook-Signature', $webhook->generate_signature( $body ) );

		self::assertTrue(
			is_wp_error( Salesforce::api_validate_webhook( $request ) ),
			'A webhook with no signing secret must be rejected even with a matching empty-key signature.'
		);
	}

	/**
	 * A request whose webhook id does not match the configured one is rejected, even
	 * when the signature is otherwise valid.
	 */
	public function test_webhook_rejects_mismatched_webhook_id() {
		$webhook = $this->register_sync_webhook();
		$body    = wp_json_encode( [ 'id' => 1 ] );

		$request = $this->build_sync_request( $webhook->get_id() + 1, $body );
		$request->set_header( 'X-WC-Webhook-Signature', $webhook->generate_signature( $body ) );

		self::assertTrue(
			is_wp_error( Salesforce::api_validate_webhook( $request ) ),
			'A request whose webhook id does not match the configured one must be rejected.'
		);
	}

	/**
	 * The signature is verified over the raw request body: signing one body and sending
	 * a different one is rejected.
	 */
	public function test_webhook_rejects_tampered_body() {
		$webhook = $this->register_sync_webhook();

		$signed_body = wp_json_encode( [ 'id' => 1 ] );
		$sent_body   = wp_json_encode( [ 'id' => 999 ] );
		$request     = $this->build_sync_request( $webhook->get_id(), $sent_body );
		$request->set_header( 'X-WC-Webhook-Signature', $webhook->generate_signature( $signed_body ) );

		self::assertTrue(
			is_wp_error( Salesforce::api_validate_webhook( $request ) ),
			'A request whose body differs from the signed body must be rejected.'
		);
	}

	/**
	 * Which Salesforce request fails during a sync.
	 *
	 * @return array
	 */
	public function failed_salesforce_request_provider() {
		return [
			'contact lookup' => [ 'GET' ],
			'contact create' => [ 'POST' ],
		];
	}

	/**
	 * When a request to Salesforce fails, the order note carries that request's error
	 * rather than a generic message, so a site that uses Salesforce can see what went wrong.
	 *
	 * Isolated because order parsing requires a WooCommerce class, and an alias of one
	 * cannot be undone; the rest of the suite runs without WooCommerce.
	 *
	 * @dataProvider failed_salesforce_request_provider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $failing_method HTTP method of the request that fails.
	 */
	public function test_sync_failure_note_carries_the_salesforce_error( $failing_method ) {
		global $orders_database;
		$orders_database = [];
		class_alias( WC_Order::class, 'WooCommerce' );
		$this->connect_salesforce();

		add_filter(
			'pre_http_request',
			function( $preempt, $args ) use ( $failing_method ) {
				if ( $failing_method === $args['method'] ) {
					return new WP_Error( 'http_request_failed', 'Salesforce did not respond.' );
				}
				// The contact lookup finds no one, so the sync goes on to create a contact.
				return [
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'totalSize' => 0,
							'records'   => [],
						]
					),
					'response' => [ 'code' => 200 ],
					'cookies'  => [],
				];
			},
			10,
			2
		);

		$donation = new WC_Order_Item_Product(
			[
				'name'  => 'Donation',
				'total' => 10,
			]
		);
		$order    = wc_create_order(
			[
				'status'        => 'pending',
				'billing_email' => 'reader@example.test',
				'items'         => [ $donation ],
			]
		);
		$request = new WP_REST_Request( 'POST', '/newspack/salesforce/v1/sync' );
		$request->set_param( 'id', $order->get_id() );

		Salesforce::api_sync_salesforce( $request );

		self::assertSame(
			[ 'Error syncing order to Salesforce. Salesforce did not respond.' ],
			wc_get_order( $order->get_id() )->data['order_notes'] ?? [],
			'The order note names the error Salesforce returned.'
		);
	}
}
