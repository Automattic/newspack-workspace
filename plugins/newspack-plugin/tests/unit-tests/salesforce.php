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
}
