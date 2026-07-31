<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the `wp newspack integrations backfill` pre-flight parser (NPPD-2076).
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\RAS_Contact_Sync;
use Newspack\Reader_Activation\Integration;
use Newspack\Reader_Activation\Integrations;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-ras-contact-sync.php';
require_once dirname( __DIR__ ) . '/integrations/class-failing-sample-integration.php';
require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mock.php';

/**
 * Pre-flight parsing of --direction / --integration and push-only flag rejection.
 *
 * @group Integrations_Backfill
 */
class Test_RAS_Integrations_Backfill_Options extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Integrations::disable( 'esp' );
		Failing_Sample_Integration::reset();
		Integrations::register( new Failing_Sample_Integration( 'backfill_mock', 'Backfill Mock' ) );
		Integrations::enable( 'backfill_mock' );
		// Give the mock an enabled incoming field so pull directions pass the
		// pull-target pre-flight; tests for the no-target path delete this.
		update_option( 'newspack_integration_incoming_fields_backfill_mock', [ 'field_a' => [ 'name' => 'Field A' ] ] );
	}

	public function tear_down() {
		foreach ( [ 'backfill_mock', 'backfill_other' ] as $integration_id ) {
			self::delete_integration_options( $integration_id );
		}
		Integrations::disable( 'backfill_mock' );
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
	 * Invoke the private static parse_backfill_options() via reflection.
	 *
	 * @param array $assoc_args Associative CLI args.
	 * @return array|\WP_Error
	 */
	private function parse( array $assoc_args ) {
		$method = new \ReflectionMethod( RAS_Contact_Sync::class, 'parse_backfill_options' );
		$method->setAccessible( true );
		return $method->invoke( null, $assoc_args );
	}

	public function test_defaults_to_push_and_all_integrations() {
		$this->assertSame(
			[
				'direction'                => 'push',
				'integration_id'           => null,
				// Empty on a push-only parse: only a pull direction's pre-flight
				// resolves incoming fields to thread into the run.
				'resolved_incoming_fields' => [],
			],
			$this->parse( [] )
		);
	}

	public function test_accepts_each_valid_direction() {
		foreach ( [ 'push', 'pull', 'both' ] as $direction ) {
			$parsed = $this->parse( [ 'direction' => $direction ] );
			$this->assertIsArray( $parsed, "Direction '$direction' must parse." );
			$this->assertSame( $direction, $parsed['direction'] );
		}
	}

	public function test_invalid_direction_returns_wp_error() {
		$result = $this->parse( [ 'direction' => 'sideways' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_backfill_invalid_direction', $result->get_error_code() );
	}

	public function test_valid_integration_id_is_threaded() {
		$parsed = $this->parse( [ 'integration' => 'backfill_mock' ] );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'backfill_mock', $parsed['integration_id'] );
	}

	public function test_unknown_integration_returns_wp_error_listing_available() {
		$result = $this->parse( [ 'integration' => 'nope' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_backfill_invalid_integration', $result->get_error_code() );
		$this->assertStringContainsString( 'backfill_mock', $result->get_error_message() );
	}

	/**
	 * A bare `--integration` (no value) reaches the parser as boolean true; it
	 * must get a usage hint, not a lookup failure for integration "1". An
	 * explicit empty value gets the same treatment.
	 */
	public function test_bare_integration_flag_errors_with_usage_hint() {
		foreach ( [ true, '' ] as $value ) {
			$result = $this->parse( [ 'integration' => $value ] );
			$this->assertInstanceOf( \WP_Error::class, $result, 'A valueless --integration must not parse.' );
			$this->assertSame( 'newspack_backfill_invalid_integration', $result->get_error_code() );
			$this->assertStringContainsString( '--integration=esp', $result->get_error_message() );
			$this->assertStringNotContainsString( '"1"', $result->get_error_message() );
		}
	}

	/**
	 * Push-only flags must hard-error when the direction includes pull — no
	 * silent partial application.
	 */
	public function test_push_only_flags_rejected_when_direction_includes_pull() {
		$push_only = [
			[ 'skip-lists' => true ],
			[ 'fields' => 'Content Access' ],
			[ 'subscription-ids' => '1,2' ],
			[ 'order-ids' => '3' ],
			[ 'migrated-subscriptions' => 'stripe' ],
		];
		foreach ( [ 'pull', 'both' ] as $direction ) {
			foreach ( $push_only as $flag ) {
				$result = $this->parse( array_merge( [ 'direction' => $direction ], $flag ) );
				$this->assertInstanceOf( \WP_Error::class, $result, wp_json_encode( $flag ) . " must be rejected under --direction=$direction." );
				$this->assertSame( 'newspack_backfill_push_only_flag', $result->get_error_code() );
			}
		}
	}

	public function test_push_only_flags_allowed_under_push_direction() {
		$parsed = $this->parse(
			[
				'direction'  => 'push',
				'skip-lists' => true,
			]
		);
		$this->assertIsArray( $parsed, 'parse_backfill_options only routes; push-only flag validity is parse_sync_options\'s job.' );
	}

	/**
	 * Invoke the private static build_sync_config() via reflection.
	 *
	 * @param array $assoc_args Associative CLI args.
	 * @return array
	 */
	private function build_config( array $assoc_args ) {
		$method = new \ReflectionMethod( RAS_Contact_Sync::class, 'build_sync_config' );
		$method->setAccessible( true );
		return $method->invoke( null, $assoc_args );
	}

	/**
	 * The new command documents --active-subs-only; the legacy `esp sync` alias
	 * keeps --active-only. The shared config builder honors either spelling.
	 */
	public function test_active_subs_only_flag_spellings() {
		$this->assertFalse( $this->build_config( [] )['active_only'] );
		$this->assertTrue( $this->build_config( [ 'active-subs-only' => true ] )['active_only'], 'New spelling (integrations backfill).' );
		$this->assertTrue( $this->build_config( [ 'active-only' => true ] )['active_only'], 'Legacy spelling (esp sync alias).' );
	}

	public function test_cli_backfill_rejects_invalid_direction_via_error() {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid --direction' );
		RAS_Contact_Sync::cli_backfill( [], [ 'direction' => 'sideways' ] );
	}

	/**
	 * End-to-end pull leg through the public command entry point: a
	 * --direction=pull run drives Contact_Pull for the target reader.
	 * (The push leg is covered end-to-end by the existing tally tests plus
	 * the scoping/parsing layers; driving it here would drag the WC mock
	 * fidelity constraints into this file for no added coverage.)
	 */
	public function test_cli_backfill_pull_direction_pulls_readers() {
		update_option( 'newspack_integration_incoming_fields_backfill_mock', [ 'field_a' => [ 'name' => 'Field A' ] ] );
		Failing_Sample_Integration::$pull_data = [ 'field_a' => 'gold' ];
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		RAS_Contact_Sync::cli_backfill(
			[],
			[
				'direction' => 'pull',
				'user-ids'  => (string) $user_id,
			]
		);

		delete_option( 'newspack_integration_incoming_fields_backfill_mock' );

		$this->assertSame( 1, Failing_Sample_Integration::$pull_count );
		$this->assertSame( '"gold"', \Newspack\Reader_Data::get_data( $user_id, 'field_a' ) );
	}

	/**
	 * A pull direction pre-flights that at least one in-scope integration has
	 * enabled incoming fields — otherwise `--direction=both` would complete a
	 * full push before discovering the pull leg has nothing to do (NPPD-2076).
	 */
	public function test_pull_direction_without_pull_targets_fails_preflight() {
		delete_option( 'newspack_integration_incoming_fields_backfill_mock' );

		foreach ( [ 'pull', 'both' ] as $direction ) {
			$result = $this->parse( [ 'direction' => $direction ] );
			$this->assertInstanceOf( \WP_Error::class, $result, "Direction '$direction' must fail without pull targets." );
			$this->assertSame( 'newspack_backfill_no_pull_targets', $result->get_error_code() );
		}

		$this->assertIsArray( $this->parse( [ 'direction' => 'push' ] ), 'Push direction needs no pull targets.' );
	}

	/**
	 * With --integration, the pull-target pre-flight considers only the target
	 * integration, mirroring the runtime scoping.
	 */
	public function test_pull_preflight_scopes_to_target_integration() {
		Integrations::register( new Failing_Sample_Integration( 'backfill_other', 'Backfill Other' ) );
		Integrations::enable( 'backfill_other' );

		$scoped_to_other = $this->parse(
			[
				'direction'   => 'pull',
				'integration' => 'backfill_other',
			]
		);
		$scoped_to_mock  = $this->parse(
			[
				'direction'   => 'pull',
				'integration' => 'backfill_mock',
			]
		);

		Integrations::disable( 'backfill_other' );

		$this->assertInstanceOf( \WP_Error::class, $scoped_to_other, 'backfill_other has no enabled incoming fields.' );
		$this->assertSame( 'newspack_backfill_no_pull_targets', $scoped_to_other->get_error_code() );
		$this->assertIsArray( $scoped_to_mock, 'backfill_mock has an enabled incoming field.' );
	}

	/**
	 * A scoped push pre-flights the TARGET integration's own can_sync(). The
	 * push leg's runtime gate is the global has_one_syncable_integration(),
	 * which a syncable sibling satisfies — so without this check a run scoped
	 * to a non-syncable integration proceeds and reports "Synced 0 contacts"
	 * instead of naming the reason (NPPD-2076 review).
	 */
	public function test_scoped_push_fails_preflight_when_target_cannot_sync() {
		Failing_Sample_Integration::$cannot_sync_reason = 'Missing API key.';

		$result = $this->parse( [ 'integration' => 'backfill_mock' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_backfill_integration_cannot_sync', $result->get_error_code() );
		$this->assertStringContainsString( 'Missing API key.', $result->get_error_message() );
	}

	/**
	 * The scoped syncability check is push-specific: a pull-only run reads from
	 * the provider and never pushes, so an unsyncable target is irrelevant.
	 */
	public function test_scoped_pull_ignores_push_syncability() {
		Failing_Sample_Integration::$cannot_sync_reason = 'Missing API key.';

		$parsed = $this->parse(
			[
				'direction'   => 'pull',
				'integration' => 'backfill_mock',
			]
		);

		$this->assertIsArray( $parsed, 'A pull-only run does not need push syncability.' );
		$this->assertSame( 'backfill_mock', $parsed['integration_id'] );
	}

	/**
	 * Same failure mode via the per-direction toggle: push_to_integrations()
	 * skips push-disabled integrations, so a scoped run against one would push
	 * to nobody and still report success (#700 / NPPD-2076 review).
	 */
	public function test_scoped_push_fails_preflight_when_outbound_sync_is_paused() {
		Integrations::get_integration( 'backfill_mock' )->update_settings_field_value( 'outgoing_sync_enabled', false );

		$result = $this->parse( [ 'integration' => 'backfill_mock' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'newspack_backfill_integration_cannot_sync', $result->get_error_code() );
		$this->assertStringContainsString( 'outbound sync is paused', $result->get_error_message() );
	}

	/**
	 * A paused outbound toggle is push-specific: the same integration is still
	 * a valid pull target.
	 */
	public function test_paused_outbound_sync_does_not_block_a_pull_direction() {
		Integrations::get_integration( 'backfill_mock' )->update_settings_field_value( 'outgoing_sync_enabled', false );

		$parsed = $this->parse(
			[
				'direction'   => 'pull',
				'integration' => 'backfill_mock',
			]
		);

		$this->assertIsArray( $parsed, 'Outbound state is irrelevant to a pull-only run.' );
	}

	/**
	 * The pull pre-flight uses the same predicate as pull_contacts(), so a
	 * paused inbound toggle fails fast rather than letting a --direction=both
	 * run complete its push leg first (#700 / NPPD-2076 review).
	 */
	public function test_pull_preflight_fails_when_inbound_sync_is_paused() {
		Integrations::get_integration( 'backfill_mock' )->update_settings_field_value( 'incoming_sync_enabled', false );

		foreach ( [ 'pull', 'both' ] as $direction ) {
			$result = $this->parse( [ 'direction' => $direction ] );
			$this->assertInstanceOf( \WP_Error::class, $result, "Direction '$direction' must fail when the only target is paused." );
			$this->assertSame( 'newspack_backfill_no_pull_targets', $result->get_error_code() );
		}

		$this->assertIsArray( $this->parse( [ 'direction' => 'push' ] ), 'A paused inbound toggle does not affect a push run.' );
	}

	/**
	 * An unscoped run keeps the global gate: the per-integration check only
	 * applies when --integration names a target.
	 */
	public function test_unscoped_run_skips_the_per_integration_sync_check() {
		Failing_Sample_Integration::$cannot_sync_reason = 'Missing API key.';

		$this->assertIsArray( $this->parse( [] ), 'Unscoped runs are gated globally, not per integration.' );
	}

	/**
	 * A run that tallies errors must exit non-zero so an unattended runbook can
	 * detect partial failure without parsing the summary (NPPD-2076 review).
	 */
	public function test_backfill_exits_non_zero_when_the_tally_has_errors() {
		WP_CLI::reset();
		Failing_Sample_Integration::$pull_should_fail = true;
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		RAS_Contact_Sync::cli_backfill(
			[],
			[
				'direction' => 'pull',
				'user-ids'  => (string) $user_id,
			]
		);

		$this->assertSame( 1, WP_CLI::$halt_code, 'A tallied error must set a non-zero exit code.' );
		$this->assertSame( [], WP_CLI::$successes, 'A partial failure is not a success.' );
		$this->assertContains( 'Pulled 0 contacts (1 errors, 0 skipped).', WP_CLI::$warnings );
	}

	/**
	 * Contrast: a clean run still exits 0 with the usual success line.
	 */
	public function test_backfill_exits_zero_on_a_clean_run() {
		WP_CLI::reset();
		Failing_Sample_Integration::$pull_data = [ 'field_a' => 'gold' ];
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		RAS_Contact_Sync::cli_backfill(
			[],
			[
				'direction' => 'pull',
				'user-ids'  => (string) $user_id,
			]
		);

		$this->assertNull( WP_CLI::$halt_code, 'A clean run must not halt with an error code.' );
		$this->assertSame( [ 'Pulled 1 contacts (0 errors, 0 skipped).' ], WP_CLI::$successes );
	}

	/**
	 * Set the paced-contacts counter and consume a boundary's worth of pause.
	 *
	 * @param int $accrued Contacts accrued since the last pause.
	 * @return array{seconds: int, remainder: int}
	 */
	private function consume_pause( int $accrued ): array {
		$counter = new \ReflectionProperty( RAS_Contact_Sync::class, 'unpaused_contacts' );
		$counter->setAccessible( true );
		$counter->setValue( null, $accrued );

		$method = new \ReflectionMethod( RAS_Contact_Sync::class, 'consume_pause_seconds' );
		$method->setAccessible( true );
		$seconds = $method->invoke( null );

		return [
			'seconds'   => $seconds,
			'remainder' => $counter->getValue(),
		];
	}

	/**
	 * The pause is owed per PAUSE_EVERY_CONTACTS contacts, not per boundary:
	 * zeroing the counter would discard the overflow and degrade pacing to 1s
	 * per --batch-size contacts, under-throttling large-batch runs exactly when
	 * they generate requests fastest (NPPD-2076 review).
	 */
	public function test_pause_consumes_counter_in_increments() {
		$per = RAS_Contact_Sync::PAUSE_EVERY_CONTACTS;

		$this->assertSame(
			[
				'seconds'   => 0,
				'remainder' => $per - 1,
			],
			$this->consume_pause( $per - 1 ),
			'Below the threshold nothing is owed and nothing is lost.'
		);
		$this->assertSame(
			[
				'seconds'   => 1,
				'remainder' => 0,
			],
			$this->consume_pause( $per )
		);
		$this->assertSame(
			[
				'seconds'   => 5,
				'remainder' => 0,
			],
			$this->consume_pause( $per * 5 ),
			'A batch of 5x the threshold owes five seconds, not one.'
		);
		$this->assertSame(
			[
				'seconds'   => 2,
				'remainder' => 7,
			],
			$this->consume_pause( ( $per * 2 ) + 7 ),
			'The remainder carries into the next boundary.'
		);
	}
}
