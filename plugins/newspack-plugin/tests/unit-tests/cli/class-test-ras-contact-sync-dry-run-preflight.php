<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests that a push dry run makes the same configuration check as a live run
 * (NPPD-2415), so it can serve as a pre-flight.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\RAS_Contact_Sync;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-ras-contact-sync.php';
require_once dirname( __DIR__ ) . '/integrations/class-failing-sample-integration.php';
require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';

/**
 * Dry-run configuration pre-flight of `wp newspack esp sync` and
 * `wp newspack integrations backfill`.
 *
 * @group Contact_Sync_Options
 */
class Test_RAS_Contact_Sync_Dry_Run_Preflight extends WP_UnitTestCase {

	private int $reader_id;

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		Failing_Sample_Integration::reset();
		Integrations::register( new Failing_Sample_Integration( 'preflight_mock', 'Preflight Mock' ) );

		$this->reader_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		Reader_Activation::set_reader_verified( $this->reader_id );
	}

	public function tear_down() {
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		Integrations::disable( 'preflight_mock' );
		Integrations::enable( 'esp' );
		Failing_Sample_Integration::reset();
		parent::tear_down();
	}

	/**
	 * Both commands push through the same batch driver.
	 */
	public function command_provider(): array {
		return [
			'esp sync'              => [ 'cli_sync_contacts' ],
			'integrations backfill' => [ 'cli_backfill' ],
		];
	}

	private function dry_run( string $command, array $extra_args = [] ): void {
		RAS_Contact_Sync::$command(
			[],
			array_merge(
				[
					'dry-run'  => true,
					'user-ids' => (string) $this->reader_id,
				],
				$extra_args
			)
		);
	}

	/**
	 * Configuration errors a live run refuses: the built-in ESP integration is
	 * the only push target and has no audience set.
	 *
	 * @return string[]
	 */
	private function configuration_errors(): array {
		Integrations::enable( 'esp' );
		$configuration_errors = Contact_Sync::has_one_syncable_integration( true )->get_error_messages();
		$this->assertContains( 'ESP master list ID is not set.', $configuration_errors );
		$this->assertGreaterThan( 1, count( $configuration_errors ), 'The fixture must yield several reasons, so the test shows that each is printed.' );
		return $configuration_errors;
	}

	/**
	 * A `--create-missing` preview reads no contacts, so it still runs, but names
	 * every reason and exits non-zero instead of reporting success.
	 *
	 * @dataProvider command_provider
	 *
	 * @param string $command CLI method under test.
	 */
	public function test_dry_run_fails_on_configuration_errors( string $command ): void {
		$configuration_errors = $this->configuration_errors();

		try {
			$this->dry_run( $command, [ 'create-missing' => true ] );
			$this->fail( 'A dry run with a configuration error must exit non-zero.' );
		} catch ( WP_CLI_Mock_Exception $e ) {
			$this->assertStringStartsWith( 'Would sync 1 contacts (0 errors, 0 skipped).', $e->getMessage(), 'The preview still runs.' );
		}

		foreach ( $configuration_errors as $configuration_error ) {
			$this->assertContains( $configuration_error, WP_CLI::$warnings );
		}
		$this->assertSame( [], WP_CLI::$successes );
	}

	/**
	 * The default, update-only preview reads each contact at the integrations,
	 * so a failed check stops it before any read, as it would a live run. It
	 * still names every reason, and how to get a preview that reads no contacts.
	 *
	 * @dataProvider command_provider
	 *
	 * @param string $command CLI method under test.
	 */
	public function test_update_only_dry_run_stops_on_configuration_errors( string $command ): void {
		$configuration_errors = $this->configuration_errors();

		try {
			$this->dry_run( $command );
			$this->fail( 'A dry run with a configuration error must exit non-zero.' );
		} catch ( WP_CLI_Mock_Exception $e ) {
			$this->assertStringStartsNotWith( 'Would sync', $e->getMessage(), 'No preview runs.' );
		}

		foreach ( $configuration_errors as $configuration_error ) {
			$this->assertContains( $configuration_error, WP_CLI::$warnings );
		}
		$this->assertStringContainsString( '--create-missing', implode( "\n", WP_CLI::$warnings ) );
		$this->assertSame( [], WP_CLI::$successes );
	}

	/**
	 * Contrast: with a push target that can sync, the dry run previews as
	 * before and exits 0.
	 *
	 * @dataProvider command_provider
	 *
	 * @param string $command CLI method under test.
	 */
	public function test_dry_run_previews_when_configuration_is_valid( string $command ): void {
		Integrations::disable( 'esp' );
		Integrations::enable( 'preflight_mock' );

		$this->dry_run( $command );

		$this->assertNull( WP_CLI::$halt_code );
		$this->assertSame( [ 'Would sync 1 contacts (0 errors, 0 skipped).' ], WP_CLI::$successes );
	}
}
