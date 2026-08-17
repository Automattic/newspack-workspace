<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests retry semantics for permanent vs transient pull failures (NPPD-2076).
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Contact_Pull;
use Newspack\Reader_Data;

require_once __DIR__ . '/class-failing-sample-integration.php';

/**
 * Permanent pull failures (rejected reader-data writes) must not spawn retry
 * chains that can never succeed: pull_all() skips scheduling them, and a
 * permanent failure surfacing mid-chain ends the chain by failing its
 * ActionScheduler action instead of scheduling further attempts.
 *
 * @group Integrations_Backfill
 */
class Test_Contact_Pull_Retries extends WP_UnitTestCase {

	private $user_id;

	private $integration;

	public function set_up() {
		parent::set_up();
		Integrations::disable( 'esp' );
		Failing_Sample_Integration::reset();
		$this->integration = new Failing_Sample_Integration( 'retry_mock', 'Retry Mock' );
		Integrations::register( $this->integration );
		Integrations::enable( 'retry_mock' );
		update_option( 'newspack_integration_incoming_fields_retry_mock', [ 'field_a' => [ 'name' => 'Field A' ] ] );
		Failing_Sample_Integration::$pull_data = [ 'field_a' => 'gold' ];
		$this->user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
	}

	public function tear_down() {
		delete_option( 'newspack_integration_incoming_fields_retry_mock' );
		Integrations::disable( 'retry_mock' );
		Integrations::enable( 'esp' );
		Failing_Sample_Integration::reset();
		parent::tear_down();
	}

	/**
	 * Fill the reader to the data-key cap so any further write is rejected
	 * with the deterministic `too_many_items` error.
	 */
	private function fill_reader_to_cap() {
		for ( $i = 0; $i < Reader_Data::MAX_ITEMS; $i++ ) {
			Reader_Data::update_item( $this->user_id, "filler_{$i}", '"x"' );
		}
	}

	public function test_pull_all_does_not_schedule_retries_for_permanent_write_failures() {
		$this->fill_reader_to_cap();

		$result = Contact_Pull::pull_all( $this->user_id );

		$this->assertInstanceOf( \WP_Error::class, $result, 'The failure still surfaces to the caller.' );
		$pending = as_get_scheduled_actions(
			[
				'hook'   => Contact_Pull::RETRY_HOOK,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
		);
		$this->assertCount( 0, $pending, 'A permanent write failure must not schedule retries.' );
	}

	/**
	 * A permanent failure surfacing mid-chain ends the chain immediately: the
	 * retry action throws (so ActionScheduler marks it failed) instead of
	 * scheduling further attempts that would re-fetch and fail identically.
	 */
	public function test_retry_execution_throws_immediately_on_permanent_failure() {
		$this->fill_reader_to_cap();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'reader data item' );
		Contact_Pull::execute_integration_retry(
			[
				'integration_id' => 'retry_mock',
				'user_id'        => $this->user_id,
				'retry_count'    => 1,
			]
		);
	}

	/**
	 * Contrast: a transient failure below the retry cap keeps the chain alive
	 * — no throw; the next attempt is left to the usual scheduling.
	 */
	public function test_retry_execution_does_not_throw_on_transient_failure_below_cap() {
		Failing_Sample_Integration::$pull_should_fail = true;

		Contact_Pull::execute_integration_retry(
			[
				'integration_id' => 'retry_mock',
				'user_id'        => $this->user_id,
				'retry_count'    => 1,
			]
		);

		$this->assertSame( 1, Failing_Sample_Integration::$pull_count, 'The retry attempted the pull without throwing.' );
	}

	/**
	 * Incoming fields disabled mid-chain is a configuration change, not a
	 * failure: end the chain quietly like the is_set_up() guard rather than
	 * burning retries — or failing the action — on a deterministic outcome
	 * (NPPD-2076 review).
	 */
	public function test_retry_execution_aborts_quietly_when_incoming_fields_are_disabled() {
		delete_option( 'newspack_integration_incoming_fields_retry_mock' );

		Contact_Pull::execute_integration_retry(
			[
				'integration_id' => 'retry_mock',
				'user_id'        => $this->user_id,
				'retry_count'    => 1,
			]
		);

		$this->assertSame( 0, Failing_Sample_Integration::$pull_count, 'No provider fetch for a chain with nothing to pull.' );
		$this->assertContains( 'no_selected_incoming_fields', Contact_Pull::PERMANENT_PULL_ERRORS, 'And pull_all() must not schedule retries for it either.' );
	}
}
