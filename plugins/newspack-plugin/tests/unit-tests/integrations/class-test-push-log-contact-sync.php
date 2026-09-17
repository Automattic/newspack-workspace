<?php
/**
 * Tests for what Contact_Sync writes to the integrations push log.
 *
 * @package Newspack\Tests\Unit\Integrations
 */

namespace Newspack\Tests\Unit\Integrations;

use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Push_Log;

require_once __DIR__ . '/class-deletion-spy-integration.php';

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
		$this->assertCount( 1, $rows );
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
		$this->assertCount( 1, $rows );
		$this->assertSame( Push_Log::STATUS_SUCCESS, $rows['flag-spy']['status'] );
		$this->assertEquals( 2, $rows['flag-spy']['attempts'] );
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
}
