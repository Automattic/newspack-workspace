<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Tests the `wp newspack integrations backfill` pre-flight parser (NPPD-2076).
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\RAS_Contact_Sync;
use Newspack\Reader_Activation\Integrations;

require_once dirname( __DIR__, 3 ) . '/includes/cli/class-ras-contact-sync.php';
require_once dirname( __DIR__ ) . '/integrations/class-failing-sample-integration.php';

// Minimal WP_CLI stub (same shape as the sibling tally test; guarded so whichever
// file loads first wins).
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function log( $message ) {}
		public static function line( $message = '' ) {}
		public static function success( $message ) {}
		public static function error( $message ) {
			throw new \Exception( esc_html( $message ) );
		}
	}
}

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
	}

	public function tear_down() {
		Integrations::disable( 'backfill_mock' );
		Integrations::enable( 'esp' );
		Failing_Sample_Integration::reset();
		parent::tear_down();
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
				'direction'      => 'push',
				'integration_id' => null,
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
}
