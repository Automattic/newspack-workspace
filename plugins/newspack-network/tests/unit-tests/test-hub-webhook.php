<?php
/**
 * Tests for the Hub webhook handler's delivery de-duplication.
 *
 * The Hub processes each incoming Node webhook at most once. A Node generates a
 * fresh nonce per event and encrypts the payload under it, so the nonce uniquely
 * identifies a delivery. The nonce store follows each delivery from claim to
 * completion, and these tests exercise that lifecycle through
 * {@see Webhook::handle_webhook()} and against {@see Used_Nonces} directly: a
 * repeated delivery is acknowledged but not reprocessed, a delivery still being
 * processed is answered with a retryable error, and a failed delivery stays
 * retryable.
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
	 * The payload the fixture Node delivers.
	 *
	 * @return string
	 */
	private function probe_payload() {
		return wp_json_encode(
			[
				'email' => self::PROBE_EMAIL,
				'name'  => 'Probe Hub',
			]
		);
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
		$data_json = $this->probe_payload();
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
	 * A fresh nonce still processes after another nonce has been claimed, so the
	 * guard blocks repeats without blocking distinct deliveries.
	 */
	public function test_distinct_nonce_is_not_blocked() {
		$data_json = $this->probe_payload();
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
	 * A delivery whose nonce is claimed but not yet completed is answered with a
	 * retryable error, not processed alongside the attempt that holds the claim and
	 * not falsely acknowledged. This is what keeps two copies of the same delivery
	 * arriving at once from both being processed.
	 */
	public function test_delivery_in_progress_is_not_processed_again() {
		$nonce = Crypto::generate_nonce();
		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'The first attempt holds the claim.' );

		$response = Webhook::handle_webhook( $this->build_request( time(), $nonce, $this->probe_payload() ) );

		$this->assertSame( 500, $response->get_status(), 'A delivery already being processed gets a retryable error, not an acknowledgement.' );
		$this->assertSame( 0, $this->event_log_count(), 'The second copy of the delivery is not processed.' );
	}

	/**
	 * A failed Event Log write releases the claim and returns a 5xx, so the delivery
	 * is retryable rather than acknowledged as lost.
	 */
	public function test_failed_event_log_write_releases_nonce() {
		global $wpdb;
		$table = Event_Log_Database::get_table_name();

		// Force the Event Log INSERT to fail, standing in for a transient write error.
		$break_insert = function ( $query ) use ( $table ) {
			if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, $table ) ) {
				return "INSERT INTO {$table}_missing ( x ) VALUES ( 1 )";
			}
			return $query;
		};
		add_filter( 'query', $break_insert );
		$suppress = $wpdb->suppress_errors( true );

		$nonce    = Crypto::generate_nonce();
		$response = Webhook::handle_webhook( $this->build_request( time(), $nonce, $this->probe_payload() ) );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_insert );

		$this->assertSame( 500, $response->get_status(), 'A failed write returns a retryable 5xx.' );
		$this->assertSame( 0, $this->event_log_count(), 'Nothing is persisted when the write fails.' );
		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'The claim is released so the retry can reprocess.' );
	}

	/**
	 * An exception thrown before anything is persisted releases the claim and
	 * returns a 5xx, so the delivery is retryable — nothing happened, and the
	 * retry can do the work.
	 */
	public function test_exception_before_persistence_releases_nonce() {
		$table = Event_Log_Database::get_table_name();

		// Interrupt the Event Log INSERT with an exception, standing in for
		// processing that dies before the event is recorded.
		$interrupt = function ( $query ) use ( $table ) {
			if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, $table ) ) {
				throw new \RuntimeException( 'Interrupted.' );
			}
			return $query;
		};
		add_filter( 'query', $interrupt );

		$nonce    = Crypto::generate_nonce();
		$response = Webhook::handle_webhook( $this->build_request( time(), $nonce, $this->probe_payload() ) );

		remove_filter( 'query', $interrupt );

		$this->assertSame( 500, $response->get_status(), 'An interrupted delivery returns a retryable 5xx.' );
		$this->assertSame( 0, $this->event_log_count(), 'Nothing is persisted when processing is interrupted.' );
		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'The claim is released so the retry can reprocess.' );
	}

	/**
	 * An exception thrown after the event is persisted holds the claim: the
	 * pipeline does not re-run the remaining work for a delivery already on
	 * record, so releasing would let the retry be acknowledged as success. The
	 * delivery is answered with an error and stays held, so the failure surfaces
	 * instead of being recorded as done.
	 */
	public function test_exception_after_persistence_holds_the_claim() {
		$table = Event_Log_Database::get_table_name();

		// Arm after the Event Log INSERT, then interrupt the next post lookup —
		// the first thing processing does once the event is recorded.
		$armed = false;
		$arm   = function ( $query ) use ( $table, &$armed ) {
			if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, $table ) ) {
				$armed = true;
			}
			return $query;
		};
		$interrupt = function () use ( &$armed ) {
			if ( $armed ) {
				throw new \RuntimeException( 'Interrupted.' );
			}
		};
		add_filter( 'query', $arm );
		add_action( 'pre_get_posts', $interrupt );

		$nonce    = Crypto::generate_nonce();
		$response = Webhook::handle_webhook( $this->build_request( time(), $nonce, $this->probe_payload() ) );

		remove_filter( 'query', $arm );
		remove_action( 'pre_get_posts', $interrupt );

		$this->assertSame( 500, $response->get_status(), 'A failure after persistence is answered as an error, not acknowledged.' );
		$this->assertSame( 1, $this->event_log_count(), 'The event was persisted before the interruption.' );
		$this->assertSame( 'pending', Used_Nonces::claim( $nonce ), 'The claim is held, so a repeat of the delivery is not acknowledged as done.' );
	}

	/**
	 * A delivery whose completion could not be recorded is still acknowledged:
	 * the work is done, so the node is told the truth. The record simply stays
	 * pending, so a repeat of the delivery is not acknowledged as done.
	 */
	public function test_failed_completion_write_still_acknowledges_the_delivery() {
		global $wpdb;
		$nonces_table = Used_Nonces::get_table_name();

		// Force the completion UPDATE to fail, standing in for a transient write
		// error after the event has been recorded.
		$break_update = function ( $query ) use ( $nonces_table ) {
			if ( 0 === stripos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, $nonces_table ) ) {
				return "UPDATE {$nonces_table}_missing SET x = 1";
			}
			return $query;
		};
		add_filter( 'query', $break_update );
		$suppress = $wpdb->suppress_errors( true );

		$nonce    = Crypto::generate_nonce();
		$response = Webhook::handle_webhook( $this->build_request( time(), $nonce, $this->probe_payload() ) );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_update );

		$this->assertSame( 200, $response->get_status(), 'The delivery was processed, so it is acknowledged even when the completion write fails.' );
		$this->assertSame( 1, $this->event_log_count(), 'The event was persisted.' );
		$this->assertSame( 'pending', Used_Nonces::claim( $nonce ), 'The record stays pending, so a repeat of the delivery is not acknowledged as done.' );
	}

	/**
	 * The nonce store claims a nonce once, releases it on request, and refuses a
	 * malformed one.
	 */
	public function test_used_nonces_claim_release_and_validation() {
		$nonce = Crypto::generate_nonce();

		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'A fresh nonce is claimable.' );
		$this->assertSame( 'claimed', Used_Nonces::claim( Crypto::generate_nonce() ), 'A distinct nonce is claimable while another is pending.' );

		$this->assertNull( Used_Nonces::claim( 'not-a-valid-nonce' ), 'A malformed nonce cannot be recorded.' );

		$this->assertTrue( Used_Nonces::release( $nonce ), 'An unfinished claim can be released.' );
		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'A released nonce is claimable again.' );
	}

	/**
	 * A second claim of a nonce whose delivery has not yet completed reports it as
	 * pending, so the caller can answer with a retryable error instead of processing
	 * the delivery again or acknowledging it as done.
	 */
	public function test_claim_reports_a_delivery_still_in_progress() {
		$nonce = Crypto::generate_nonce();

		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ) );
		$this->assertSame( 'pending', Used_Nonces::claim( $nonce ), 'While the delivery is unfinished, a further claim reports it pending.' );
	}

	/**
	 * Once a delivery is marked completed, every later claim of its nonce reports
	 * that, so the delivery is acknowledged without being reprocessed.
	 */
	public function test_claim_recognises_a_completed_delivery() {
		$nonce = Crypto::generate_nonce();

		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ) );
		$this->assertTrue( Used_Nonces::complete( $nonce ), 'The delivery is marked completed.' );
		$this->assertSame( 'completed', Used_Nonces::claim( $nonce ), 'A completed delivery is reported as such on any later claim.' );
	}

	/**
	 * Releasing removes an unfinished claim so a retry can process the delivery, and
	 * leaves a completed record in place, since removing that would let its delivery
	 * be processed again.
	 */
	public function test_release_removes_only_pending_claims() {
		$pending   = Crypto::generate_nonce();
		$completed = Crypto::generate_nonce();

		Used_Nonces::claim( $pending );
		Used_Nonces::claim( $completed );
		Used_Nonces::complete( $completed );

		$this->assertTrue( Used_Nonces::release( $pending ), 'A pending claim is released.' );
		$this->assertSame( 'claimed', Used_Nonces::claim( $pending ), 'Releasing a pending claim makes the nonce claimable again.' );

		Used_Nonces::release( $completed );
		$this->assertSame( 'completed', Used_Nonces::claim( $completed ), 'A completed record survives a release, so its delivery stays recognised.' );
	}

	/**
	 * Completing and releasing find the record whatever hex casing they are handed,
	 * matching how the claim recorded it, so a claim can always be finished or undone.
	 */
	public function test_complete_and_release_normalise_casing() {
		$completed = Crypto::generate_nonce();
		Used_Nonces::claim( $completed );
		$this->assertTrue( Used_Nonces::complete( strtoupper( $completed ) ), 'Completion accepts the other casing.' );
		$this->assertSame( 'completed', Used_Nonces::claim( $completed ), 'Completion under a different casing marks the same record.' );

		$released = Crypto::generate_nonce();
		Used_Nonces::claim( $released );
		$this->assertTrue( Used_Nonces::release( strtoupper( $released ) ), 'Release accepts the other casing.' );
		$this->assertSame( 'claimed', Used_Nonces::claim( $released ), 'Release under a different casing removes the same record.' );
	}

	/**
	 * The same nonce in different hex casing is recognised as one delivery, so
	 * recognising a repeat does not depend on the storage column's collation.
	 */
	public function test_nonce_claim_normalises_casing() {
		$nonce = Crypto::generate_nonce();

		$this->assertSame( 'claimed', Used_Nonces::claim( $nonce ), 'The nonce is claimable the first time.' );
		$this->assertSame( 'pending', Used_Nonces::claim( strtoupper( $nonce ) ), 'The same nonce in a different casing is recognised as the same delivery.' );
	}

	/**
	 * When the claim INSERT fails and no record results, claim() reports null rather
	 * than guessing a state: the caller answers with a retryable error, and the retry
	 * claims afresh.
	 */
	public function test_failed_claim_write_is_reported_as_indeterminate() {
		$table = Used_Nonces::get_table_name();

		// Force the claim INSERT to fail, standing in for a transient write error.
		$break_insert = function ( $query ) use ( $table ) {
			if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, $table ) ) {
				return "INSERT INTO {$table}_missing ( x ) VALUES ( 1 )";
			}
			return $query;
		};
		add_filter( 'query', $break_insert );

		$result = Used_Nonces::claim( Crypto::generate_nonce() );

		remove_filter( 'query', $break_insert );

		$this->assertNull( $result, 'A claim that could not be recorded reports no state, so the caller retries rather than guesses.' );
	}
}
