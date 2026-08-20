<?php
/**
 * Tests for the Hub webhook handler's delivery de-duplication and freshness.
 *
 * The Hub processes each incoming Node webhook at most once. A Node generates a
 * fresh nonce per event and encrypts the payload under it, so the nonce uniquely
 * identifies a delivery. These tests exercise that guarantee through
 * {@see Webhook::handle_webhook()}: a repeated delivery is acknowledged but not
 * reprocessed, and a delivery whose timestamp is far outside the freshness
 * window is ignored.
 *
 * @package Newspack_Network
 */

use Newspack_Network\Crypto;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Hub\Webhook;
use Newspack_Network\Hub\Used_Nonces;
use Newspack_Network\Hub\Database\Event_Log as Event_Log_Database;

/**
 * Test the Hub webhook handler.
 *
 * @group hub-webhook
 */
class TestHubWebhook extends \WP_UnitTestCase {

	/**
	 * A registered Node URL the Hub resolves the delivery against.
	 */
	const NODE_URL = 'https://sync-node.example.test';

	/**
	 * Email carried in the payload, used to isolate this test's event-log rows.
	 */
	const PROBE_EMAIL = 'probe@example.test';

	/**
	 * An action whose incoming-event class only persists (no handler side effects,
	 * no dependency on the Newspack plugin), so the event-log row count is a clean
	 * signal of how many times the delivery was processed.
	 */
	const ACTION = 'network_hub_name_updated';

	/**
	 * The shared secret the fixture Node signs with.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Create the custom tables once, before the per-test transaction.
	 *
	 * Both the used-nonce store and the event log create their table lazily via
	 * dbDelta, and DDL implicitly commits the open transaction. Triggering that here,
	 * outside any test's transaction, keeps a later in-test insert from being
	 * committed and leaking into the next test.
	 *
	 * @param \WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		Used_Nonces::get_table_name();
		Event_Log_Database::get_table_name();
	}

	/**
	 * Register a fixture Node on the Hub.
	 */
	public function set_up() {
		parent::set_up();

		$this->secret_key = Crypto::generate_secret_key();

		$node_id = self::factory()->post->create(
			[
				'post_type'   => Nodes::POST_TYPE_SLUG,
				'post_title'  => 'Sync Node',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $node_id, 'node-url', self::NODE_URL );
		update_post_meta( $node_id, 'secret-key', $this->secret_key );
	}

	/**
	 * Build a webhook request the way a Node would: the payload is encrypted under
	 * the nonce, and the request carries the same `site`/`action`/`timestamp`/`nonce`
	 * fields a Node sends.
	 *
	 * @param int    $timestamp Event timestamp.
	 * @param string $nonce     Nonce.
	 * @param string $data_json JSON payload to encrypt.
	 * @return \WP_REST_Request
	 */
	private function build_request( $timestamp, $nonce, $data_json ) {
		$request = new \WP_REST_Request( 'POST', '/newspack-network/v1/webhook' );
		$request->set_param( 'site', self::NODE_URL );
		$request->set_param( 'action', self::ACTION );
		$request->set_param( 'data', Crypto::encrypt_message( $data_json, $this->secret_key, $nonce ) );
		$request->set_param( 'timestamp', $timestamp );
		$request->set_param( 'nonce', $nonce );
		return $request;
	}

	/**
	 * Count event-log rows for this test's probe email.
	 *
	 * @return int
	 */
	private function event_log_count() {
		global $wpdb;
		$table = Event_Log_Database::get_table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE email = %s", self::PROBE_EMAIL ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A delivery received more than once is processed only once.
	 *
	 * The nonce that accompanies each delivery identifies it, so a repeat is recognised
	 * and skipped. The test sends the same signed payload three times and asserts a
	 * single event-log row.
	 */
	public function test_repeat_delivery_is_not_reprocessed() {
		$data_json = wp_json_encode(
			[
				'email' => self::PROBE_EMAIL,
				'name'  => 'Probe Hub',
			]
		);
		$timestamp = time();
		$nonce     = Crypto::generate_nonce();

		// First delivery: persisted once.
		$first = Webhook::handle_webhook( $this->build_request( $timestamp, $nonce, $data_json ) );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 1, $this->event_log_count(), 'First delivery should persist exactly one event.' );

		// The same delivery again.
		$again = Webhook::handle_webhook( $this->build_request( $timestamp, $nonce, $data_json ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 1, $this->event_log_count(), 'A repeat delivery must not add a second event.' );

		// The same delivery once more, with a later timestamp.
		$later = Webhook::handle_webhook( $this->build_request( $timestamp + HOUR_IN_SECONDS, $nonce, $data_json ) );
		$this->assertSame( 200, $later->get_status(), 'A repeat delivery is acknowledged, not errored.' );
		$this->assertSame( 1, $this->event_log_count(), 'A repeat delivery with a later timestamp must not add a second event.' );
	}

	/**
	 * A delivery whose timestamp is far outside the freshness window is turned away.
	 */
	public function test_stale_delivery_is_rejected() {
		$data_json = wp_json_encode(
			[
				'email' => self::PROBE_EMAIL,
				'name'  => 'Probe Hub',
			]
		);
		$stale = time() - ( 30 * DAY_IN_SECONDS );
		$nonce = Crypto::generate_nonce();

		$response = Webhook::handle_webhook( $this->build_request( $stale, $nonce, $data_json ) );
		$this->assertSame( 400, $response->get_status(), 'A stale delivery is turned away.' );
		$this->assertSame( 0, $this->event_log_count(), 'A delivery far outside the freshness window must not persist.' );
	}

	/**
	 * A fresh nonce still processes after another nonce has been claimed, so the
	 * guard blocks repeats without blocking distinct deliveries.
	 */
	public function test_distinct_nonce_is_not_blocked() {
		$data_json = wp_json_encode(
			[
				'email' => self::PROBE_EMAIL,
				'name'  => 'Probe Hub',
			]
		);
		$timestamp = time();

		$first = Webhook::handle_webhook( $this->build_request( $timestamp, Crypto::generate_nonce(), $data_json ) );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 1, $this->event_log_count() );

		// A different delivery (fresh nonce, distinct timestamp so the event-log
		// dedupe does not absorb it) must be processed, not treated as a repeat.
		$second = Webhook::handle_webhook( $this->build_request( $timestamp + HOUR_IN_SECONDS, Crypto::generate_nonce(), $data_json ) );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 2, $this->event_log_count(), 'A distinct delivery under a fresh nonce must still process.' );
	}

	/**
	 * The nonce store records a nonce once, recognises a repeat, forgets a released
	 * nonce, and refuses a malformed one.
	 */
	public function test_used_nonces_claim_release_and_validation() {
		$nonce = Crypto::generate_nonce();

		$this->assertTrue( Used_Nonces::claim( $nonce ), 'A nonce is claimable the first time.' );
		$this->assertFalse( Used_Nonces::claim( $nonce ), 'A second claim of the same nonce is recognised as a repeat.' );
		$this->assertTrue( Used_Nonces::claim( Crypto::generate_nonce() ), 'A distinct nonce is claimable.' );

		$this->assertNull( Used_Nonces::claim( 'not-a-valid-nonce' ), 'A malformed nonce cannot be recorded.' );

		Used_Nonces::release( $nonce );
		$this->assertTrue( Used_Nonces::claim( $nonce ), 'A released nonce is claimable again.' );
	}

	/**
	 * The same nonce in different hex casing is recognised as one, so recognising a
	 * repeat does not depend on the storage column's collation.
	 */
	public function test_nonce_claim_normalises_casing() {
		$nonce = Crypto::generate_nonce();

		$this->assertTrue( Used_Nonces::claim( $nonce ), 'The nonce is claimable the first time.' );
		$this->assertFalse( Used_Nonces::claim( strtoupper( $nonce ) ), 'The same nonce in a different casing is recognised as a repeat.' );
	}
}
