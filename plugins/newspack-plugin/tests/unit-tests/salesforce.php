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

	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		unset( $_GET['id'] );
		$GLOBALS['current_screen'] = null;
		// The admin-script tests enqueue into the process-global registry.
		$GLOBALS['wp_scripts'] = null;
		parent::tear_down();
	}

	/**
	 * Invoke a private static Salesforce method.
	 *
	 * @param string $method  Method name.
	 * @param mixed  ...$args Arguments to pass along.
	 * @return mixed The method's return value.
	 */
	private static function call_private( $method, ...$args ) {
		$reflection = new ReflectionMethod( Salesforce::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	/**
	 * Store the settings that make the Salesforce connection look established.
	 */
	private function connect_salesforce() {
		update_option( Salesforce::SALESFORCE_CLIENT_ID, 'test-client-id' );
		update_option( Salesforce::SALESFORCE_CLIENT_SECRET, 'test-client-secret' );
		update_option( Salesforce::SALESFORCE_REFRESH_TOKEN, 'test-refresh-token' );
		update_option( Salesforce::SALESFORCE_INSTANCE_URL, 'https://newspack-test.my.salesforce.com' );
	}

	/**
	 * Opportunity ids are stored under the meta key the re-sync lookup reads back.
	 */
	public function test_save_opportunity_ids_stores_ids_under_meta_key() {
		$order         = wc_create_order(
			[
				'status'      => 'processing',
				'customer_id' => 1,
			]
		);
		$opportunities = [ '0068d00000AAAAA', '0068d00000BBBBB' ];

		self::call_private( 'save_opportunity_ids', $order->get_id(), $opportunities );

		self::assertSame(
			$opportunities,
			wc_get_order( $order->get_id() )->get_meta( 'newspack_salesforce_opportunities' ),
			'Opportunity ids are readable under the meta key the re-sync lookup uses.'
		);
	}

	/**
	 * A junk meta row keyed by the order id (written by the argument-shifted call
	 * from Automattic/newspack-plugin#2711) is removed when the order re-syncs.
	 */
	public function test_save_opportunity_ids_scrubs_argument_shifted_row() {
		$order = wc_create_order(
			[
				'status'      => 'processing',
				'customer_id' => 1,
			]
		);
		$order->update_meta_data( (string) $order->get_id(), 'newspack_salesforce_opportunities' );

		self::call_private( 'save_opportunity_ids', $order->get_id(), [ '0068d00000AAAAA' ] );

		self::assertFalse(
			$order->meta_exists( (string) $order->get_id() ),
			'The junk row keyed by the order id is removed on re-sync.'
		);
	}

	/**
	 * When Salesforce does not grant a new access token, the refresh returns an
	 * error instead of null, so callers cannot mistake the failure for a token.
	 */
	public function test_refresh_token_returns_error_when_no_token_granted() {
		$this->connect_salesforce();
		$filter = function() {
			return [
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'error'             => 'invalid_grant',
						'error_description' => 'expired access/refresh token',
					]
				),
				'response' => [
					'code'    => 400,
					'message' => 'Bad Request',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $filter );

		$result = self::call_private( 'refresh_salesforce_token' );

		remove_filter( 'pre_http_request', $filter );

		self::assertWPError( $result, 'A refresh that grants no token returns an error.' );
	}

	/**
	 * A refresh response whose body isn't decodable JSON is reported as an error.
	 * The suite promotes PHP warnings to failures, so this also pins that reading
	 * fields off the failed decode stays warning-free.
	 */
	public function test_refresh_token_handles_undecodable_response_body() {
		$this->connect_salesforce();
		$filter = function() {
			return [
				'headers'  => [],
				'body'     => 'Bad Gateway',
				'response' => [
					'code'    => 502,
					'message' => 'Bad Gateway',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $filter );

		$result = self::call_private( 'refresh_salesforce_token' );

		remove_filter( 'pre_http_request', $filter );

		self::assertWPError( $result, 'An undecodable refresh response is reported as an error.' );
	}

	/**
	 * A granted token is saved and returned.
	 */
	public function test_refresh_token_saves_and_returns_granted_token() {
		$this->connect_salesforce();
		$filter = function() {
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'access_token' => 'new-access-token' ] ),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $filter );

		$result = self::call_private( 'refresh_salesforce_token' );

		remove_filter( 'pre_http_request', $filter );

		self::assertSame( 'new-access-token', $result, 'The granted token is returned.' );
		self::assertSame( 'new-access-token', get_option( Salesforce::SALESFORCE_ACCESS_TOKEN ), 'The granted token is saved.' );
	}

	/**
	 * When the access token is expired and the refresh fails, the request helper
	 * returns the refresh error rather than retrying with an empty bearer token.
	 */
	public function test_build_request_propagates_failed_refresh() {
		$this->connect_salesforce();
		update_option( Salesforce::SALESFORCE_ACCESS_TOKEN, 'expired-token' );

		$authorizations = [];
		$filter         = function( $preempt, $args, $url ) use ( &$authorizations ) {
			$authorizations[] = $args['headers']['Authorization'] ?? '';
			if ( is_string( $url ) && false !== strpos( $url, 'login.salesforce.com' ) ) {
				// The OAuth token endpoint refuses to grant a token.
				return [
					'headers'  => [],
					'body'     => wp_json_encode( [ 'error' => 'invalid_grant' ] ),
					'response' => [
						'code'    => 400,
						'message' => 'Bad Request',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
			// The instance API rejects the expired access token.
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [
					'code'    => 401,
					'message' => 'Unauthorized',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$result = self::call_private( 'build_request', '/services/data/v48.0/query' );

		remove_filter( 'pre_http_request', $filter );

		self::assertWPError( $result, 'A failed token refresh becomes the request result.' );
		self::assertNotContains( 'Bearer ', $authorizations, 'No request is sent with an empty bearer token.' );
	}

	/**
	 * The sync-status script loads on the HPOS order editor, where orders are not
	 * posts and the screen id is WooCommerce's orders page.
	 */
	public function test_admin_scripts_enqueue_on_hpos_order_editor() {
		$this->connect_salesforce();
		set_current_screen( 'woocommerce_page_wc-orders' );
		$_GET['id'] = '123';

		Salesforce::register_admin_scripts();

		self::assertTrue(
			wp_script_is( 'newspack-salesforce-sync-status', 'enqueued' ),
			'The sync-status script is enqueued on the HPOS order editor.'
		);
		self::assertStringContainsString(
			'"order_id":"123"',
			wp_scripts()->get_data( 'newspack-salesforce-sync-status', 'data' ),
			'The edited order id reaches the script without a post context.'
		);
	}

	/**
	 * The HPOS orders list shares the editor's screen id; with no order id in the
	 * query string there is no order to report on, so the script stays out.
	 */
	public function test_admin_scripts_skip_hpos_orders_list() {
		$this->connect_salesforce();
		set_current_screen( 'woocommerce_page_wc-orders' );

		Salesforce::register_admin_scripts();

		self::assertFalse(
			wp_script_is( 'newspack-salesforce-sync-status', 'enqueued' ),
			'The sync-status script is not enqueued on the orders list.'
		);
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
	 * A newly created sync webhook is given a signing secret.
	 */
	public function test_platform_check_creates_webhook_with_secret() {
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
}
