<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the `wp newspack integrations backfill` pull batch driver (NPPD-2076).
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\RAS_Contact_Sync;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Contact_Pull;
use Newspack\Reader_Data;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-ras-contact-sync.php';
require_once dirname( __DIR__ ) . '/integrations/class-failing-sample-integration.php';
require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mock.php';

/**
 * Batch pull driver: tallies, scoping, dry-run, batching, no AS retries.
 *
 * @group Integrations_Backfill
 */
class Test_RAS_Integrations_Backfill_Pull extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
	}

	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];
		Integrations::disable( 'esp' );
		Failing_Sample_Integration::reset();
		Integrations::register( new Failing_Sample_Integration( 'pull_cli_mock', 'Pull CLI Mock' ) );
		Integrations::enable( 'pull_cli_mock' );
		update_option( 'newspack_integration_incoming_fields_pull_cli_mock', [ 'field_a' => [ 'name' => 'Field A' ] ] );
		Failing_Sample_Integration::$pull_data = [ 'field_a' => 'gold' ];
	}

	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		foreach ( [ 'pull_cli_mock', 'pull_cli_other' ] as $integration_id ) {
			self::delete_integration_options( $integration_id );
		}
		Integrations::disable( 'pull_cli_mock' );
		Integrations::enable( 'esp' );
		Failing_Sample_Integration::reset();
		parent::tear_down();
	}

	/**
	 * Remove every per-integration option these tests write.
	 *
	 * Symmetric with what the tests set: the incoming-fields selection and the
	 * per-direction toggles a paused-integration test flips. WP_UnitTestCase's
	 * transaction rolls these back either way, so this is about the cleanup
	 * being honest about what the class touches rather than about leakage.
	 *
	 * @param string $integration_id The integration id.
	 */
	private static function delete_integration_options( $integration_id ) {
		delete_option( 'newspack_integration_incoming_fields_' . $integration_id );
		delete_option( Integration::SETTINGS_OPTION_PREFIX . $integration_id . '_incoming_sync_enabled' );
		delete_option( Integration::SETTINGS_OPTION_PREFIX . $integration_id . '_outgoing_sync_enabled' );
	}

	/**
	 * Create a verified subscriber-role reader.
	 *
	 * @return int User ID.
	 */
	private function create_reader() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		Reader_Activation::set_reader_verified( $user_id );
		return $user_id;
	}

	/**
	 * Invoke the private static pull_contacts() via reflection.
	 *
	 * @param array $config Pull batch configuration.
	 * @return array|\WP_Error
	 */
	private function run_pull( array $config ) {
		$method = new \ReflectionMethod( RAS_Contact_Sync::class, 'pull_contacts' );
		$method->setAccessible( true );
		return $method->invoke( null, $config );
	}

	public function test_pulls_given_user_ids_and_tallies_processed() {
		$user_a = $this->create_reader();
		$user_b = $this->create_reader();

		$tally = $this->run_pull( [ 'user_ids' => [ $user_a, $user_b ] ] );

		$this->assertSame(
			[
				'processed' => 2,
				'errors'    => 0,
				'skipped'   => 0,
			],
			$tally
		);
		$this->assertSame( 2, Failing_Sample_Integration::$pull_count );
		$this->assertSame( '"gold"', Reader_Data::get_data( $user_a, 'field_a' ) );
	}

	public function test_dry_run_pull_fetches_without_persisting() {
		$user_a = $this->create_reader();

		$tally = $this->run_pull(
			[
				'user_ids'   => [ $user_a ],
				'is_dry_run' => true,
			]
		);

		$this->assertSame( 1, $tally['processed'] );
		$this->assertSame( 1, Failing_Sample_Integration::$pull_count );
		$this->assertFalse( Reader_Data::get_data( $user_a, 'field_a' ) );
	}

	public function test_missing_user_id_is_skipped() {
		$tally = $this->run_pull( [ 'user_ids' => [ 99999999 ] ] );
		$this->assertSame( 1, $tally['skipped'] );
		$this->assertSame( 0, Failing_Sample_Integration::$pull_count );
	}

	public function test_pull_failure_tallies_error_and_schedules_no_retries() {
		Failing_Sample_Integration::$pull_should_fail = true;
		$user_a = $this->create_reader();

		$tally = $this->run_pull( [ 'user_ids' => [ $user_a ] ] );

		$this->assertSame( 1, $tally['errors'] );
		$this->assertSame( 0, $tally['processed'] );
		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Pull::RETRY_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
		);
		$this->assertCount( 0, $pending, 'CLI bulk pulls must not schedule AS retries.' );
	}

	public function test_integration_scoping_limits_pull_targets() {
		Integrations::register( new Failing_Sample_Integration( 'pull_cli_other', 'Pull CLI Other' ) );
		Integrations::enable( 'pull_cli_other' );
		update_option( 'newspack_integration_incoming_fields_pull_cli_other', [ 'field_a' => [ 'name' => 'Field A' ] ] );
		$user_a = $this->create_reader();

		$unscoped_tally = $this->run_pull( [ 'user_ids' => [ $user_a ] ] );
		$unscoped_count = Failing_Sample_Integration::$pull_count;
		$scoped_tally   = $this->run_pull(
			[
				'user_ids'       => [ $user_a ],
				'integration_id' => 'pull_cli_mock',
			]
		);

		delete_option( 'newspack_integration_incoming_fields_pull_cli_other' );
		Integrations::disable( 'pull_cli_other' );

		$this->assertSame( 2, $unscoped_count, 'Unscoped: both integrations pulled.' );
		$this->assertSame( 3, Failing_Sample_Integration::$pull_count, 'Scoped: only one more pull happened.' );
		$this->assertSame( 1, $unscoped_tally['processed'] );
		$this->assertSame( 1, $scoped_tally['processed'] );
	}

	/**
	 * Enabled incoming fields are resolved once per integration for the whole
	 * run — not once per reader — since resolution may hit the provider's API
	 * on legacy-shaped settings (NPPD-2076 review).
	 */
	public function test_fields_resolved_once_per_integration_for_the_run() {
		$user_a = $this->create_reader();
		$user_b = $this->create_reader();
		$user_c = $this->create_reader();
		Failing_Sample_Integration::$enabled_incoming_fields_calls = 0;

		$this->run_pull( [ 'user_ids' => [ $user_a, $user_b, $user_c ] ] );

		$this->assertSame( 1, Failing_Sample_Integration::$enabled_incoming_fields_calls, 'One resolution for the run, regardless of reader count.' );
		$this->assertSame( 3, Failing_Sample_Integration::$pull_count, 'Every reader was still pulled.' );
	}

	public function test_no_pull_targets_returns_wp_error() {
		delete_option( 'newspack_integration_incoming_fields_pull_cli_mock' );
		$user_a = $this->create_reader();

		$result = $this->run_pull( [ 'user_ids' => [ $user_a ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_backfill_no_pull_targets', $result->get_error_code() );
	}

	/**
	 * Pausing the inbound toggle stops the backfill's pull leg too. Every other
	 * pull dispatch site (pull_sync, pull_all, the retry chain) honors
	 * is_pull_enabled(); a bulk driver that ignored it would call providers the
	 * publisher has explicitly paused (#700).
	 */
	public function test_pull_skips_integrations_with_inbound_sync_paused() {
		$integration = Integrations::get_integration( 'pull_cli_mock' );
		$integration->update_settings_field_value( 'incoming_sync_enabled', false );
		$user_a = $this->create_reader();

		$result = $this->run_pull( [ 'user_ids' => [ $user_a ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result, 'The only in-scope integration is paused, so there is nothing to pull.' );
		$this->assertSame( 'newspack_backfill_no_pull_targets', $result->get_error_code() );
		$this->assertSame( 0, Failing_Sample_Integration::$pull_count, 'A paused integration must not be called.' );
	}

	/**
	 * The mixed case: a paused integration is skipped while an enabled sibling
	 * still pulls. More likely to regress silently than the all-paused case,
	 * since the run still succeeds either way — only the call count betrays it
	 * (NPPD-2076 review).
	 */
	public function test_paused_integration_is_skipped_while_enabled_sibling_pulls() {
		Integrations::register( new Failing_Sample_Integration( 'pull_cli_other', 'Pull CLI Other' ) );
		Integrations::enable( 'pull_cli_other' );
		update_option( 'newspack_integration_incoming_fields_pull_cli_other', [ 'field_a' => [ 'name' => 'Field A' ] ] );
		Integrations::get_integration( 'pull_cli_other' )->update_settings_field_value( 'incoming_sync_enabled', false );
		$user_a = $this->create_reader();

		$tally = $this->run_pull( [ 'user_ids' => [ $user_a ] ] );

		delete_option( 'newspack_integration_incoming_fields_pull_cli_other' );
		Integrations::disable( 'pull_cli_other' );

		$this->assertSame( 1, $tally['processed'], 'The enabled sibling still pulls the reader.' );
		$this->assertSame( 0, $tally['errors'] );
		$this->assertSame( 1, Failing_Sample_Integration::$pull_count, 'Exactly one of the two integrations was called.' );
		$this->assertSame( '"gold"', Reader_Data::get_data( $user_a, 'field_a' ) );
	}

	/**
	 * A dry run must tally what a wet run would, including across integrations:
	 * a wet run's earlier writes grow the reader's key list before the next
	 * integration is pulled, so the preview has to carry that pending state or
	 * it under-reports a reader who only crosses the cap once every
	 * integration's fields are counted together (NPPD-2076 review).
	 */
	public function test_dry_run_tally_matches_wet_run_across_integrations() {
		Integrations::register( new Failing_Sample_Integration( 'pull_cli_other', 'Pull CLI Other' ) );
		Integrations::enable( 'pull_cli_other' );
		// Distinct incoming fields, so the two integrations write different keys.
		update_option( 'newspack_integration_incoming_fields_pull_cli_other', [ 'field_b' => [ 'name' => 'Field B' ] ] );
		Failing_Sample_Integration::$pull_data = [
			'field_a' => 'gold',
			'field_b' => 'silver',
		];

		$dry_user = $this->create_reader();
		$wet_user = $this->create_reader();
		// One free slot each: the first integration's key fits, the second's does not.
		foreach ( [ $dry_user, $wet_user ] as $user_id ) {
			for ( $i = 0; $i < Reader_Data::MAX_ITEMS - 1; $i++ ) {
				Reader_Data::update_item( $user_id, "filler_{$i}", '"x"' );
			}
		}

		$dry_tally = $this->run_pull(
			[
				'user_ids'   => [ $dry_user ],
				'is_dry_run' => true,
			]
		);
		$wet_tally = $this->run_pull( [ 'user_ids' => [ $wet_user ] ] );

		delete_option( 'newspack_integration_incoming_fields_pull_cli_other' );
		Integrations::disable( 'pull_cli_other' );

		$this->assertSame( 1, $wet_tally['errors'], 'The second integration really does breach the cap.' );
		$this->assertSame( $wet_tally, $dry_tally, 'The preview must report the same tally as the real run.' );
		$this->assertFalse( Reader_Data::get_data( $dry_user, 'field_a' ), 'The preview still persists nothing.' );
	}

	public function test_active_only_skips_readers_without_active_subscription() {
		$active_user   = $this->create_reader();
		$inactive_user = $this->create_reader();
		wcs_create_subscription(
			[
				'customer_id' => $active_user,
				'status'      => 'active',
			]
		);

		$tally = $this->run_pull(
			[
				'user_ids'    => [ $active_user, $inactive_user ],
				'active_only' => true,
			]
		);

		$this->assertSame(
			[
				'processed' => 1,
				'errors'    => 0,
				'skipped'   => 1,
			],
			$tally
		);
	}

	public function test_all_readers_batching_honors_max_batches() {
		$this->create_reader();
		$this->create_reader();
		$this->create_reader();

		$tally = $this->run_pull(
			[
				'batch_size'  => 1,
				'max_batches' => 2,
			]
		);

		$this->assertSame( 2, $tally['processed'], 'batch_size=1 with max_batches=2 processes exactly 2 readers.' );
	}

	/**
	 * A reader whose Reader_Data writes are rejected must tally as an error,
	 * not processed — the documented "re-run the affected --offset window"
	 * recovery only revisits tallied errors (NPPD-2076).
	 */
	public function test_reader_data_write_failure_tallies_error() {
		$user_a = $this->create_reader();
		// Fill the reader to the data-key cap so the pull's write is rejected.
		for ( $i = 0; $i < Reader_Data::MAX_ITEMS; $i++ ) {
			Reader_Data::update_item( $user_a, "filler_{$i}", '"x"' );
		}

		$tally = $this->run_pull( [ 'user_ids' => [ $user_a ] ] );

		$this->assertSame( 1, $tally['errors'], 'A reader whose writes were rejected is an error, not processed.' );
		$this->assertSame( 0, $tally['processed'] );
		$this->assertSame( 1, Failing_Sample_Integration::$pull_count, 'The fetch still happened.' );
	}

	/**
	 * `--direction=both` through the public command entry point runs push then
	 * pull and joins both summaries into one success line (NPPD-2076).
	 */
	public function test_cli_backfill_direction_both_runs_push_then_pull() {
		WP_CLI::reset();
		$user_id = $this->create_reader();

		RAS_Contact_Sync::cli_backfill(
			[],
			[
				'direction' => 'both',
				'dry-run'   => true,
				'user-ids'  => (string) $user_id,
			]
		);

		$this->assertSame( 1, Failing_Sample_Integration::$pull_count, 'The pull leg ran.' );
		$this->assertSame(
			[ 'Would sync 1 contacts (0 errors, 0 skipped). Would pull 1 contacts (0 errors, 0 skipped).' ],
			WP_CLI::$successes,
			'Push and pull summaries are joined into one success line, push first.'
		);
	}

	/**
	 * A reader the provider has never heard of is not a failure: no re-run can
	 * make an absent contact appear, so tallying them as errors would exit 1 on
	 * exactly the sites a backfill exists for — mirror the push leg's
	 * missing-entity skips instead (NPPD-2076 review).
	 */
	public function test_provider_missing_contacts_tally_as_skipped() {
		Failing_Sample_Integration::$pull_should_fail = true;
		Failing_Sample_Integration::$pull_error_code  = Integration::CONTACT_NOT_FOUND_ERROR_CODE;
		$user_a = $this->create_reader();

		$tally = $this->run_pull( [ 'user_ids' => [ $user_a ] ] );

		$this->assertSame(
			[
				'processed' => 0,
				'errors'    => 0,
				'skipped'   => 1,
			],
			$tally,
			'A provider-missing contact is skipped, not an error.'
		);
	}

	/**
	 * Not-found only means "skip" when every target came up empty: a reader one
	 * integration knows and another does not was still pulled.
	 */
	public function test_reader_found_by_one_integration_is_processed_despite_not_found_elsewhere() {
		// Fresh id: the integrations registry is process-static and keeps the
		// first instance registered under an id, so reusing `pull_cli_other`
		// here would silently run a sibling test's plain mock instead.
		$not_found = new class( 'pull_cli_ghost', 'Pull CLI Ghost' ) extends Failing_Sample_Integration {
			/**
			 * Always report the contact as missing at the provider.
			 *
			 * @param int $user_id WordPress user ID.
			 * @return \WP_Error
			 */
			public function pull_contact_data( $user_id ) {
				return new \WP_Error( Integration::CONTACT_NOT_FOUND_ERROR_CODE, 'Contact not found' );
			}
		};
		Integrations::register( $not_found );
		Integrations::enable( 'pull_cli_ghost' );
		update_option( 'newspack_integration_incoming_fields_pull_cli_ghost', [ 'field_a' => [ 'name' => 'Field A' ] ] );
		$user_a = $this->create_reader();

		$tally = $this->run_pull( [ 'user_ids' => [ $user_a ] ] );

		Integrations::disable( 'pull_cli_ghost' );
		delete_option( 'newspack_integration_incoming_fields_pull_cli_ghost' );

		$this->assertSame(
			[
				'processed' => 1,
				'errors'    => 0,
				'skipped'   => 0,
			],
			$tally,
			'The reader was pulled from the integration that knows them.'
		);
	}

	/**
	 * The whole backfill run — pre-flight included — resolves each
	 * integration's incoming fields once: resolution may hit the provider's
	 * API on legacy-shaped settings (NPPD-2076 review).
	 */
	public function test_backfill_run_resolves_incoming_fields_once_including_preflight() {
		$user_a = $this->create_reader();
		WP_CLI::reset();
		Failing_Sample_Integration::$enabled_incoming_fields_calls = 0;

		RAS_Contact_Sync::cli_backfill(
			[],
			[
				'direction' => 'pull',
				'user-ids'  => (string) $user_a,
			]
		);

		$this->assertSame( 1, Failing_Sample_Integration::$enabled_incoming_fields_calls, 'One resolution for the entire run, pre-flight included.' );
		$this->assertSame( 1, Failing_Sample_Integration::$pull_count, 'The reader was still pulled.' );
	}
}
