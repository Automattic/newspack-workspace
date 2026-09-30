<?php
/**
 * Tests the revision cleanup CLI command.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Revisions;

require_once dirname( __DIR__ ) . '/mocks/wp-cli-mocks.php';
require_once __DIR__ . '/traits/trait-autosave-fixtures.php';
require_once NEWSPACK_ABSPATH . 'includes/cli/class-revisions.php';

/**
 * Test the revision cleanup CLI command.
 */
class Newspack_Test_Revision_Cleanup_CLI extends WP_UnitTestCase {
	use Autosave_Fixtures;

	/**
	 * Keep 3 revisions and reset recorded CLI output.
	 */
	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		update_option(
			'newspack_revisions_control',
			[
				'active'  => true,
				'number'  => 3,
				'min_age' => '-1 week',
			]
		);
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( 'newspack_revisions_control' );
		parent::tear_down();
	}

	/**
	 * Create a post with 5 old revisions.
	 *
	 * @return array [ post ID, revision IDs oldest first ].
	 */
	private function create_post_over_limit() {
		$post_id = $this->create_post( 30 );
		$ids     = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->create_revision( $post_id, 40 - $i );
		}
		return [ $post_id, $ids ];
	}

	/**
	 * Dry run reports but deletes nothing.
	 */
	public function test_dry_run_deletes_nothing() {
		[ , $ids ] = $this->create_post_over_limit();

		Revisions::cmd_prune( [], [ 'dry-run' => true ] );

		$this->assertNotNull( get_post( $ids[0] ) );
		$this->assertContains( '2 revisions would be deleted.', WP_CLI::$successes );
	}

	/**
	 * Without dry run, revisions over the limit are deleted.
	 */
	public function test_prune_deletes_over_limit() {
		[ , $ids ] = $this->create_post_over_limit();

		Revisions::cmd_prune( [], [] );

		$this->assertNull( get_post( $ids[0] ) );
		$this->assertNull( get_post( $ids[1] ) );
		$this->assertNotNull( get_post( $ids[2] ) );
		$this->assertContains( 'Deleted 2 revisions.', WP_CLI::$successes );
	}

	/**
	 * --post limits the run to one post.
	 */
	public function test_post_flag_scopes_run() {
		[ $post_a, $ids_a ] = $this->create_post_over_limit();
		[ , $ids_b ]        = $this->create_post_over_limit();

		Revisions::cmd_prune( [], [ 'post' => (string) $post_a ] );

		$this->assertNull( get_post( $ids_a[0] ) );
		$this->assertNotNull( get_post( $ids_b[0] ) );
	}

	/**
	 * With the limit off, the command warns and deletes nothing.
	 */
	public function test_warns_when_limit_off() {
		[ , $ids ] = $this->create_post_over_limit();
		delete_option( 'newspack_revisions_control' );

		Revisions::cmd_prune( [], [] );

		$this->assertNotEmpty( WP_CLI::$warnings );
		$this->assertNotNull( get_post( $ids[0] ) );
	}

	/**
	 * A missing post aborts.
	 */
	public function test_missing_post_errors() {
		$this->expectException( WP_CLI_Mock_Exception::class );
		Revisions::cmd_prune( [], [ 'post' => '999999' ] );
	}
}
