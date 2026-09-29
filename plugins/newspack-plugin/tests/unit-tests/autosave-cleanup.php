<?php
/**
 * Tests the stale autosave cleanup.
 *
 * @package Newspack\Tests
 */

use Newspack\Autosave_Cleanup;

require_once __DIR__ . '/traits/trait-autosave-fixtures.php';

/**
 * Test Autosave Cleanup functionality.
 */
class Newspack_Test_Autosave_Cleanup extends WP_UnitTestCase {
	use Autosave_Fixtures;

	/**
	 * Test the default wait.
	 */
	public function test_get_days_defaults_to_1() {
		if ( defined( 'NEWSPACK_AUTOSAVE_CLEANUP_DAYS' ) ) {
			$this->markTestSkipped( 'NEWSPACK_AUTOSAVE_CLEANUP_DAYS is defined.' );
		}
		$this->assertSame( 1, Autosave_Cleanup::get_days() );
	}

	/**
	 * An autosave overtaken by a save 20 days ago is eligible.
	 */
	public function test_autosave_stale_longer_than_wait_is_eligible() {
		[ , $autosave_id ] = $this->create_eligible_autosave();
		$this->assertContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 7, 100 ) );
	}

	/**
	 * A stale autosave by someone other than the post's author is deleted.
	 */
	public function test_other_users_stale_autosave_is_deleted() {
		$author = self::factory()->user->create( [ 'role' => 'editor' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );

		$post_id = $this->create_post( 20 );
		$this->set_dates( $post_id, [ 'post_author' => $author ] );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->set_dates( $autosave_id, [ 'post_author' => $editor ] );
		$this->create_revision( $post_id, 20 );

		$this->assertSame( $autosave_id, wp_get_post_autosave( $post_id, $editor )->ID );
		$this->assertSame( 1, Autosave_Cleanup::run_cron() );
		$this->assertNull( get_post( $autosave_id ) );
	}

	/**
	 * An autosave overtaken by a save 5 days ago is kept.
	 */
	public function test_autosave_recently_overtaken_is_not_eligible() {
		$post_id     = $this->create_post( 5 );
		$autosave_id = $this->create_revision( $post_id, 10, true );
		$this->create_revision( $post_id, 5 );
		$this->assertNotContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 7, 100 ) );
	}

	/**
	 * Saving a page daily does not reset the clock once it was overtaken 20 days ago.
	 */
	public function test_daily_saves_do_not_reset_the_wait() {
		$post_id     = $this->create_post( 1 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		foreach ( [ 20, 10, 5, 1 ] as $days ) {
			$this->create_revision( $post_id, $days );
		}
		$this->assertContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 7, 100 ) );
	}

	/**
	 * Without revisions, a stale autosave on a post untouched for 20 days is eligible.
	 */
	public function test_fallback_without_revisions_is_eligible() {
		$post_id     = $this->create_post( 20 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->assertContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 7, 100 ) );
	}

	/**
	 * Without revisions, a stale autosave on a post saved yesterday is kept.
	 */
	public function test_fallback_without_revisions_recent_save_is_not_eligible() {
		$post_id     = $this->create_post( 1 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->assertNotContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 7, 100 ) );
	}

	/**
	 * A fresh autosave (newer than the post) is never eligible, however old.
	 */
	public function test_fresh_autosave_is_never_eligible() {
		$post_id     = $this->create_post( 60 );
		$autosave_id = $this->create_revision( $post_id, 30, true );
		$this->assertNotContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 0, 100 ) );
	}

	/**
	 * A major autosave is excluded in SQL, so it can't fill a batch.
	 */
	public function test_major_autosave_is_excluded_and_does_not_block_batch() {
		[ , $eligible_autosave ]         = $this->create_eligible_autosave();
		[ $major_post, $major_autosave ] = $this->create_eligible_autosave();
		add_post_meta( $major_post, '_major_revision', $major_autosave );

		$this->assertSame( [ $eligible_autosave ], Autosave_Cleanup::get_eligible_ids( 7, 1 ) );
	}

	/**
	 * Regular revisions are never returned.
	 */
	public function test_regular_revisions_are_never_eligible() {
		$post_id     = $this->create_post( 1 );
		$revision_id = $this->create_revision( $post_id, 60 );
		$this->assertNotContains( $revision_id, Autosave_Cleanup::get_eligible_ids( 0, 100 ) );
	}

	/**
	 * The post argument scopes the query to one parent.
	 */
	public function test_post_id_scopes_results() {
		[ $post_a, $autosave_a ] = $this->create_eligible_autosave();
		$this->create_eligible_autosave();
		$this->assertSame( [ $autosave_a ], Autosave_Cleanup::get_eligible_ids( 7, 100, $post_a ) );
	}

	/**
	 * The before_id argument pages through results, newest first.
	 */
	public function test_before_id_pages_results() {
		[ , $first ]  = $this->create_eligible_autosave();
		[ , $second ] = $this->create_eligible_autosave();
		$this->assertSame( [ $second, $first ], Autosave_Cleanup::get_eligible_ids( 7, 100 ) );
		$this->assertSame( [ $first ], Autosave_Cleanup::get_eligible_ids( 7, 100, 0, $second ) );
	}

	/**
	 * Only autosaves are deleted, whatever IDs are passed.
	 */
	public function test_delete_autosaves_only_deletes_autosaves() {
		[ $post_id, $autosave_id ] = $this->create_eligible_autosave();
		$revision_id               = $this->create_revision( $post_id, 40 );

		$deleted = Autosave_Cleanup::delete_autosaves( [ $autosave_id, $revision_id, $post_id ] );

		$this->assertSame( [ $autosave_id ], $deleted );
		$this->assertNull( get_post( $autosave_id ) );
		$this->assertNotNull( get_post( $revision_id ) );
		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * The cron is scheduled daily.
	 */
	public function test_cron_is_scheduled_daily() {
		wp_clear_scheduled_hook( Autosave_Cleanup::CRON_HOOK );
		Autosave_Cleanup::cron_init();
		$this->assertNotFalse( wp_next_scheduled( Autosave_Cleanup::CRON_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Autosave_Cleanup::CRON_HOOK ) );
	}

	/**
	 * The cron deletes eligible autosaves and keeps the rest.
	 */
	public function test_run_cron_deletes_only_eligible() {
		[ , $eligible ] = $this->create_eligible_autosave();
		$post_id        = $this->create_post( 0 );
		$recent         = $this->create_revision( $post_id, 10, true );
		$this->create_revision( $post_id, 0 );

		$this->assertSame( 1, Autosave_Cleanup::run_cron() );
		$this->assertNull( get_post( $eligible ) );
		$this->assertNotNull( get_post( $recent ) );
	}

	/**
	 * The cron stops at the per-run cap.
	 */
	public function test_run_cron_respects_cap() {
		$this->create_eligible_autosave();
		$this->create_eligible_autosave();
		$this->create_eligible_autosave();

		$this->assertSame( 2, Autosave_Cleanup::run_cron( 2 ) );
		$this->assertCount( 1, Autosave_Cleanup::get_eligible_ids( 7, 100 ) );
	}

	/**
	 * A capped run deletes the newest autosaves and leaves the oldest backlog.
	 */
	public function test_run_cron_deletes_newest_first() {
		[ , $oldest ] = $this->create_eligible_autosave();
		[ , $middle ] = $this->create_eligible_autosave();
		[ , $newest ] = $this->create_eligible_autosave();

		$this->assertSame( 2, Autosave_Cleanup::run_cron( 2 ) );
		$this->assertNull( get_post( $newest ) );
		$this->assertNull( get_post( $middle ) );
		$this->assertNotNull( get_post( $oldest ) );
	}

	/**
	 * A veto that returns true (as Revisions_Control does) is not counted as a deletion.
	 */
	public function test_delete_autosaves_does_not_count_truthy_veto() {
		[ , $autosave_id ] = $this->create_eligible_autosave();

		$veto = function ( $check, $post ) use ( $autosave_id ) {
			return $post->ID === $autosave_id ? true : $check;
		};
		add_filter( 'pre_delete_post', $veto, 10, 2 );
		$deleted = Autosave_Cleanup::delete_autosaves( [ $autosave_id ] );
		remove_filter( 'pre_delete_post', $veto, 10 );

		$this->assertSame( [], $deleted );
		$this->assertNotNull( get_post( $autosave_id ) );
	}

	/**
	 * An autosave refreshed after it was selected is not deleted.
	 */
	public function test_delete_autosaves_skips_autosave_refreshed_after_selection() {
		[ , $autosave_id ] = $this->create_eligible_autosave();
		$this->assertContains( $autosave_id, Autosave_Cleanup::get_eligible_ids( 7, 100 ) );

		$now = gmdate( 'Y-m-d H:i:s' );
		$this->set_dates(
			$autosave_id,
			[
				'post_modified_gmt' => $now,
				'post_modified'     => $now,
			]
		);

		$this->assertSame( [], Autosave_Cleanup::delete_autosaves( [ $autosave_id ] ) );
		$this->assertNotNull( get_post( $autosave_id ) );
	}

	/**
	 * The revision limit's minimum age doesn't hold back stale autosaves.
	 */
	public function test_revisions_control_min_age_does_not_apply_to_autosaves() {
		[ , $autosave_id ] = $this->create_eligible_autosave();

		update_option(
			'newspack_revisions_control',
			[
				'active'  => true,
				'number'  => 10,
				'min_age' => '-60 days',
			]
		);
		$this->assertSame( 1, Autosave_Cleanup::run_cron() );
		$this->assertNull( get_post( $autosave_id ) );

		delete_option( 'newspack_revisions_control' );
	}

	/**
	 * A vetoed deletion is skipped, not retried forever or allowed to stop the run.
	 */
	public function test_run_cron_walks_past_vetoed_deletions() {
		[ , $other ]  = $this->create_eligible_autosave();
		[ , $vetoed ] = $this->create_eligible_autosave();

		$veto = function ( $check, $post ) use ( $vetoed ) {
			return $post->ID === $vetoed ? false : $check;
		};
		add_filter( 'pre_delete_post', $veto, 10, 2 );
		$deleted = Autosave_Cleanup::run_cron();
		remove_filter( 'pre_delete_post', $veto, 10 );

		$this->assertSame( 1, $deleted );
		$this->assertNotNull( get_post( $vetoed ) );
		$this->assertNull( get_post( $other ) );
	}
}
