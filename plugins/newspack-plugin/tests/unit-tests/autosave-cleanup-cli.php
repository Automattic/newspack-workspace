<?php
/**
 * Tests the autosave cleanup CLI command.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Autosaves;

require_once dirname( __DIR__ ) . '/mocks/wp-cli-mocks.php';
require_once __DIR__ . '/traits/trait-autosave-fixtures.php';
require_once NEWSPACK_ABSPATH . 'includes/cli/class-autosaves.php';

/**
 * Test the autosave cleanup CLI command.
 */
class Newspack_Test_Autosave_Cleanup_CLI extends WP_UnitTestCase {
	use Autosave_Fixtures;

	/**
	 * Reset recorded CLI output.
	 */
	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
	}

	/**
	 * Dry run reports but deletes nothing, and terminates.
	 */
	public function test_dry_run_deletes_nothing() {
		[ , $first ]  = $this->create_eligible_autosave();
		[ , $second ] = $this->create_eligible_autosave();

		Autosaves::cmd_prune( [], [ 'dry-run' => true ] );

		$this->assertNotNull( get_post( $first ) );
		$this->assertNotNull( get_post( $second ) );
		$this->assertContains( '2 stale autosaves would be deleted.', WP_CLI::$successes );
	}

	/**
	 * Without dry run, eligible autosaves are deleted.
	 */
	public function test_prune_deletes_eligible() {
		[ , $autosave_id ] = $this->create_eligible_autosave();

		Autosaves::cmd_prune( [], [] );

		$this->assertNull( get_post( $autosave_id ) );
		$this->assertContains( 'Deleted 1 stale autosaves.', WP_CLI::$successes );
	}

	/**
	 * --post limits the run to one post.
	 */
	public function test_post_flag_scopes_run() {
		[ $post_a, $autosave_a ] = $this->create_eligible_autosave();
		[ , $autosave_b ]        = $this->create_eligible_autosave();

		Autosaves::cmd_prune( [], [ 'post' => (string) $post_a ] );

		$this->assertNull( get_post( $autosave_a ) );
		$this->assertNotNull( get_post( $autosave_b ) );
	}

	/**
	 * --older-than=0 deletes recently stale autosaves but never fresh ones.
	 */
	public function test_older_than_zero_keeps_fresh() {
		$stale_post = $this->create_post( 1 );
		$stale      = $this->create_revision( $stale_post, 2, true );
		$this->create_revision( $stale_post, 1 );
		$fresh_post = $this->create_post( 60 );
		$fresh      = $this->create_revision( $fresh_post, 30, true );

		Autosaves::cmd_prune( [], [ 'older-than' => '0' ] );

		$this->assertNull( get_post( $stale ) );
		$this->assertNotNull( get_post( $fresh ) );
	}

	/**
	 * Invalid --older-than aborts.
	 */
	public function test_invalid_older_than_errors() {
		$this->expectException( WP_CLI_Mock_Exception::class );
		Autosaves::cmd_prune( [], [ 'older-than' => '-3' ] );
	}

	/**
	 * A missing post aborts.
	 */
	public function test_missing_post_errors() {
		$this->expectException( WP_CLI_Mock_Exception::class );
		Autosaves::cmd_prune( [], [ 'post' => '999999' ] );
	}
}
