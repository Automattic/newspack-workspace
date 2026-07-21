<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests dry-run support in Contact_Pull::pull_single_integration (NPPD-2076).
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Integrations;
use Newspack\Reader_Activation\Integrations\Contact_Pull;
use Newspack\Reader_Data;

require_once __DIR__ . '/class-failing-sample-integration.php';

/**
 * Dry-run pull: fetch happens, persistence does not.
 *
 * @group Integrations_Backfill
 */
class Test_Contact_Pull_Dry_Run extends WP_UnitTestCase {

	private $user_id;

	private $integration;

	public function set_up() {
		parent::set_up();
		Failing_Sample_Integration::reset();
		$this->integration = new Failing_Sample_Integration( 'pull_mock', 'Pull Mock' );
		Integrations::register( $this->integration );
		Integrations::enable( 'pull_mock' );
		// Enable one incoming field ("field_a") for the mock. Raw data carries a
		// schema key ("name") so get_enabled_incoming_fields() takes the non-legacy path.
		update_option( 'newspack_integration_incoming_fields_pull_mock', [ 'field_a' => [ 'name' => 'Field A' ] ] );
		Failing_Sample_Integration::$pull_data = [
			'field_a' => 'gold',
			'field_b' => 'not-enabled-so-never-stored',
		];
		$this->user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
	}

	public function tear_down() {
		delete_option( 'newspack_integration_incoming_fields_pull_mock' );
		Integrations::disable( 'pull_mock' );
		Failing_Sample_Integration::reset();
		parent::tear_down();
	}

	public function test_dry_run_fetches_but_does_not_persist() {
		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration, true );
		$this->assertTrue( $result );
		$this->assertSame( 1, Failing_Sample_Integration::$pull_count, 'Dry run still performs the external fetch.' );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'field_a' ), 'Dry run must not write reader data.' );
	}

	public function test_wet_run_persists_enabled_fields_only() {
		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration );
		$this->assertTrue( $result );
		$this->assertSame( '"gold"', Reader_Data::get_data( $this->user_id, 'field_a' ), 'Values are stored JSON-encoded.' );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'field_b' ), 'Fields not enabled as incoming are filtered out.' );
	}

	public function test_pull_error_propagates_as_wp_error() {
		Failing_Sample_Integration::$pull_should_fail = true;
		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration, true );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mock_pull_error', $result->get_error_code() );
	}
}
