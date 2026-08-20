<?php
/**
 * Tests for the migrate-post-exemptions CLI (NPPD-2199).
 *
 * Pins what makes the command more than a two-line loop; each case below names its own.
 * The load-bearing one is the missing/falsy split: `get_post_meta()` cannot see it, and it
 * is what makes a row an editor set falsy distinguishable from no row at all.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Membership_Gates_Migration;
use Newspack\Content_Restriction_Control;

require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
require_once dirname( __DIR__, 3 ) . '/includes/cli/class-membership-gates-migration.php';

/**
 * Test the migrate-post-exemptions command.
 *
 * @group content-gate
 */
class Test_Post_Exemptions_Migration extends WP_UnitTestCase {

	/**
	 * Enable content gates and register the exemption meta, so the tests run against the
	 * registered shape — the default is what stops get_post_meta() telling "no row" from
	 * "row set falsy".
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		Content_Restriction_Control::register_meta();
	}

	/**
	 * Unregister the exemption meta so its registered default does not leak into other suites.
	 */
	public function tear_down() {
		foreach ( array_column( (array) Content_Restriction_Control::get_available_post_types(), 'value' ) as $subtype ) {
			unregister_meta_key( 'post', Content_Restriction_Control::IS_EXEMPT_META_KEY, $subtype );
		}
		parent::tear_down();
	}

	/**
	 * Create a post carrying Memberships' force-public flag.
	 *
	 * @param array $args Post args passed to the factory.
	 *
	 * @return int Post ID.
	 */
	private function create_force_public_post( array $args = [] ): int {
		$post_id = self::factory()->post->create( $args );
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'yes' );
		return $post_id;
	}

	/**
	 * Run the command and return its whole transcript.
	 *
	 * @param array $assoc_args Named args, e.g. [ 'live' => true ].
	 *
	 * @return string
	 */
	private function run_migration( array $assoc_args = [] ): string {
		WP_CLI::reset();
		( new Membership_Gates_Migration() )->migrate_post_exemptions( [], $assoc_args );
		return implode( "\n", WP_CLI::$output );
	}

	/**
	 * Whether a real exemption row exists.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return bool
	 */
	private function has_recorded_exemption( int $post_id ): bool {
		global $wpdb;
		// Straight to the row rather than through metadata_exists(), which is the primitive
		// the command classifies with — an oracle sharing it could not see it fooled.
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$post_id,
				Content_Restriction_Control::IS_EXEMPT_META_KEY
			)
		);
	}

	/**
	 * A force-public post with no exemption row of its own gets one, and a second run has
	 * nothing left to do.
	 */
	public function test_records_an_exemption_where_only_the_memberships_flag_exists() {
		$post_id = $this->create_force_public_post();

		$this->run_migration( [ 'live' => true ] );
		$this->assertTrue( $this->has_recorded_exemption( $post_id ) );

		$output = $this->run_migration( [ 'live' => true ] );
		$this->assertStringContainsString( '0 exemption(s) recorded', $output );
		$this->assertStringContainsString( '1 post(s) were already exempt', $output );
	}

	/**
	 * The row `get_post_meta()` cannot distinguish from no row at all. It is overwritten,
	 * not skipped, and its ID reported.
	 */
	public function test_overwrites_a_falsy_exemption_row() {
		$post_id = $this->create_force_public_post();
		update_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, '' );

		$output = $this->run_migration( [ 'live' => true ] );

		$this->assertTrue( $this->has_recorded_exemption( $post_id ) );
		$this->assertStringContainsString( '1 post(s) carried a falsy exemption row', $output );
		$this->assertStringContainsString( (string) $post_id, $output );
	}

	/**
	 * The `no` rows the checkbox writes in bulk are not exemptions.
	 */
	public function test_ignores_force_public_no_rows() {
		$declined_id = self::factory()->post->create();
		update_post_meta( $declined_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'no' );

		$output = $this->run_migration( [ 'live' => true ] );

		$this->assertFalse( metadata_exists( 'post', $declined_id, Content_Restriction_Control::IS_EXEMPT_META_KEY ) );
		$this->assertStringContainsString( 'No posts carry the WooCommerce Memberships force-public flag', $output );
	}

	/**
	 * A row on a post type the exemption is not registered for is inert, so it is counted
	 * rather than migrated — that is what lets a census of the raw meta key reconcile.
	 */
	public function test_ignores_post_types_the_exemption_is_not_registered_for() {
		$gateable_id = $this->create_force_public_post();
		$inert_id    = $this->create_force_public_post( [ 'post_type' => 'attachment' ] );

		$output = $this->run_migration( [ 'live' => true ] );

		$this->assertTrue( $this->has_recorded_exemption( $gateable_id ) );
		$this->assertFalse( metadata_exists( 'post', $inert_id, Content_Restriction_Control::IS_EXEMPT_META_KEY ) );
		$this->assertStringContainsString( 'Ignored 1 force-public row(s) on post types the exemption does not apply to: attachment (1)', $output );
	}

	/**
	 * An exemption recorded by an earlier run outlives the flag it came from, because the
	 * command only ever selects `yes` rows. Such a post stays public after cutover, so it
	 * is named for review rather than left silent — or cleared, which would also take out
	 * an exemption an editor set deliberately.
	 */
	public function test_reports_an_exemption_whose_memberships_flag_was_revoked() {
		$post_id = $this->create_force_public_post();
		$this->run_migration( [ 'live' => true ] );
		update_post_meta( $post_id, Content_Restriction_Control::WC_FORCE_PUBLIC_META_KEY, 'no' );

		$output = $this->run_migration( [ 'live' => true ] );

		$this->assertTrue( $this->has_recorded_exemption( $post_id ), 'The exemption is reported, never cleared.' );
		$this->assertStringContainsString( '1 post(s) are exempt while the Memberships flag says they are not', $output );
		$this->assertStringContainsString( (string) $post_id, $output );
	}

	/**
	 * Dry-run is the default: it reports the split it would write, and writes neither the
	 * missing row nor the overwrite.
	 */
	public function test_dry_run_reports_the_split_and_writes_nothing() {
		$missing_row_id = $this->create_force_public_post();
		$falsy_row_id   = $this->create_force_public_post();
		update_post_meta( $falsy_row_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, '' );

		$output = $this->run_migration();

		$this->assertFalse( metadata_exists( 'post', $missing_row_id, Content_Restriction_Control::IS_EXEMPT_META_KEY ) );
		$this->assertFalse( $this->has_recorded_exemption( $falsy_row_id ) );
		$this->assertStringContainsString(
			'2 exemption(s) would be recorded (1 with no row, 1 overwriting a falsy row)',
			$output
		);
	}
}
