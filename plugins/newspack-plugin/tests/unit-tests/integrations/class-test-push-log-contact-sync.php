<?php
/**
 * Tests for what Contact_Sync writes to the integrations push log.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Push_Log;

require_once __DIR__ . '/class-deletion-spy-integration.php';
require_once dirname( __DIR__, 2 ) . '/mocks/newsletters-mocks.php';

/**
 * Push log wiring test case.
 *
 * Contact_Sync owns the retry chains, so it is what tells the log which
 * attempt a push was, whether another is coming, and when a chain gave up.
 *
 * @group integrations
 * @group push-log
 */
class Test_Push_Log_Contact_Sync extends \WP_UnitTestCase {

	/**
	 * Set up the test environment before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Allow sync on the test (non-production) site so Sync::can_sync() does
		// not bail out before anything is pushed.
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		$this->reset_integrations();
		$this->clear_scheduled_retries();
	}

	/**
	 * Tear down the test environment after each test.
	 */
	public function tear_down() {
		$this->clear_scheduled_retries();
		\Newspack_Newsletters_Contacts::reset_calls();
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		delete_option( Integrations::OPTION_NAME );
		$this->reset_integrations();
		Integrations::register_integrations();
		parent::tear_down();
	}

	/**
	 * Reset the static integrations registry so each test starts clean.
	 */
	private function reset_integrations() {
		$reflection = new \ReflectionClass( Integrations::class );
		$property   = $reflection->getProperty( 'integrations' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Unschedule every retry either chain may have left behind.
	 */
	private function clear_scheduled_retries() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( Contact_Sync::RETRY_HOOK );
			\as_unschedule_all_actions( Contact_Sync::RETRY_DELETION_HOOK );
		}
	}

	/**
	 * Skip a test that needs ActionScheduler when it is not loaded.
	 */
	private function require_action_scheduler() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'ActionScheduler not available.' );
		}
	}

	/**
	 * Register and enable a spy integration.
	 *
	 * @param string $integration_id The integration ID.
	 * @return \Deletion_Spy_Integration
	 */
	private function register_spy( string $integration_id ) {
		$spy = new \Deletion_Spy_Integration( $integration_id, 'Spy' );
		Integrations::register( $spy );
		Integrations::enable( $integration_id );
		return $spy;
	}

	/**
	 * Every push log row, keyed by integration ID.
	 *
	 * @return array[]
	 */
	private function get_rows_by_integration(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', Push_Log::get_table_name() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_column( $rows, null, 'integration_id' );
	}

	/**
	 * Count every row in the table.
	 *
	 * Rows keyed by integration cannot show a chain that split into two rows
	 * for one integration, so the chain tests count the table itself.
	 *
	 * @return int
	 */
	private function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Push_Log::get_table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The args of the single pending retry for an integration.
	 *
	 * @param string $hook           The retry hook.
	 * @param string $integration_id The integration ID.
	 * @return array{action_id:int,args:array}
	 */
	private function get_pending_retry( string $hook, string $integration_id ): array {
		$pending = \as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => Integrations::get_action_group( $integration_id ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ARRAY_A'
		);
		$this->assertCount( 1, $pending, 'Exactly one retry is pending.' );
		$action_id = (int) array_key_first( $pending );
		return [
			'action_id' => $action_id,
			'args'      => \ActionScheduler::store()->fetch_action( $action_id )->get_args()[0],
		];
	}

	/**
	 * A sync fans out to every integration, and each row carries the contact
	 * that integration was handed (filtered and prefixed), not the raw input.
	 */
	public function test_a_sync_writes_one_row_per_integration_with_the_contact_it_received() {
		$spies = [
			'first-spy'  => $this->register_spy( 'first-spy' ),
			'second-spy' => $this->register_spy( 'second-spy' ),
		];

		Contact_Sync::sync(
			[
				'email'    => 'reader@example.test',
				'metadata' => [ 'account' => 7 ],
			],
			'Test context'
		);

		$rows = $this->get_rows_by_integration();
		$this->assertCount( 2, $rows );
		foreach ( $spies as $integration_id => $spy ) {
			$row = $rows[ $integration_id ];
			$this->assertSame( Push_Log::STATUS_SUCCESS, $row['status'] );
			$this->assertSame( Push_Log::OPERATION_UPSERT, $row['operation'] );
			$this->assertSame( 'Test context', $row['context'] );
			$this->assertSame( $spy->push_calls[0]['contact'], json_decode( $row['payload'], true ) );
		}
	}

	/**
	 * Last Active changes on every visit, so it must not count as a change.
	 * The key the integration really receives is the prefixed field name
	 * ("NP_Last Active"), not the raw metadata key, and the exclusion has to
	 * match that spelling — otherwise every push by an active reader adds a
	 * row and the collapse that keeps the table small never fires.
	 */
	public function test_a_changed_last_active_alone_does_not_add_a_row() {
		$spy = $this->register_spy( 'last-active-spy' );
		$spy->update_enabled_outgoing_fields( [ 'Last Active' ] );
		$last_active_key = $spy->get_metadata_prefix() . 'Last Active';

		foreach ( [ '2026-09-01 10:00:00', '2026-09-01 10:05:00' ] as $last_active ) {
			Contact_Sync::sync(
				[
					'email'    => 'reader@example.test',
					'metadata' => [ 'Last_Active' => $last_active ],
				],
				'Test context'
			);
		}

		// Assert the field really reached the integration under that key, so
		// the test cannot pass because it was dropped on the way.
		$this->assertSame( '2026-09-01 10:00:00', $spy->push_calls[0]['contact']['metadata'][ $last_active_key ] ?? null );
		$this->assertSame( '2026-09-01 10:05:00', $spy->push_calls[1]['contact']['metadata'][ $last_active_key ] ?? null );
		$this->assertSame( 1, $this->count_rows() );
		$row = $this->get_rows_by_integration()['last-active-spy'];
		$this->assertEquals( 1, $row['repeat_count'] );
		$this->assertSame( '2026-09-01 10:05:00', json_decode( $row['payload'], true )['metadata'][ $last_active_key ] );
	}

	/**
	 * From first failure to resolution a sync is one row: retrying while the
	 * retry is pending, pointing at that retry, and a success on the same row
	 * once the retry lands.
	 */
	public function test_a_failed_sync_is_one_row_from_first_failure_to_resolution() {
		$this->require_action_scheduler();
		$user_id          = $this->factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$spy              = $this->register_spy( 'retry-spy' );
		$spy->push_result = new \WP_Error( 'provider_down', 'Provider down' );

		Contact_Sync::sync(
			[
				'email'    => 'reader@example.test',
				'metadata' => [],
			],
			'Test context'
		);

		$retrying_row = $this->get_rows_by_integration()['retry-spy'];
		$retry        = $this->get_pending_retry( Contact_Sync::RETRY_HOOK, 'retry-spy' );
		$this->assertSame( Push_Log::STATUS_RETRYING, $retrying_row['status'] );
		$this->assertEquals( $user_id, $retrying_row['user_id'] );
		$this->assertEquals( $retry['action_id'], $retrying_row['retry_action_id'] );
		$this->assertEquals( Contact_Sync::MAX_RETRIES + 1, $retrying_row['max_attempts'] );
		$this->assertEquals( $retrying_row['id'], $retry['args']['log_id'], 'The retry carries the row it belongs to.' );

		$spy->push_result = true;
		Contact_Sync::execute_integration_retry( $retry['args'] );

		$rows = $this->get_rows_by_integration();
		$this->assertSame( 1, $this->count_rows() );
		$this->assertSame( $retrying_row['id'], $rows['retry-spy']['id'] );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $rows['retry-spy']['status'] );
		$this->assertEquals( 2, $rows['retry-spy']['attempts'] );
	}

	/**
	 * A retry that finds outbound sync paused gives up without pushing. The
	 * row must say so instead of waiting forever.
	 */
	public function test_a_retry_that_finds_outbound_sync_paused_ends_the_row_as_failed() {
		$this->require_action_scheduler();
		$this->factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$spy              = $this->register_spy( 'paused-spy' );
		$spy->push_result = new \WP_Error( 'provider_down', 'Provider down' );
		Contact_Sync::sync(
			[
				'email'    => 'reader@example.test',
				'metadata' => [],
			],
			'Test context'
		);
		$retry = $this->get_pending_retry( Contact_Sync::RETRY_HOOK, 'paused-spy' );

		$spy->update_settings_field_value( 'outgoing_sync_enabled', false );
		Contact_Sync::execute_integration_retry( $retry['args'] );

		$row = $this->get_rows_by_integration()['paused-spy'];
		$this->assertCount( 1, $spy->push_calls, 'The paused retry does not push.' );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'retry_aborted', $row['error_code'] );
		$this->assertStringContainsString( 'paused', $row['error_message'] );
		$this->assertStringContainsString( 'Last error: Provider down', $row['error_message'] );
	}

	/**
	 * Disabling the card on Audience → Integrations must also stop the retries
	 * queued while it was enabled. The card stays registered with outbound
	 * sync on, so neither of the guards above notices.
	 */
	public function test_a_retry_that_finds_the_integration_disabled_ends_the_row_as_failed() {
		$this->require_action_scheduler();
		$this->factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$spy              = $this->register_spy( 'disabled-spy' );
		$spy->push_result = new \WP_Error( 'provider_down', 'Provider down' );
		Contact_Sync::sync(
			[
				'email'    => 'reader@example.test',
				'metadata' => [],
			],
			'Test context'
		);
		$retry = $this->get_pending_retry( Contact_Sync::RETRY_HOOK, 'disabled-spy' );

		Integrations::disable( 'disabled-spy' );
		Contact_Sync::execute_integration_retry( $retry['args'] );

		$row = $this->get_rows_by_integration()['disabled-spy'];
		$this->assertCount( 1, $spy->push_calls, 'The retry of a disabled integration does not push.' );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'retry_aborted', $row['error_code'] );
		$this->assertStringContainsString( 'disabled', $row['error_message'] );
		$this->assertStringContainsString( 'Last error: Provider down', $row['error_message'] );
	}

	/**
	 * An email change pushes the new address while asking the provider to
	 * match the old one. The row records which address was matched against,
	 * so a support question about a reader whose CRM record moved can be
	 * answered from the log.
	 */
	public function test_an_email_change_records_the_address_it_pushed_against() {
		$this->register_spy( 'email-change-spy' );

		Contact_Sync::sync(
			[
				'email'    => 'new-address@example.test',
				'metadata' => [],
			],
			'Test context',
			[ 'email' => 'old-address@example.test' ]
		);

		$row = $this->get_rows_by_integration()['email-change-spy'];
		$this->assertSame( 'old-address@example.test', json_decode( $row['payload'], true )['previous_email'] );
	}

	/**
	 * A reader deleted between the failure and the retry can never be rebuilt,
	 * so the chain ends without pushing. The row must say so: left retrying it
	 * would promise an attempt that is never coming.
	 */
	public function test_a_retry_whose_reader_was_deleted_ends_the_row_as_failed() {
		$this->require_action_scheduler();
		$reader_id        = $this->factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$spy              = $this->register_spy( 'deleted-reader-spy' );
		$spy->push_result = new \WP_Error( 'provider_down', 'Provider down' );
		Contact_Sync::sync(
			[
				'email'    => 'reader@example.test',
				'metadata' => [],
			],
			'Test context'
		);
		$retry = $this->get_pending_retry( Contact_Sync::RETRY_HOOK, 'deleted-reader-spy' );

		\wp_delete_user( $reader_id );
		Contact_Sync::execute_integration_retry( $retry['args'] );

		$row = $this->get_rows_by_integration()['deleted-reader-spy'];
		$this->assertCount( 1, $spy->push_calls, 'The retry for a deleted reader does not push.' );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'retry_aborted', $row['error_code'] );
		$this->assertStringContainsString( 'Last error: Provider down', $row['error_message'] );
	}

	/**
	 * The log only observes. A table that cannot be written must not change
	 * what a sync returns or whether it retries; the retry then carries
	 * log_id 0 and starts a fresh row rather than updating one never written.
	 */
	public function test_a_broken_push_log_changes_neither_the_sync_result_nor_its_retry() {
		$this->require_action_scheduler();
		$this->factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$spy              = $this->register_spy( 'unlogged-spy' );
		$spy->push_result = new \WP_Error( 'provider_down', 'Provider down' );
		$break_inserts    = function ( $query ) {
			$is_push_log_insert = 0 === stripos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, Push_Log::TABLE_NAME );
			return $is_push_log_insert ? 'INSERT INTO table_that_does_not_exist VALUES (1)' : $query;
		};
		add_filter( 'query', $break_inserts );

		$sync_result = Contact_Sync::sync(
			[
				'email'    => 'reader@example.test',
				'metadata' => [],
			],
			'Test context'
		);

		remove_filter( 'query', $break_inserts );
		$retry = $this->get_pending_retry( Contact_Sync::RETRY_HOOK, 'unlogged-spy' );
		$this->assertInstanceOf( \WP_Error::class, $sync_result );
		$this->assertSame( 0, $this->count_rows() );
		$this->assertSame( 0, $retry['args']['log_id'] );
	}

	/**
	 * Failures that cannot be retried.
	 *
	 * @return array[]
	 */
	public function failures_that_cannot_retry(): array {
		return [
			'a CLI push scoped with skip_lists' => [ true, [ 'skip_lists' => true ] ],
			'a contact without an account'      => [ false, [] ],
		];
	}

	/**
	 * A failure nothing will retry is final straight away, and says it had a
	 * single attempt, so the log never promises a retry that is not coming.
	 *
	 * @param bool  $reader_has_account Whether a WP user exists for the contact.
	 * @param array $sync_options       The sync options.
	 *
	 * @dataProvider failures_that_cannot_retry
	 */
	public function test_a_failure_that_cannot_retry_is_final( bool $reader_has_account, array $sync_options ) {
		$this->require_action_scheduler();
		if ( $reader_has_account ) {
			$this->factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		}
		$spy              = $this->register_spy( 'final-spy' );
		$spy->push_result = new \WP_Error( 'provider_down', 'Provider down' );

		Contact_Sync::sync(
			[
				'email'    => 'reader@example.test',
				'metadata' => [],
			],
			'Test context',
			null,
			$sync_options
		);

		$row = $this->get_rows_by_integration()['final-spy'];
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertEquals( 1, $row['max_attempts'] );
		$this->assertNull( $row['retry_action_id'] );
	}

	/**
	 * Whether a retry is pending on a hook.
	 *
	 * @param string $hook The retry hook.
	 * @return bool
	 */
	private function has_pending_retry( string $hook ): bool {
		return ! empty(
			\as_get_scheduled_actions(
				[
					'hook'   => $hook,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				],
				'ids'
			)
		);
	}

	/**
	 * A provider can accept a push and still leave fields out, as Mailchimp
	 * does for fields it cannot create in an audience at its merge field cap.
	 * A retry would hit the same limit, so the sync stays a success with
	 * nothing scheduled, and the row is what tells the publisher.
	 */
	public function test_a_push_saved_without_some_fields_needs_attention_and_is_not_retried() {
		$this->require_action_scheduler();
		$this->factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$spy              = $this->register_spy( 'unsynced-spy' );
		$spy->push_result = [ 'unsynced_fields' => [ 'NP_Account_Deleted', 'NP_Membership_Status' ] ];

		$sync_result = Contact_Sync::sync(
			[
				'email'    => 'reader@example.test',
				'metadata' => [],
			],
			'Test context'
		);

		$row = $this->get_rows_by_integration()['unsynced-spy'];
		$this->assertTrue( $sync_result );
		$this->assertFalse( $this->has_pending_retry( Contact_Sync::RETRY_HOOK ) );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'permanent_config', $row['error_class'] );
		$this->assertSame( 'fields_not_synced', $row['error_code'] );
		$this->assertStringContainsString( 'NP_Account_Deleted, NP_Membership_Status', $row['error_message'] );
	}

	/**
	 * Push a reader through a spy once per result, in order.
	 *
	 * @param string $integration_id The integration ID.
	 * @param array  $push_results   What each push returns, in order.
	 */
	private function sync_reader_with_results( string $integration_id, array $push_results ) {
		$spy = $this->register_spy( $integration_id );
		foreach ( $push_results as $push_result ) {
			$spy->push_result = $push_result;
			Contact_Sync::sync(
				[
					'email'    => 'reader@example.test',
					'metadata' => [],
				],
				'Test context'
			);
		}
	}

	/**
	 * While an audience stays at the cap, every push of the same data is
	 * saved without the same fields. Each one is the same open problem, so it
	 * stays one row instead of growing the log by a failed row per push.
	 */
	public function test_a_repeated_partial_push_folds_into_the_readers_latest_partial_row() {
		$partial = [ 'unsynced_fields' => [ 'NP_Membership_Status' ] ];
		$this->sync_reader_with_results( 'repeat-partial-spy', [ $partial, $partial ] );

		$this->assertSame( 1, $this->count_rows() );
		$row = $this->get_rows_by_integration()['repeat-partial-spy'];
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'fields_not_synced', $row['error_code'] );
		$this->assertEquals( 1, $row['repeat_count'] );
	}

	/**
	 * A partial push that left out other fields is a different problem, so it
	 * adds a row naming those fields instead of folding into the earlier one.
	 */
	public function test_a_partial_push_missing_other_fields_adds_its_own_row() {
		$this->sync_reader_with_results(
			'other-partial-spy',
			[
				[ 'unsynced_fields' => [ 'NP_Membership_Status' ] ],
				[ 'unsynced_fields' => [ 'NP_Account_Deleted' ] ],
			]
		);

		$this->assertSame( 2, $this->count_rows() );
		$this->assertStringContainsString( 'NP_Account_Deleted', $this->get_rows_by_integration()['other-partial-spy']['error_message'] );
	}

	/**
	 * A partial push after a full one is a new problem, so it must not fold
	 * into the success row and hide there.
	 */
	public function test_a_partial_push_after_a_full_one_adds_its_own_row() {
		$this->sync_reader_with_results( 'partial-after-full-spy', [ true, [ 'unsynced_fields' => [ 'NP_Membership_Status' ] ] ] );

		$this->assertSame( 2, $this->count_rows() );
		$this->assertSame( Push_Log::STATUS_FAILED, $this->get_rows_by_integration()['partial-after-full-spy']['status'] );
	}

	/**
	 * Register a spy configured for account-deletion sync.
	 *
	 * @param string $integration_id The integration ID.
	 * @param string $handling       'delete' or 'flag'.
	 * @return \Deletion_Spy_Integration
	 */
	private function register_deletion_spy( string $integration_id, string $handling ) {
		$spy = new \Deletion_Spy_Integration( $integration_id, 'Spy' );
		Integrations::register( $spy );
		$spy->update_settings_field_value( 'sync_account_deletion', true );
		$spy->update_settings_field_value( 'account_deletion_handling', $handling );
		Integrations::enable( $integration_id );
		return $spy;
	}

	/**
	 * Propagate the deletion of the sample reader.
	 */
	private function delete_sample_reader() {
		Contact_Sync::handle_account_deletion(
			'reader@example.test',
			[
				'email'    => 'reader@example.test',
				'metadata' => [],
			],
			'Test deletion'
		);
	}

	/**
	 * A deleted reader's row is found by email alone: the account is gone by
	 * the time the deletion is pushed, and a hard delete sends no contact.
	 */
	public function test_a_hard_delete_is_recorded_by_email_without_a_payload() {
		$this->register_deletion_spy( 'delete-spy', 'delete' );

		$this->delete_sample_reader();

		$row = $this->get_rows_by_integration()['delete-spy'];
		$this->assertSame( Push_Log::OPERATION_DELETE, $row['operation'] );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $row['status'] );
		$this->assertSame( 'reader@example.test', $row['email'] );
		$this->assertEquals( 0, $row['user_id'] );
		$this->assertNull( $row['payload'] );
	}

	/**
	 * Mailchimp answers a hard delete of a reader it has no member for with
	 * its own "Contact not found". The reader is already gone there, and no
	 * retry can change that, so the row is done and nothing is retried.
	 */
	public function test_a_hard_delete_of_a_reader_mailchimp_does_not_hold_is_final() {
		$this->require_action_scheduler();
		\Newspack_Newsletters_Contacts::$next_return = new \WP_Error( 'newspack_newsletters_mailchimp_contact_not_found', 'Contact not found' );
		\update_option( 'newspack_newsletters_service_provider', 'mailchimp' );
		\update_option( 'newspack_integration_settings_esp_mailchimp_audience_id', 'list-abc' );
		$esp = new \Newspack\Reader_Activation\Integrations\ESP();
		Integrations::register( $esp );
		$esp->update_settings_field_value( 'sync_account_deletion', true );
		$esp->update_settings_field_value( 'account_deletion_handling', 'delete' );
		Integrations::enable( 'esp' );

		$this->delete_sample_reader();

		$delete_calls = \Newspack_Newsletters_Contacts::$delete_calls;

		$row     = $this->get_rows_by_integration()['esp'];
		$pending = \as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_DELETION_HOOK,
				'group'  => Integrations::get_action_group( 'esp' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
		);
		$this->assertCount( 1, $delete_calls, 'The delete reached Mailchimp.' );
		$this->assertEmpty( $pending, 'Nothing is retried.' );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $row['status'] );
		$this->assertSame( 'benign', $row['error_class'] );
	}

	/**
	 * A deletion flag that fails is retried on the same row, and the row
	 * shows the flag that was pushed.
	 */
	public function test_a_failed_deletion_flag_is_one_row_from_first_failure_to_resolution() {
		$this->require_action_scheduler();
		$spy              = $this->register_deletion_spy( 'flag-spy', 'flag' );
		$spy->push_result = new \WP_Error( 'provider_down', 'Provider down' );

		$this->delete_sample_reader();

		$retrying_row = $this->get_rows_by_integration()['flag-spy'];
		$retry        = $this->get_pending_retry( Contact_Sync::RETRY_DELETION_HOOK, 'flag-spy' );
		$this->assertSame( Push_Log::OPERATION_FLAG, $retrying_row['operation'] );
		$this->assertSame( Push_Log::STATUS_RETRYING, $retrying_row['status'] );
		$this->assertEquals( $retrying_row['id'], $retry['args']['log_id'], 'The retry carries the row it belongs to.' );
		$this->assertArrayHasKey( $spy->get_metadata_prefix() . 'Account_Deleted', json_decode( $retrying_row['payload'], true )['metadata'] );

		$spy->push_result = true;
		Contact_Sync::execute_deletion_retry( $retry['args'] );

		$rows = $this->get_rows_by_integration();
		$this->assertSame( 1, $this->count_rows() );
		$this->assertSame( $retrying_row['id'], $rows['flag-spy']['id'] );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $rows['flag-spy']['status'] );
		$this->assertEquals( 2, $rows['flag-spy']['attempts'] );
	}

	/**
	 * A cleanup failure alongside a failed push must not overwrite the push's
	 * own error: the row is still retrying, and the retry runs the cleanup
	 * again. Overwriting would read as a chain that ended.
	 */
	public function test_a_failed_flag_push_keeps_its_own_error_when_the_cleanup_also_fails() {
		$this->require_action_scheduler();
		$spy                 = $this->register_deletion_spy( 'both-failed-spy', 'flag' );
		$spy->push_result    = new \WP_Error( 'provider_down', 'Provider down' );
		$spy->cleanup_result = new \WP_Error( 'lists_down', 'Lists API down' );

		$this->delete_sample_reader();

		$row = $this->get_rows_by_integration()['both-failed-spy'];
		$this->assertSame( Push_Log::STATUS_RETRYING, $row['status'] );
		$this->assertSame( 'provider_down', $row['error_code'] );
		$this->assertStringContainsString( 'Provider down', $row['error_message'] );
	}

	/**
	 * Flag mode is two steps: push the flag, then stop outreach. When the
	 * second fails the deleted reader is still on the lists, so the row must
	 * not read as done.
	 */
	public function test_a_deletion_flag_whose_list_cleanup_fails_is_recorded_as_failed() {
		$spy                 = $this->register_deletion_spy( 'cleanup-spy', 'flag' );
		$spy->cleanup_result = new \WP_Error( 'lists_down', 'Lists API down' );

		$this->delete_sample_reader();

		$row = $this->get_rows_by_integration()['cleanup-spy'];
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'flag_cleanup_failed', $row['error_code'] );
		$this->assertStringContainsString( 'Lists API down', $row['error_message'] );
	}

	/**
	 * A reader the integration holds no contact for has nothing to flag, and
	 * no retry can make the contact appear. The row is done, nothing is
	 * retried, and the cleanup still runs.
	 */
	public function test_a_deletion_flag_for_a_reader_without_a_contact_is_final() {
		$this->require_action_scheduler();
		$spy              = $this->register_deletion_spy( 'missing-spy', 'flag' );
		$spy->push_result = new \WP_Error( Integration::CONTACT_NOT_FOUND_ERROR_CODE, 'The reader has no live member in the configured Mailchimp audience.' );

		$this->delete_sample_reader();

		$row     = $this->get_rows_by_integration()['missing-spy'];
		$pending = \as_get_scheduled_actions(
			[
				'hook'   => Contact_Sync::RETRY_DELETION_HOOK,
				'group'  => Integrations::get_action_group( 'missing-spy' ),
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
		);
		$this->assertEmpty( $pending, 'Nothing is retried.' );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $row['status'] );
		$this->assertSame( 'benign', $row['error_class'] );
		$this->assertSame( Integration::CONTACT_NOT_FOUND_ERROR_CODE, $row['error_code'], 'The row keeps what the integration answered.' );
		$this->assertCount( 1, $spy->cleanup_calls, 'The cleanup still runs.' );
	}

	/**
	 * A not-found answer that comes with another failure is that failure,
	 * not a finished deletion, so it is retried.
	 */
	public function test_a_deletion_flag_whose_missing_contact_comes_with_another_failure_is_retried() {
		$this->require_action_scheduler();
		$spy              = $this->register_deletion_spy( 'mixed-spy', 'flag' );
		$spy->push_result = new \WP_Error( Integration::CONTACT_NOT_FOUND_ERROR_CODE, 'No contact to update.' );
		$spy->push_result->add( 'provider_down', 'Provider down' );

		$this->delete_sample_reader();

		$this->get_pending_retry( Contact_Sync::RETRY_DELETION_HOOK, 'mixed-spy' );
		$this->assertSame( Push_Log::STATUS_RETRYING, $this->get_rows_by_integration()['mixed-spy']['status'] );
	}

	/**
	 * A provider that answers the flag push with "already deleted" ends the
	 * chain as a success with no retry behind it. A failed cleanup is then the
	 * only thing left that can say the reader is still on a list.
	 */
	public function test_an_already_deleted_contact_whose_list_cleanup_fails_is_recorded_as_failed() {
		$spy                 = $this->register_deletion_spy( 'gone-cleanup-spy', 'flag' );
		$spy->push_result    = new \WP_Error( 'gone', 'reader@example.test was permanently deleted and cannot be re-imported.' );
		$spy->cleanup_result = new \WP_Error( 'lists_down', 'Lists API down' );

		$this->delete_sample_reader();

		$row = $this->get_rows_by_integration()['gone-cleanup-spy'];
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'flag_cleanup_failed', $row['error_code'] );
		$this->assertStringContainsString( 'Lists API down', $row['error_message'] );
		$this->assertStringContainsString( 'permanently deleted', $row['error_message'], 'The row keeps what the provider answered.' );
	}

	/**
	 * A flag push that fails and is retried leaves its cleanup failure off the
	 * row, because the retry runs the cleanup again. A retry that hears the
	 * contact is already gone still ends the chain, so it must run the cleanup
	 * too, or nothing records that a list may still hold the reader.
	 */
	public function test_a_deletion_retry_that_finds_the_contact_gone_still_records_a_failed_list_cleanup() {
		$this->require_action_scheduler();
		$spy                 = $this->register_deletion_spy( 'gone-on-retry-spy', 'flag' );
		$spy->push_result    = new \WP_Error( 'provider_down', 'Provider down' );
		$spy->cleanup_result = new \WP_Error( 'lists_down', 'Lists API down' );
		$this->delete_sample_reader();
		$retry = $this->get_pending_retry( Contact_Sync::RETRY_DELETION_HOOK, 'gone-on-retry-spy' );

		$spy->push_result = new \WP_Error( 'gone', 'reader@example.test was permanently deleted and cannot be re-imported.' );
		Contact_Sync::execute_deletion_retry( $retry['args'] );

		$row = $this->get_rows_by_integration()['gone-on-retry-spy'];
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'flag_cleanup_failed', $row['error_code'] );
		$this->assertStringContainsString( 'Lists API down', $row['error_message'] );
		$this->assertStringContainsString( 'permanently deleted', $row['error_message'], 'The row keeps what the provider answered.' );
	}

	/**
	 * How a deletion retry chain can end on a failure.
	 *
	 * @return array[]
	 */
	public function failures_that_end_a_deletion_chain(): array {
		return [
			'a permanent error'                 => [ 'reader@example.test looks fake or invalid', 1 ],
			'a transient error on the last try' => [ 'Provider down', Contact_Sync::MAX_RETRIES ],
		];
	}

	/**
	 * The first attempt leaves a failed cleanup off the row for the retry to
	 * settle, so the retry that ends the chain runs the cleanup again even when
	 * its own push fails. The row keeps the push's error: that push never
	 * arrived, and a cleanup code would read as one that did.
	 *
	 * @dataProvider failures_that_end_a_deletion_chain
	 *
	 * @param string $last_error  What the last push answers.
	 * @param int    $retry_count The retry that answers it.
	 */
	public function test_a_deletion_chain_that_ends_on_a_failure_still_reruns_the_list_cleanup( string $last_error, int $retry_count ) {
		$this->require_action_scheduler();
		$spy                 = $this->register_deletion_spy( 'failed-chain-spy', 'flag' );
		$spy->push_result    = new \WP_Error( 'provider_down', 'Provider down' );
		$spy->cleanup_result = new \WP_Error( 'lists_down', 'Lists API down' );
		$this->delete_sample_reader();
		$retry = $this->get_pending_retry( Contact_Sync::RETRY_DELETION_HOOK, 'failed-chain-spy' );

		$spy->push_result = new \WP_Error( 'last_push_failed', $last_error );
		try {
			Contact_Sync::execute_deletion_retry( array_merge( $retry['args'], [ 'retry_count' => $retry_count ] ) );
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The last retry throws so Action Scheduler marks it failed.
		}

		$row = $this->get_rows_by_integration()['failed-chain-spy'];
		$this->assertCount( 2, $spy->cleanup_calls, 'The retry that ends the chain runs the cleanup again.' );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'last_push_failed', $row['error_code'], 'The row keeps the push error.' );
	}

	/**
	 * The deletion flag's fields are created on first use, so they are the
	 * ones a full audience refuses. A flag saved without them must not read
	 * as done, and retrying it would meet the same limit.
	 */
	public function test_a_deletion_flag_saved_without_its_fields_is_recorded_as_failed_and_not_retried() {
		$this->require_action_scheduler();
		$spy              = $this->register_deletion_spy( 'unsynced-flag-spy', 'flag' );
		$spy->push_result = [ 'unsynced_fields' => [ 'NP_Account_Deleted' ] ];

		$this->delete_sample_reader();

		$row = $this->get_rows_by_integration()['unsynced-flag-spy'];
		$this->assertFalse( $this->has_pending_retry( Contact_Sync::RETRY_DELETION_HOOK ) );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'fields_not_synced', $row['error_code'] );
		$this->assertStringContainsString( 'NP_Account_Deleted', $row['error_message'] );
	}

	/**
	 * Collect the payloads of `newspack_sync_permanent_failure` fired from now on.
	 *
	 * @return \ArrayObject The payloads, filled in as the hook fires.
	 */
	private function capture_permanent_failures(): \ArrayObject {
		$payloads = new \ArrayObject();
		add_action(
			'newspack_sync_permanent_failure',
			function ( $payload ) use ( $payloads ) {
				$payloads[] = $payload;
			}
		);
		return $payloads;
	}

	/**
	 * A flag saved without its deletion fields reports a config failure: it
	 * is not retried, so the alert is the only thing that names the gap
	 * once the row ages out. A retry that lands the same way reports it too.
	 *
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $on_retry Whether the partial save answers a retry rather than the first attempt.
	 */
	public function test_a_deletion_flag_saved_without_its_fields_reports_a_config_failure( bool $on_retry ) {
		$this->require_action_scheduler();
		$spy              = $this->register_deletion_spy( 'unsynced-alert-spy', 'flag' );
		$partial_save     = [ 'unsynced_fields' => [ 'NP_Account_Deleted', 'NP_Membership_Status' ] ];
		$spy->push_result = $on_retry ? new \WP_Error( 'provider_down', 'Provider down' ) : $partial_save;
		$failures         = $this->capture_permanent_failures();

		$this->delete_sample_reader();
		if ( $on_retry ) {
			$retry            = $this->get_pending_retry( Contact_Sync::RETRY_DELETION_HOOK, 'unsynced-alert-spy' );
			$spy->push_result = $partial_save;
			Contact_Sync::execute_deletion_retry( $retry['args'] );
		}

		$this->assertCount( 1, $failures );
		$this->assertSame( 'unsynced-alert-spy', $failures[0]['integration_id'] );
		$this->assertSame( 'reader@example.test', $failures[0]['email'] );
		$this->assertSame( 'flag', $failures[0]['mode'] );
		$this->assertSame( 'permanent_config', $failures[0]['error_class'] );
		$this->assertStringContainsString( 'NP_Account_Deleted, NP_Membership_Status', $failures[0]['reason'] );
	}

	/**
	 * A flag saved with all its fields reports nothing.
	 */
	public function test_a_complete_deletion_flag_reports_no_failure() {
		$this->register_deletion_spy( 'complete-flag-spy', 'flag' );
		$failures = $this->capture_permanent_failures();

		$this->delete_sample_reader();

		$this->assertCount( 0, $failures );
	}

	/**
	 * A deletion refused because the ESP account is disabled or unpaid
	 * retries through the backoff, so it reaches the provider once the
	 * account works again. Nothing re-sends a deletion that ends here.
	 *
	 * @testWith ["API Access has been disabled for this account."]
	 *           ["Payment Required"]
	 *
	 * @param string $refusal What the provider answers the first attempt.
	 */
	public function test_a_deletion_refused_for_an_account_problem_reaches_the_provider_once_fixed( string $refusal ) {
		$this->require_action_scheduler();
		$spy                = $this->register_deletion_spy( 'lapsed-account-spy', 'delete' );
		$spy->delete_result = new \WP_Error( 'esp_error', $refusal );
		$failures           = $this->capture_permanent_failures();

		$this->delete_sample_reader();
		$retry              = $this->get_pending_retry( Contact_Sync::RETRY_DELETION_HOOK, 'lapsed-account-spy' );
		$spy->delete_result = true;
		Contact_Sync::execute_deletion_retry( $retry['args'] );

		$row = $this->get_rows_by_integration()['lapsed-account-spy'];
		$this->assertCount( 0, $failures, 'The refusal is not reported as a permanent failure.' );
		$this->assertCount( 2, $spy->delete_calls );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $row['status'] );
	}

	/**
	 * A deletion retry that gives up is a reader left undeleted at the
	 * provider. It has no natural re-trigger, so the row must not stay
	 * "retrying".
	 */
	public function test_a_deletion_retry_that_gives_up_ends_the_row_as_failed() {
		$this->require_action_scheduler();
		$spy                = $this->register_deletion_spy( 'gone-spy', 'delete' );
		$spy->delete_result = new \WP_Error( 'provider_down', 'Provider down' );
		$this->delete_sample_reader();
		$retry = $this->get_pending_retry( Contact_Sync::RETRY_DELETION_HOOK, 'gone-spy' );

		$spy->is_set_up = false;
		Contact_Sync::execute_deletion_retry( $retry['args'] );

		$row = $this->get_rows_by_integration()['gone-spy'];
		$this->assertCount( 1, $spy->delete_calls, 'The abandoned retry does not call the provider.' );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'retry_aborted', $row['error_code'] );
		$this->assertStringContainsString( 'Last error: Provider down', $row['error_message'] );
	}

	/**
	 * A deletion retry queued before the card was disabled must not reach the
	 * provider either: the card stays registered with outbound sync on.
	 */
	public function test_a_deletion_retry_that_finds_the_integration_disabled_ends_the_row_as_failed() {
		$this->require_action_scheduler();
		$spy                = $this->register_deletion_spy( 'disabled-gone-spy', 'delete' );
		$spy->delete_result = new \WP_Error( 'provider_down', 'Provider down' );
		$this->delete_sample_reader();
		$retry = $this->get_pending_retry( Contact_Sync::RETRY_DELETION_HOOK, 'disabled-gone-spy' );

		Integrations::disable( 'disabled-gone-spy' );
		Contact_Sync::execute_deletion_retry( $retry['args'] );

		$row = $this->get_rows_by_integration()['disabled-gone-spy'];
		$this->assertCount( 1, $spy->delete_calls, 'The retry of a disabled integration does not call the provider.' );
		$this->assertSame( Push_Log::STATUS_FAILED, $row['status'] );
		$this->assertSame( 'retry_aborted', $row['error_code'] );
		$this->assertStringContainsString( 'disabled', $row['error_message'] );
		$this->assertStringContainsString( 'Last error: Provider down', $row['error_message'] );
	}
}
