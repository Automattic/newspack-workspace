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

	/**
	 * A pull whose Reader_Data write is rejected is a failed pull: fetching
	 * alone must not count as success (NPPD-2076).
	 */
	public function test_write_failure_returns_wp_error() {
		// Fill the reader to the data-key cap so the pull's write is rejected.
		for ( $i = 0; $i < Reader_Data::MAX_ITEMS; $i++ ) {
			Reader_Data::update_item( $this->user_id, "filler_{$i}", '"x"' );
		}

		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration );

		$this->assertInstanceOf( \WP_Error::class, $result, 'A pull that cannot persist is a failed pull.' );
		$this->assertSame( 'reader_data_write_failed', $result->get_error_code() );
		$this->assertSame( 1, Failing_Sample_Integration::$pull_count, 'The fetch still happened.' );
	}

	/**
	 * The dry-run preview must report writes that are guaranteed to fail —
	 * otherwise an operator green-lights a run whose systematic failures the
	 * preview could not show (NPPD-2076 review).
	 */
	public function test_dry_run_reports_writes_that_would_fail() {
		// Fill the reader to the data-key cap so the pull's write would be rejected.
		for ( $i = 0; $i < Reader_Data::MAX_ITEMS; $i++ ) {
			Reader_Data::update_item( $this->user_id, "filler_{$i}", '"x"' );
		}

		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration, true );

		$this->assertInstanceOf( \WP_Error::class, $result, 'The preview must surface a guaranteed write failure.' );
		$this->assertSame( 'reader_data_write_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Too many items', $result->get_error_message() );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'field_a' ), 'The preview still persists nothing.' );
	}

	/**
	 * A preview persists nothing, so keys it has already accepted must be
	 * threaded forward: otherwise a pull adding several new keys that
	 * collectively cross the cap previews clean and then fails for real
	 * (NPPD-2076 review).
	 */
	public function test_dry_run_accounts_for_keys_it_would_add_earlier_in_the_batch() {
		update_option(
			'newspack_integration_incoming_fields_pull_mock',
			[
				'field_a' => [ 'name' => 'Field A' ],
				'field_b' => [ 'name' => 'Field B' ],
			]
		);
		Failing_Sample_Integration::$pull_data = [
			'field_a' => 'gold',
			'field_b' => 'silver',
		];
		// Leave exactly one free slot, so only the first of the two new keys fits.
		for ( $i = 0; $i < Reader_Data::MAX_ITEMS - 1; $i++ ) {
			Reader_Data::update_item( $this->user_id, "filler_{$i}", '"x"' );
		}

		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration, true );

		$this->assertInstanceOf( \WP_Error::class, $result, 'The second key would breach the cap.' );
		$this->assertSame( 'reader_data_write_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Too many items', $result->get_error_message() );
		$this->assertStringContainsString( '1 of 2', $result->get_error_message(), 'Exactly one of the two writes fails.' );
	}

	/**
	 * A numeric zero must survive the round trip. Its JSON encoding is the
	 * string "0", which PHP treats as falsy — a rejection here would fail the
	 * pull permanently and re-enqueue the reader forever (NPPD-2076 review).
	 */
	public function test_zero_valued_incoming_field_is_stored() {
		Failing_Sample_Integration::$pull_data = [ 'field_a' => 0 ];

		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration );

		$this->assertTrue( $result, 'A zero-valued incoming field is a successful pull.' );
		$this->assertSame( '0', Reader_Data::get_data( $this->user_id, 'field_a' ) );
	}

	/**
	 * And its dry-run preview agrees — no phantom error.
	 */
	public function test_dry_run_does_not_flag_a_zero_valued_field() {
		Failing_Sample_Integration::$pull_data = [ 'field_a' => 0 ];

		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration, true );

		$this->assertTrue( $result, 'A storable zero must not preview as a failure.' );
	}

	/**
	 * A payload whose keys don't match any enabled incoming field stores
	 * nothing and still succeeds — the reader may legitimately have no values,
	 * so it isn't an error. This is the shape the ESP pull had when it read a
	 * key the provider never returned: "pulled, 0 errors" with no delivery.
	 * Pinned so the outcome stays deliberate, with the log naming the reason.
	 */
	public function test_payload_with_no_matching_keys_succeeds_without_storing() {
		Failing_Sample_Integration::$pull_data = [ 'unknown_key' => 'value' ];

		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration );

		$this->assertTrue( $result, 'No matching keys is not a failure.' );
		$this->assertSame( 1, Failing_Sample_Integration::$pull_count, 'The fetch still happened.' );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'unknown_key' ), 'Unenabled keys are never stored.' );
		$this->assertFalse( Reader_Data::get_data( $this->user_id, 'field_a' ), 'And nothing else was invented.' );
	}

	/**
	 * A provider returning a non-array payload is rejected explicitly rather
	 * than tripping a TypeError that the catch block would misclassify as a
	 * transient exception worth five retries (NPPD-2076 review).
	 */
	public function test_non_array_payload_is_a_permanent_error() {
		Failing_Sample_Integration::$pull_data = 'not-an-array';

		$result = Contact_Pull::pull_single_integration( $this->user_id, $this->integration );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_pull_payload', $result->get_error_code() );
		$this->assertContains( 'invalid_pull_payload', Contact_Pull::PERMANENT_PULL_ERRORS );
	}
}
