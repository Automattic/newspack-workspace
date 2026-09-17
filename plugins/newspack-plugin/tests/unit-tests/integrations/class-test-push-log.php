<?php
/**
 * Tests for the integrations push log.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Integrations\Push_Log;

/**
 * Push log test case.
 *
 * The push log is the publisher-facing record of what was sent to each
 * integration: one row per reader, integration and triggering push.
 *
 * @group integrations
 * @group push-log
 */
class Test_Push_Log extends \WP_UnitTestCase {

	/**
	 * Reset the once-per-request failure report, so a test that breaks the
	 * table on purpose does not silence the report in a later test.
	 */
	public function set_up() {
		parent::set_up();
		$write_failure_reported = new \ReflectionProperty( Push_Log::class, 'write_failure_reported' );
		$write_failure_reported->setAccessible( true );
		$write_failure_reported->setValue( null, false );
	}

	/**
	 * The contact a sample integration would receive.
	 *
	 * @param array $metadata Metadata to merge over the defaults.
	 * @return array
	 */
	private function sample_payload( array $metadata = [] ): array {
		return [
			'email'    => 'reader@example.test',
			'name'     => 'Sample Reader',
			'metadata' => array_merge(
				[
					'NP_Membership_Status' => 'active',
					'NP_Total_Paid'        => '120',
				],
				$metadata
			),
		];
	}

	/**
	 * Record an attempt, overriding only what the test is about.
	 *
	 * @param array $overrides Arguments to override.
	 * @return int The row ID.
	 */
	private function record( array $overrides = [] ): int {
		return Push_Log::record_attempt(
			array_merge(
				[
					'integration_id' => 'sample',
					'email'          => 'reader@example.test',
					'user_id'        => 7,
					'operation'      => Push_Log::OPERATION_UPSERT,
					'context'        => 'Test context',
					'payload'        => $this->sample_payload(),
					'hash_prefix'    => 'NP_',
					'result'         => true,
					'attempts'       => 1,
					'max_attempts'   => 6,
				],
				$overrides
			)
		);
	}

	/**
	 * Read one row.
	 *
	 * @param int $row_id The row ID.
	 * @return array|null
	 */
	private function get_row( int $row_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Push_Log::get_table_name(), $row_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Count every row in the table.
	 *
	 * @return int
	 */
	private function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Push_Log::get_table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * A successful push leaves a row a publisher can read back: who, where,
	 * why, and the exact contact the integration was handed.
	 */
	public function test_successful_push_is_recorded_with_the_payload_it_sent() {
		$row = $this->get_row( $this->record() );

		$this->assertSame( 'sample', $row['integration_id'] );
		$this->assertSame( 'reader@example.test', $row['email'] );
		$this->assertEquals( 7, $row['user_id'] );
		$this->assertSame( Push_Log::OPERATION_UPSERT, $row['operation'] );
		$this->assertSame( 'Test context', $row['context'] );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $row['status'] );
		$this->assertEquals( 1, $row['attempts'] );
		$this->assertEquals( 6, $row['max_attempts'] );
		$this->assertSame( $this->sample_payload(), json_decode( $row['payload'], true ) );
		$this->assertNull( $row['error_class'] );
		$this->assertNull( $row['error_message'] );
	}

	/**
	 * The ESP layer stacks messages ahead of the provider's own, so a failed
	 * row keeps all of them, not only the first.
	 */
	public function test_failed_push_is_recorded_as_failed_with_every_error_message() {
		$push_error = new \WP_Error( 'provider_down', 'Invalid list' );
		$push_error->add( 'provider_down', 'ESP 503' );

		$row = $this->get_row(
			$this->record(
				[
					'result'      => $push_error,
					'error_class' => 'transient',
				]
			)
		);

		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'transient', $row['error_class'] );
		$this->assertSame( 'provider_down', $row['error_code'] );
		$this->assertSame( 'Invalid list; ESP 503', $row['error_message'] );
	}

	/**
	 * A benign error means the provider already holds the contact, so the
	 * sync reached its end state: the row reads as a success and still says
	 * what the provider answered.
	 */
	public function test_benign_error_is_recorded_as_a_success_that_keeps_the_error() {
		$row = $this->get_row(
			$this->record(
				[
					'result'      => new \WP_Error( 'member_exists', 'Member exists' ),
					'error_class' => 'benign',
				]
			)
		);

		$this->assertSame( Push_Log::STATUS_SUCCESS, $row['status'] );
		$this->assertSame( 'benign', $row['error_class'] );
		$this->assertSame( 'Member exists', $row['error_message'] );
	}

	/**
	 * A hard delete sends no contact data, so its row carries no payload.
	 */
	public function test_hard_delete_is_recorded_without_a_payload() {
		$row = $this->get_row(
			$this->record(
				[
					'operation' => Push_Log::OPERATION_DELETE,
					'payload'   => null,
				]
			)
		);

		$this->assertSame( Push_Log::OPERATION_DELETE, $row['operation'] );
		$this->assertNull( $row['payload'] );
		$this->assertNull( $row['payload_hash'] );
	}

	/**
	 * The database rejects a value longer than the column, which would drop
	 * the whole row. A guest checkout can carry an address longer than the
	 * 100 characters WordPress allows its own users.
	 */
	public function test_an_overlong_email_is_truncated_rather_than_losing_the_row() {
		$overlong_email = str_repeat( 'a', 200 ) . '@example.test';

		$row = $this->get_row( $this->record( [ 'email' => $overlong_email ] ) );

		$this->assertNotNull( $row );
		$this->assertSame( substr( $overlong_email, 0, 191 ), $row['email'] );
	}

	/**
	 * Logging must never break a sync: a broken table returns 0 without
	 * throwing, and reports once per request rather than once per push.
	 */
	public function test_a_failed_write_never_throws_and_is_reported_once_per_request() {
		global $wpdb;
		$reported_codes = [];
		$capture_report = function ( $code ) use ( &$reported_codes ) {
			if ( 'newspack_integrations_push_log_write_failed' === $code ) {
				$reported_codes[] = $code;
			}
		};
		$break_inserts  = function ( $query ) {
			$is_push_log_insert = 0 === stripos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, Push_Log::TABLE_NAME );
			return $is_push_log_insert ? 'INSERT INTO table_that_does_not_exist VALUES (1)' : $query;
		};
		add_action( 'newspack_log', $capture_report );
		add_filter( 'query', $break_inserts );
		$errors_were_suppressed = $wpdb->suppress_errors( true );

		$first_row_id  = $this->record();
		$second_row_id = $this->record( [ 'email' => 'other@example.test' ] );

		$wpdb->suppress_errors( $errors_were_suppressed );
		remove_filter( 'query', $break_inserts );
		remove_action( 'newspack_log', $capture_report );

		$this->assertSame( 0, $first_row_id );
		$this->assertSame( 0, $second_row_id );
		$this->assertCount( 1, $reported_codes, 'A broken table reports once, not once per push.' );
	}

	/**
	 * The lookups the log exists for (by reader email, by account, and the
	 * per-integration lists) each rely on an index. Without one they scan the
	 * whole table on every admin page load.
	 */
	public function test_table_has_the_indexes_the_lookups_rely_on() {
		global $wpdb;
		$index_rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', Push_Log::get_table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$index_names = array_unique( wp_list_pluck( $index_rows, 'Key_name' ) );

		foreach ( [ 'PRIMARY', 'email', 'user_id', 'integration_updated', 'integration_status' ] as $expected_index ) {
			$this->assertContains( $expected_index, $index_names );
		}
	}

	/**
	 * Move a row's last-update time into the past.
	 *
	 * @param int $row_id   The row ID.
	 * @param int $days_ago How many days back.
	 */
	private function age_row( int $row_id, int $days_ago ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Push_Log::get_table_name(),
			[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ) ],
			[ 'id' => $row_id ]
		);
	}

	/**
	 * Most pushes change nothing at the provider. They must not add rows, or
	 * the recurring sync alone would grow the table without bound. Field
	 * order is not a change either.
	 */
	public function test_identical_successful_push_bumps_the_counter_instead_of_adding_a_row() {
		$first_row_id = $this->record();
		$this->age_row( $first_row_id, 2 );
		$aged_update_time = $this->get_row( $first_row_id )['updated_at'];

		$same_data_reordered             = $this->sample_payload();
		$same_data_reordered['metadata'] = array_reverse( $same_data_reordered['metadata'], true );
		$second_row_id                   = $this->record( [ 'payload' => $same_data_reordered ] );

		$row = $this->get_row( $first_row_id );
		$this->assertSame( $first_row_id, $second_row_id );
		$this->assertSame( 1, $this->count_rows() );
		$this->assertEquals( 1, $row['repeat_count'] );
		$this->assertGreaterThan( $aged_update_time, $row['updated_at'], 'A repeat keeps the row alive for retention.' );
	}

	/**
	 * A push that changes a value is the event the log exists to show.
	 */
	public function test_changed_payload_adds_a_row() {
		$this->record();
		$this->record( [ 'payload' => $this->sample_payload( [ 'NP_Total_Paid' => '180' ] ) ] );

		$this->assertSame( 2, $this->count_rows() );
	}

	/**
	 * Last Active changes on every visit. If it counted as a change, every
	 * push by an active reader would add a row. The row still shows the last
	 * value sent. The key is the one an integration really receives: the
	 * prefixed field name, spaces and all.
	 */
	public function test_volatile_fields_do_not_add_rows_and_the_row_shows_the_latest_value() {
		$first_row_id  = $this->record( [ 'payload' => $this->sample_payload( [ 'NP_Last Active' => '2026-09-01 10:00:00' ] ) ] );
		$second_row_id = $this->record( [ 'payload' => $this->sample_payload( [ 'NP_Last Active' => '2026-09-01 10:05:00' ] ) ] );

		$stored_payload = json_decode( $this->get_row( $first_row_id )['payload'], true );
		$this->assertSame( $first_row_id, $second_row_id );
		$this->assertSame( 1, $this->count_rows() );
		$this->assertSame( '2026-09-01 10:05:00', $stored_payload['metadata']['NP_Last Active'] );
	}

	/**
	 * A site that pushes a field of its own that changes on every visit needs
	 * the same exemption, or that field alone would add a row per push.
	 */
	public function test_the_volatile_field_list_is_filterable() {
		$exempt_engagement_score = function ( $volatile_fields ) {
			$volatile_fields[] = 'Engagement Score';
			return $volatile_fields;
		};
		add_filter( 'newspack_integrations_push_log_volatile_fields', $exempt_engagement_score );

		$first_row_id  = $this->record( [ 'payload' => $this->sample_payload( [ 'NP_Engagement Score' => '12' ] ) ] );
		$second_row_id = $this->record( [ 'payload' => $this->sample_payload( [ 'NP_Engagement Score' => '48' ] ) ] );

		remove_filter( 'newspack_integrations_push_log_volatile_fields', $exempt_engagement_score );
		$this->assertSame( $first_row_id, $second_row_id );
		$this->assertSame( 1, $this->count_rows() );
	}

	/**
	 * Previous rows that must not absorb a repeat.
	 *
	 * @return array[]
	 */
	public function rows_that_do_not_absorb_a_repeat(): array {
		return [
			'a failed push'   => [
				[
					'result'      => new \WP_Error( 'provider_down', 'ESP 503' ),
					'error_class' => 'transient',
				],
			],
			'a benign result' => [
				[
					'result'      => new \WP_Error( 'member_exists', 'Member exists' ),
					'error_class' => 'benign',
				],
			],
			'a deletion flag' => [
				[ 'operation' => Push_Log::OPERATION_FLAG ],
			],
		];
	}

	/**
	 * Only a clean successful upsert stands for "this data is at the
	 * provider". Anything else before a success means that success is news.
	 *
	 * @param array $previous_attempt Overrides for the row recorded first.
	 *
	 * @dataProvider rows_that_do_not_absorb_a_repeat
	 */
	public function test_only_a_clean_successful_upsert_absorbs_a_repeat( array $previous_attempt ) {
		$this->record( $previous_attempt );
		$this->record();

		$this->assertSame( 2, $this->count_rows() );
	}

	/**
	 * A retry chain reads as one entry: the retry updates the row its first
	 * attempt wrote, and the row keeps what the earlier attempt hit.
	 */
	public function test_a_retry_updates_its_row_instead_of_adding_one() {
		$row_id = $this->record(
			[
				'result'      => new \WP_Error( 'provider_down', 'ESP 503' ),
				'error_class' => 'transient',
			]
		);
		Push_Log::mark_retrying( $row_id, 4321 );
		$retrying_row = $this->get_row( $row_id );

		$retried_row_id = $this->record(
			[
				'log_id'   => $row_id,
				'attempts' => 2,
			]
		);

		$row = $this->get_row( $row_id );
		$this->assertSame( Push_Log::STATUS_RETRYING, $retrying_row['status'] );
		$this->assertEquals( 4321, $retrying_row['retry_action_id'] );
		$this->assertSame( $row_id, $retried_row_id );
		$this->assertSame( 1, $this->count_rows() );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $row['status'] );
		$this->assertEquals( 2, $row['attempts'] );
		$this->assertNull( $row['retry_action_id'] );
		$this->assertSame( 'ESP 503', $row['error_message'], 'A resolved row still says what the earlier attempt hit.' );
	}

	/**
	 * A row can be pruned, or a retry can predate the log, while its chain is
	 * still running. The attempt is recorded anyway, with its real number.
	 */
	public function test_a_retry_whose_row_is_gone_adds_a_row_with_the_right_attempt_number() {
		$row_id = $this->record(
			[
				'log_id'   => 999999,
				'attempts' => 3,
			]
		);

		$row = $this->get_row( $row_id );
		$this->assertNotSame( 999999, $row_id );
		$this->assertEquals( 3, $row['attempts'] );
	}

	/**
	 * The database reports rows changed, not rows matched, so a retry that
	 * runs twice with the same outcome changes nothing the second time. That
	 * must not read as "the row is gone" and add a second row for one sync.
	 */
	public function test_a_retry_that_changes_nothing_still_lands_on_its_row() {
		$row_id = $this->record( [ 'attempts' => 2 ] );

		$repeated_row_id = $this->record(
			[
				'log_id'   => $row_id,
				'attempts' => 2,
			]
		);

		$this->assertSame( $row_id, $repeated_row_id );
		$this->assertSame( 1, $this->count_rows() );
	}

	/**
	 * Action Scheduler answers 0 when it stored nothing. A row marked
	 * retrying with no retry behind it would wait forever, so it stays
	 * failed.
	 */
	public function test_a_retry_that_was_not_scheduled_leaves_the_row_failed() {
		$row_id = $this->record(
			[
				'result'      => new \WP_Error( 'provider_down', 'ESP 503' ),
				'error_class' => 'transient',
			]
		);

		Push_Log::mark_retrying( $row_id, 0 );

		$this->assertSame( Push_Log::STATUS_FAILED, $this->get_row( $row_id )['status'] );
	}

	/**
	 * A retry that gives up before pushing used to leave no trace. The row
	 * ends as failed, says why, and keeps the error that started the chain.
	 */
	public function test_an_aborted_retry_ends_as_failed_and_keeps_the_last_push_error() {
		$row_id = $this->record(
			[
				'result'      => new \WP_Error( 'provider_down', 'ESP 503' ),
				'error_class' => 'transient',
			]
		);
		Push_Log::mark_retrying( $row_id, 4321 );

		Push_Log::mark_failed( $row_id, 'retry_aborted', 'Outbound sync is paused for this integration.' );

		$row = $this->get_row( $row_id );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertNull( $row['retry_action_id'] );
		$this->assertSame( 'retry_aborted', $row['error_code'] );
		$this->assertSame( 'Outbound sync is paused for this integration. Last error: ESP 503', $row['error_message'] );
		$this->assertSame( 'transient', $row['error_class'] );
	}

	/**
	 * Routine history is short-lived and failures are kept long enough for a
	 * late support question: 14 days for successes, 90 for anything that did
	 * not end well, including a row stuck retrying.
	 */
	public function test_cleanup_removes_successes_after_14_days_and_failures_after_90() {
		$failure = [
			'result'      => new \WP_Error( 'provider_down', 'ESP 503' ),
			'error_class' => 'transient',
		];

		$expired_success  = $this->record( [ 'email' => 'expired-success@example.test' ] );
		$recent_success   = $this->record( [ 'email' => 'recent-success@example.test' ] );
		$expired_failure  = $this->record( array_merge( $failure, [ 'email' => 'expired-failure@example.test' ] ) );
		$recent_failure   = $this->record( array_merge( $failure, [ 'email' => 'recent-failure@example.test' ] ) );
		$expired_retrying = $this->record( array_merge( $failure, [ 'email' => 'expired-retrying@example.test' ] ) );
		Push_Log::mark_retrying( $expired_retrying, 4321 );
		$this->age_row( $expired_success, 15 );
		$this->age_row( $recent_success, 13 );
		$this->age_row( $expired_failure, 91 );
		$this->age_row( $recent_failure, 89 );
		$this->age_row( $expired_retrying, 91 );

		Push_Log::cleanup();

		$this->assertNull( $this->get_row( $expired_success ) );
		$this->assertNotNull( $this->get_row( $recent_success ) );
		$this->assertNull( $this->get_row( $expired_failure ) );
		$this->assertNotNull( $this->get_row( $recent_failure ) );
		$this->assertNull( $this->get_row( $expired_retrying ) );
	}

	/**
	 * The windows are the knob a large site turns to keep the table small.
	 */
	public function test_cleanup_windows_are_filterable() {
		$two_day_old_success = $this->record();
		$this->age_row( $two_day_old_success, 2 );
		$keep_one_day = function ( $retention_days ) {
			$retention_days['success'] = 1;
			return $retention_days;
		};
		add_filter( 'newspack_integrations_push_log_retention_days', $keep_one_day );

		Push_Log::cleanup();

		remove_filter( 'newspack_integrations_push_log_retention_days', $keep_one_day );
		$this->assertNull( $this->get_row( $two_day_old_success ) );
	}

	/**
	 * One run deletes a bounded amount, so a backlog cannot turn the daily
	 * cron into a long table lock. The rest goes on the next run.
	 */
	public function test_cleanup_stops_at_the_batch_cap() {
		foreach ( range( 1, 5 ) as $reader_number ) {
			$this->age_row( $this->record( [ 'email' => "reader-{$reader_number}@example.test" ] ), 30 );
		}

		Push_Log::cleanup( 2, 2 );

		$this->assertSame( 1, $this->count_rows() );
	}

	/**
	 * The cap bounds deleting, not looking. Integrations with nothing to
	 * prune must not use up the run before it reaches one that has a backlog.
	 */
	public function test_cleanup_reaches_a_backlog_behind_integrations_with_nothing_to_prune() {
		foreach ( [ 'aa-first', 'bb-second', 'cc-third' ] as $integration_without_backlog ) {
			$this->record( [ 'integration_id' => $integration_without_backlog ] );
		}
		$expired_row_id = $this->record( [ 'integration_id' => 'zz-backlog' ] );
		$this->age_row( $expired_row_id, 30 );

		Push_Log::cleanup( 1000, 2 );

		$this->assertNull( $this->get_row( $expired_row_id ) );
	}

	/**
	 * An erasure request must not wait for retention, and must reach rows
	 * written under an address the reader has since changed.
	 */
	public function test_erasing_a_reader_removes_their_rows_under_any_address() {
		$reader_id       = $this->factory()->user->create( [ 'user_email' => 'new-address@example.test' ] );
		$under_old_email = $this->record(
			[
				'email'   => 'old-address@example.test',
				'user_id' => $reader_id,
			]
		);
		$under_new_email = $this->record(
			[
				'email'   => 'new-address@example.test',
				'user_id' => $reader_id,
			]
		);
		$someone_elses   = $this->record(
			[
				'email'   => 'someone-else@example.test',
				'user_id' => $reader_id + 1,
			]
		);

		$registered_eraser = apply_filters( 'wp_privacy_personal_data_erasers', [] )['newspack-integrations-push-log'];

		$response = call_user_func( $registered_eraser['callback'], 'new-address@example.test', 1 );

		$this->assertNull( $this->get_row( $under_old_email ) );
		$this->assertNull( $this->get_row( $under_new_email ) );
		$this->assertNotNull( $this->get_row( $someone_elses ) );
		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['done'] );
	}
}
