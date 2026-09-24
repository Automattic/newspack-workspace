<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing
/**
 * Tests per-integration scoping of the contact push fan-out (NPPD-2076).
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation\Contact_Sync;
use Newspack\Reader_Activation\Integrations;

require_once dirname( __DIR__ ) . '/integrations/class-failing-sample-integration.php';

/**
 * Scoping the push fan-out via $options['integration_id'].
 *
 * @group Integrations_Backfill
 */
class Test_Contact_Sync_Integration_Scope extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		add_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		// The built-in ESP integration is auto-enabled; disable so the sample
		// integrations are the only push targets.
		Integrations::disable( 'esp' );
		Failing_Sample_Integration::reset();
		Integrations::register( new Failing_Sample_Integration( 'scope_a', 'Scope A' ) );
		Integrations::register( new Failing_Sample_Integration( 'scope_b', 'Scope B' ) );
		Integrations::enable( 'scope_a' );
		Integrations::enable( 'scope_b' );
	}

	public function tear_down() {
		Integrations::disable( 'scope_a' );
		Integrations::disable( 'scope_b' );
		Integrations::enable( 'esp' );
		Failing_Sample_Integration::reset();
		remove_filter( 'newspack_reader_activation_is_syncing_allowed', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Push a minimal contact with the given sync options.
	 *
	 * @param array $options Sync options.
	 * @return true|\WP_Error
	 */
	private function sync_with_options( $options ) {
		return Contact_Sync::sync(
			[
				'email'    => 'reader@example.com',
				'metadata' => [],
			],
			'Integration scope test',
			null,
			$options
		);
	}

	public function test_unscoped_push_reaches_all_active_integrations() {
		$result = $this->sync_with_options( [] );
		$this->assertTrue( $result );
		$this->assertEqualsCanonicalizing( [ 'scope_a', 'scope_b' ], Failing_Sample_Integration::$push_ids );
	}

	public function test_scoped_push_reaches_only_the_target_integration() {
		$result = $this->sync_with_options( [ 'integration_id' => 'scope_b' ] );
		$this->assertTrue( $result );
		$this->assertSame( [ 'scope_b' ], Failing_Sample_Integration::$push_ids );
		$this->assertSame( 1, Failing_Sample_Integration::$push_count );
	}

	/**
	 * An unknown id yields an empty fan-out, not an error: rejecting bad ids is
	 * the CLI pre-flight's job (parse_backfill_options), not the sync engine's.
	 */
	public function test_scoped_push_with_unknown_id_pushes_nothing() {
		$result = $this->sync_with_options( [ 'integration_id' => 'not_registered' ] );
		$this->assertTrue( $result );
		$this->assertSame( 0, Failing_Sample_Integration::$push_count );
	}

	/**
	 * The failure path must honor the scope too. Retry scheduling lives inside
	 * the fan-out loop (one schedule per failed integration), so proving the
	 * excluded integration sees no attempt at all on a scoped failure also
	 * proves no retry can be created for it — pinning the docblock's "retries
	 * for it are scheduled normally" at the observable seam (NPPD-2076).
	 */
	public function test_scoped_push_failure_touches_only_the_target_integration() {
		Failing_Sample_Integration::$should_fail = true;

		$result = $this->sync_with_options( [ 'integration_id' => 'scope_b' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( [ 'scope_b' ], Failing_Sample_Integration::$push_ids, 'Only the scoped integration was attempted on the failure path.' );
	}
}
