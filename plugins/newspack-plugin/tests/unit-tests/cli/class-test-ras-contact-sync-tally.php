<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests the `wp newspack esp sync` results tally (NPPD-1883).
 *
 * The pre-existing counter bug this PR fixes lived in `sync_contacts()`: the run
 * summary conflated processed/error/skipped outcomes. These tests drive
 * `sync_contacts()` end-to-end and assert the returned
 * `[ 'processed', 'errors', 'skipped' ]` tally so the fix can't silently regress.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\RAS_Contact_Sync;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Integrations;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-ras-contact-sync.php';
require_once dirname( __DIR__ ) . '/integrations/class-failing-sample-integration.php';
require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mock.php';

/**
 * Results tally of the batch sync driver.
 *
 * @group Contact_Sync_Options
 */
class Test_RAS_Contact_Sync_Tally extends WP_UnitTestCase {

	/**
	 * A verified reader who has an active subscription (syncs).
	 *
	 * @var int
	 */
	private $active_user_id;

	/**
	 * A verified reader with no active subscription (skipped under --active-only).
	 *
	 * @var int
	 */
	private $inactive_user_id;

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		// Makes the Content Access field available so the field-scoped runs below
		// compute a real (non-WC) field. See run_sync(). Defining this leaks
		// process-wide, but a sibling CLI test already does so, and it only enables a
		// field so it cannot neutralize other tests.
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database = [];

		// Allow reader sync so Contact_Sync::can_sync() passes on this non-production
		// host. Done via the filter (not the NEWSPACK_ALLOW_READER_SYNC constant) so it
		// is scoped to this test and can't leak into tests that assert the default,
		// sync-disabled state.
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );

		// The built-in ESP integration is auto-enabled but unconfigured here; disabling
		// it keeps the failing sample integration the only push target, so the tally is
		// driven solely by this test's controllable integration.
		Integrations::disable( 'esp' );
		Failing_Sample_Integration::reset();
		$integration = new Failing_Sample_Integration( 'tally_mock', 'Tally Mock' );
		Integrations::register( $integration );
		Integrations::enable( 'tally_mock' );

		$this->active_user_id   = $this->create_reader();
		$this->inactive_user_id = $this->create_reader();

		// Only the active user gets an active subscription.
		wcs_create_subscription(
			[
				'customer_id' => $this->active_user_id,
				'status'      => 'active',
			]
		);
	}

	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		Integrations::disable( 'tally_mock' );
		Integrations::enable( 'esp' );
		Failing_Sample_Integration::reset();
		parent::tear_down();
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
	 * Invoke the private static sync_contacts() with the given config.
	 *
	 * Every run is field-scoped to the (non-WC) "Content Access" field. The tally
	 * logic under test is option-independent, but scoping keeps contact computation
	 * off the WooCommerce subscription path: the shared WC_Subscription test mock
	 * does not extend WC_Order, so building a subscribed reader's full metadata would
	 * trip an unrelated mock-fidelity error. Scoping skips those classes cleanly.
	 *
	 * @param array $config Batch sync configuration.
	 * @return array|\WP_Error
	 */
	private function run_sync( array $config ) {
		$config['options'] = [
			'skip_lists' => false,
			'fields'     => [ 'Content Access' ],
		];
		$sync_contacts_method = new \ReflectionMethod( RAS_Contact_Sync::class, 'sync_contacts' );
		$sync_contacts_method->setAccessible( true );
		return $sync_contacts_method->invoke( null, $config );
	}

	public function test_tally_counts_processed_and_skipped() {
		$tally = $this->run_sync(
			[
				'active_only' => true,
				'user_ids'    => [ $this->active_user_id, $this->inactive_user_id ],
			]
		);

		$this->assertSame(
			[
				'processed' => 1,
				'errors'    => 0,
				'skipped'   => 1,
			],
			$tally,
			'The active reader is processed; the reader with no active subscription is skipped under --active-only.'
		);
	}

	public function test_tally_counts_errors() {
		Failing_Sample_Integration::$should_fail = true;

		$tally = $this->run_sync(
			[
				'active_only' => true,
				'user_ids'    => [ $this->active_user_id, $this->inactive_user_id ],
			]
		);

		$this->assertSame(
			[
				'processed' => 0,
				'errors'    => 1,
				'skipped'   => 1,
			],
			$tally,
			'A failing push is tallied as an error (not processed); the inactive reader is still skipped.'
		);
	}

	/**
	 * The tally is static state; sync_contacts() resets it on entry so a second run
	 * in the same process reports only its own counts rather than accumulating.
	 */
	public function test_tally_resets_between_runs() {
		$config = [ 'user_ids' => [ $this->active_user_id ] ];

		$this->run_sync( $config );
		$second = $this->run_sync( $config );

		$this->assertSame(
			[
				'processed' => 1,
				'errors'    => 0,
				'skipped'   => 0,
			],
			$second,
			'A second run must not accumulate the first run into its tally.'
		);
	}

	/**
	 * `wp newspack esp sync` is a legacy alias: it must announce itself on
	 * STDERR (so the alias's STDOUT stays frozen for tooling that pipes or
	 * parses it) and keep the exact historical summary wording (NPPD-2076).
	 */
	public function test_esp_sync_alias_prints_notice_and_frozen_summary() {
		WP_CLI::reset();
		Integrations::get_integration( 'tally_mock' )->update_enabled_outgoing_fields( [ 'Content Access' ] );

		RAS_Contact_Sync::cli_sync_contacts(
			[],
			[
				'dry-run'  => true,
				'user-ids' => (string) $this->active_user_id,
				'fields'   => 'Content Access',
			]
		);

		$this->assertNotEmpty( WP_CLI::$warnings );
		$this->assertStringContainsString( 'legacy alias of `wp newspack integrations backfill`', WP_CLI::$warnings[0], 'The alias announces itself via warning (STDERR).' );
		$this->assertSame(
			[ 'Would sync 1 contacts (0 errors, 0 skipped).' ],
			WP_CLI::$successes,
			'The alias summary keeps the exact historical `esp sync` wording.'
		);
	}

	/**
	 * Read the protected static inter-batch pacing counter.
	 *
	 * @return int
	 */
	private function get_unpaused_contacts() {
		$prop = new \ReflectionProperty( RAS_Contact_Sync::class, 'unpaused_contacts' );
		$prop->setAccessible( true );
		return $prop->getValue();
	}

	/**
	 * A dry-run push never reaches a provider, so it must not accrue the
	 * pacing that exists to space out real external requests — previewing
	 * 100k readers should not cost ~17 minutes of sleep (NPPD-2076 review).
	 */
	public function test_dry_run_push_does_not_accrue_pacing() {
		$this->run_sync(
			[
				'user_ids'   => [ $this->active_user_id, $this->inactive_user_id ],
				'is_dry_run' => true,
			]
		);

		$this->assertSame( 0, $this->get_unpaused_contacts(), 'Dry-run push contacts generate no provider traffic to pace.' );
	}

	public function test_wet_push_accrues_pacing_per_contact() {
		$this->run_sync( [ 'user_ids' => [ $this->active_user_id, $this->inactive_user_id ] ] );

		$this->assertSame( 2, $this->get_unpaused_contacts(), 'Each wet-pushed contact reached the integrations and counts toward pacing.' );
	}
}
