<?php
/**
 * Tests trimming revisions over the revision limit.
 *
 * @package Newspack\Tests
 */

use Newspack\Revision_Cleanup;

require_once __DIR__ . '/traits/trait-autosave-fixtures.php';

/**
 * Test Revision Cleanup functionality.
 */
class Newspack_Test_Revision_Cleanup extends WP_UnitTestCase {
	use Autosave_Fixtures;

	/**
	 * Keep 3 revisions, deleting only those older than a week.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_limit( 3 );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( 'newspack_revisions_control' );
		parent::tear_down();
	}

	/**
	 * Set the revision limit.
	 *
	 * @param int $number Revisions to keep.
	 */
	private function set_limit( $number ) {
		update_option(
			'newspack_revisions_control',
			[
				'active'  => true,
				'number'  => $number,
				'min_age' => '-1 week',
			]
		);
	}

	/**
	 * Create a post with old revisions, oldest first.
	 *
	 * @param int $count Number of revisions.
	 * @return array [ post ID, revision IDs oldest first ].
	 */
	private function create_post_with_revisions( $count ) {
		$post_id = $this->create_post( 30 );
		$ids     = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->create_revision( $post_id, 30 + $count - $i );
		}
		return [ $post_id, $ids ];
	}

	/**
	 * IDs of a post's regular revisions (not autosaves).
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function regular_revision_ids( $post_id ) {
		$ids = [];
		foreach ( wp_get_post_revisions( $post_id ) as $revision ) {
			if ( ! wp_is_post_autosave( $revision ) ) {
				$ids[] = $revision->ID;
			}
		}
		return $ids;
	}

	/**
	 * Save a change to a post, so WordPress adds a revision and trims to the limit.
	 *
	 * @param int $post_id Post ID.
	 */
	private function save( $post_id ) {
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Changed ' . wp_rand(),
			]
		);
	}

	/**
	 * A save deletes at most 10 revisions, oldest first.
	 */
	public function test_save_deletes_at_most_ten() {
		[ $post_id, $ids ] = $this->create_post_with_revisions( 20 );

		$this->save( $post_id );

		$remaining = $this->regular_revision_ids( $post_id );
		$this->assertCount( 11, $remaining );
		foreach ( array_slice( $ids, 0, 10 ) as $id ) {
			$this->assertNull( get_post( $id ) );
		}
		foreach ( array_slice( $ids, 10 ) as $id ) {
			$this->assertNotNull( get_post( $id ) );
		}
	}

	/**
	 * A save within the cap trims to the limit as usual.
	 */
	public function test_save_under_cap_trims_to_limit() {
		[ $post_id ] = $this->create_post_with_revisions( 5 );

		$this->save( $post_id );

		$this->assertCount( 3, $this->regular_revision_ids( $post_id ) );
	}

	/**
	 * Major revisions don't use up a save's deletions.
	 */
	public function test_save_skips_major_revisions() {
		[ $post_id, $ids ] = $this->create_post_with_revisions( 20 );
		add_post_meta( $post_id, '_major_revision', (string) $ids[0] );
		add_post_meta( $post_id, '_major_revision', (string) $ids[1] );

		$this->save( $post_id );

		$this->assertCount( 11, $this->regular_revision_ids( $post_id ) );
		$this->assertNotNull( get_post( $ids[0] ) );
		$this->assertNotNull( get_post( $ids[1] ) );
		foreach ( array_slice( $ids, 2, 10 ) as $id ) {
			$this->assertNull( get_post( $id ) );
		}
	}

	/**
	 * An autosave among the oldest revisions is neither deleted nor counted as a deletion.
	 */
	public function test_save_keeps_autosave_among_oldest() {
		[ $post_id ]  = $this->create_post_with_revisions( 20 );
		$autosave_id = $this->create_revision( $post_id, 90, true );

		$this->save( $post_id );

		$this->assertNotNull( get_post( $autosave_id ) );
		$this->assertCount( 11, $this->regular_revision_ids( $post_id ) );
	}

	/**
	 * Without the Newspack limit, the list is left alone.
	 */
	public function test_cap_does_nothing_when_limit_inactive() {
		delete_option( 'newspack_revisions_control' );
		[ $post_id ] = $this->create_post_with_revisions( 20 );
		$revisions   = array_values( wp_get_post_revisions( $post_id, [ 'order' => 'ASC' ] ) );

		$this->assertSame( $revisions, Revision_Cleanup::cap_deletions_on_save( $revisions, $post_id ) );
	}

	/**
	 * The cron is scheduled hourly.
	 */
	public function test_cron_is_scheduled_hourly() {
		wp_clear_scheduled_hook( Revision_Cleanup::CRON_HOOK );
		Revision_Cleanup::cron_init();
		$this->assertNotFalse( wp_next_scheduled( Revision_Cleanup::CRON_HOOK ) );
		$this->assertSame( 'hourly', wp_get_schedule( Revision_Cleanup::CRON_HOOK ) );
	}

	/**
	 * Schedule the cron with a recurrence.
	 *
	 * @param string $recurrence 'hourly' or 'daily'.
	 */
	private function schedule( $recurrence ) {
		wp_clear_scheduled_hook( Revision_Cleanup::CRON_HOOK );
		wp_schedule_event( time(), $recurrence, Revision_Cleanup::CRON_HOOK );
	}

	/**
	 * A full pass that deletes nothing switches the cron to daily.
	 */
	public function test_backs_off_to_daily_when_a_pass_deletes_nothing() {
		$this->schedule( 'hourly' );
		$this->create_post_with_revisions( 3 );

		$this->assertSame( 0, Revision_Cleanup::run_cron() );
		$this->assertSame( 'daily', wp_get_schedule( Revision_Cleanup::CRON_HOOK ) );
	}

	/**
	 * A pass that deletes anything, even in an earlier run, stays hourly.
	 */
	public function test_stays_hourly_until_a_whole_pass_deletes_nothing() {
		$this->schedule( 'hourly' );
		$this->create_post_with_revisions( 10 );

		$this->assertSame( 5, Revision_Cleanup::run_cron( 5 ) );
		$this->assertSame( 2, Revision_Cleanup::run_cron( 5 ) );
		$this->assertSame( 'hourly', wp_get_schedule( Revision_Cleanup::CRON_HOOK ) );

		$this->assertSame( 0, Revision_Cleanup::run_cron( 5 ) );
		$this->assertSame( 'daily', wp_get_schedule( Revision_Cleanup::CRON_HOOK ) );
	}

	/**
	 * A daily run that deletes something switches back to hourly.
	 */
	public function test_daily_run_that_deletes_goes_back_to_hourly() {
		$this->schedule( 'daily' );
		$this->create_post_with_revisions( 5 );

		$this->assertSame( 2, Revision_Cleanup::run_cron() );
		$this->assertSame( 'hourly', wp_get_schedule( Revision_Cleanup::CRON_HOOK ) );
	}

	/**
	 * Saving the revision limit switches back to hourly.
	 */
	public function test_saving_the_limit_goes_back_to_hourly() {
		$this->schedule( 'daily' );
		$this->set_limit( 10 );
		$this->assertSame( 'hourly', wp_get_schedule( Revision_Cleanup::CRON_HOOK ) );
	}

	/**
	 * Unscheduling the cron clears its saved position.
	 */
	public function test_cron_deactivate_clears_cursor() {
		update_option( Revision_Cleanup::CURSOR_OPTION, 123 );
		Revision_Cleanup::cron_deactivate();
		$this->assertFalse( get_option( Revision_Cleanup::CURSOR_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Revision_Cleanup::CRON_HOOK ) );
	}

	/**
	 * The cron trims a post to the limit, deleting the oldest.
	 */
	public function test_run_cron_trims_oldest_to_limit() {
		[ $post_id, $ids ] = $this->create_post_with_revisions( 8 );

		$this->assertSame( 5, Revision_Cleanup::run_cron() );
		$this->assertSame( array_reverse( array_slice( $ids, 5 ) ), $this->regular_revision_ids( $post_id ) );
	}

	/**
	 * Revisions under the minimum age and major revisions are kept.
	 */
	public function test_run_cron_keeps_recent_and_major() {
		$post_id = $this->create_post( 1 );
		$major   = $this->create_revision( $post_id, 30 );
		add_post_meta( $post_id, '_major_revision', (string) $major );
		for ( $i = 5; $i > 0; $i-- ) {
			$this->create_revision( $post_id, $i );
		}

		$this->assertSame( 0, Revision_Cleanup::run_cron() );
		$this->assertCount( 6, $this->regular_revision_ids( $post_id ) );
	}

	/**
	 * Autosaves are neither counted nor deleted.
	 */
	public function test_run_cron_ignores_autosaves() {
		$post_id     = $this->create_post( 30 );
		$autosave_id = $this->create_revision( $post_id, 60, true );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_revision( $post_id, 40 - $i );
		}

		$this->assertSame( 0, Revision_Cleanup::run_cron() );
		$this->assertNotNull( get_post( $autosave_id ) );
	}

	/**
	 * Checking a post's revisions doesn't load each revision separately.
	 */
	public function test_get_excess_ids_does_not_load_each_revision() {
		global $wpdb;
		[ $post_id ] = $this->create_post_with_revisions( 25 );
		wp_cache_flush();

		$before = $wpdb->num_queries;
		$this->assertCount( 22, Revision_Cleanup::get_excess_ids( $post_id, 500 ) );
		$this->assertLessThan( 10, $wpdb->num_queries - $before );
	}

	/**
	 * A capped run stops partway and the next run picks up from there.
	 */
	public function test_run_cron_resumes_where_it_stopped() {
		[ $first ]  = $this->create_post_with_revisions( 6 );
		[ $second ] = $this->create_post_with_revisions( 10 );

		$this->assertSame( 5, Revision_Cleanup::run_cron( 5 ) );
		$this->assertCount( 3, $this->regular_revision_ids( $first ) );
		$this->assertCount( 8, $this->regular_revision_ids( $second ) );

		$this->assertSame( 5, Revision_Cleanup::run_cron( 5 ) );
		$this->assertCount( 3, $this->regular_revision_ids( $second ) );
	}

	/**
	 * After the last post, the next run starts over from the first.
	 */
	public function test_run_cron_starts_over_after_last_post() {
		[ $first ] = $this->create_post_with_revisions( 3 );
		[ $last ]  = $this->create_post_with_revisions( 5 );

		$this->assertSame( 2, Revision_Cleanup::run_cron() );
		$this->assertSame( 0, (int) get_option( Revision_Cleanup::CURSOR_OPTION ) );

		// A post that goes over the limit later, behind a stale cursor.
		update_option( Revision_Cleanup::CURSOR_OPTION, $last );
		$this->create_revision( $first, 35 );
		$this->assertSame( 1, Revision_Cleanup::run_cron() );
		$this->assertCount( 3, $this->regular_revision_ids( $first ) );
	}

	/**
	 * Posts with many children that aren't revisions are skipped.
	 */
	public function test_run_cron_skips_posts_with_many_attachments() {
		$post_id = $this->create_post( 30 );
		self::factory()->attachment->create_many( 5, [ 'post_parent' => $post_id ] );
		$revision = $this->create_revision( $post_id, 40 );

		$this->assertSame( 0, Revision_Cleanup::run_cron() );
		$this->assertNotNull( get_post( $revision ) );
		$this->assertCount(
			5,
			get_children(
				[
					'post_parent' => $post_id,
					'post_type'   => 'attachment',
				] 
			) 
		);
	}

	/**
	 * A post type with a higher limit of its own is trimmed only to that limit.
	 */
	public function test_run_cron_respects_higher_post_type_limit() {
		[ $post_id ] = $this->create_post_with_revisions( 8 );

		$keep_five = function () {
			return 5;
		};
		add_filter( 'wp_post_revisions_to_keep', $keep_five );
		$deleted = Revision_Cleanup::run_cron();
		remove_filter( 'wp_post_revisions_to_keep', $keep_five );

		$this->assertSame( 3, $deleted );
		$this->assertCount( 5, $this->regular_revision_ids( $post_id ) );
	}

	/**
	 * A post type with a lower limit of its own is trimmed to that limit once it's a candidate.
	 */
	public function test_run_cron_trims_to_lower_post_type_limit() {
		[ $post_id ] = $this->create_post_with_revisions( 5 );

		$keep_two = function () {
			return 2;
		};
		add_filter( 'wp_post_revisions_to_keep', $keep_two );
		$deleted = Revision_Cleanup::run_cron();
		remove_filter( 'wp_post_revisions_to_keep', $keep_two );

		$this->assertSame( 3, $deleted );
		$this->assertCount( 2, $this->regular_revision_ids( $post_id ) );
	}

	/**
	 * A limit of 0 means revisions are off, so nothing is trimmed, as in WordPress.
	 */
	public function test_run_cron_does_nothing_with_a_limit_of_zero() {
		[ $post_id ] = $this->create_post_with_revisions( 8 );

		$keep_none = function () {
			return 0;
		};
		add_filter( 'wp_post_revisions_to_keep', $keep_none );
		$deleted = Revision_Cleanup::run_cron();
		remove_filter( 'wp_post_revisions_to_keep', $keep_none );

		$this->assertSame( 0, $deleted );
		$this->assertCount( 8, $this->regular_revision_ids( $post_id ) );
	}

	/**
	 * A full batch of candidates saves its place, and the next run carries on from there.
	 */
	public function test_run_cron_continues_after_a_full_batch() {
		$post_ids = [];
		for ( $i = 0; $i <= Revision_Cleanup::MAX_CANDIDATES; $i++ ) {
			[ $post_ids[] ] = $this->create_post_with_revisions( 4 );
		}

		$this->assertSame( Revision_Cleanup::MAX_CANDIDATES, Revision_Cleanup::run_cron() );
		$this->assertSame( $post_ids[ Revision_Cleanup::MAX_CANDIDATES - 1 ], (int) get_option( Revision_Cleanup::CURSOR_OPTION ) );

		$this->assertSame( 1, Revision_Cleanup::run_cron() );
		$this->assertCount( 3, $this->regular_revision_ids( end( $post_ids ) ) );
		$this->assertSame( 0, (int) get_option( Revision_Cleanup::CURSOR_OPTION ) );
	}

	/**
	 * Revisions whose post no longer exists don't stop a run.
	 */
	public function test_run_cron_skips_orphaned_revisions() {
		global $wpdb;
		[ $orphan_parent, $orphans ] = $this->create_post_with_revisions( 5 );
		$wpdb->delete( $wpdb->posts, [ 'ID' => $orphan_parent ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $orphan_parent );
		[ $post_id ] = $this->create_post_with_revisions( 5 );

		$this->assertSame( 2, Revision_Cleanup::run_cron() );
		$this->assertCount( 3, $this->regular_revision_ids( $post_id ) );
		$this->assertNotNull( get_post( $orphans[0] ) );
	}

	/**
	 * Nothing is deleted when the limit is off or unlimited.
	 */
	public function test_run_cron_does_nothing_without_a_limit() {
		[ $post_id ] = $this->create_post_with_revisions( 8 );

		$this->set_limit( -1 );
		$this->assertSame( 0, Revision_Cleanup::run_cron() );

		delete_option( 'newspack_revisions_control' );
		$this->assertSame( 0, Revision_Cleanup::run_cron() );

		$this->assertCount( 8, $this->regular_revision_ids( $post_id ) );
	}

	/**
	 * A vetoed deletion is skipped and not counted.
	 */
	public function test_run_cron_skips_vetoed_deletions() {
		[ $post_id, $ids ] = $this->create_post_with_revisions( 5 );

		$veto = function ( $check, $post ) use ( $ids ) {
			return $post->ID === $ids[0] ? false : $check;
		};
		add_filter( 'pre_delete_post', $veto, 10, 2 );
		$deleted = Revision_Cleanup::run_cron();
		remove_filter( 'pre_delete_post', $veto, 10 );

		$this->assertSame( 1, $deleted );
		$this->assertNotNull( get_post( $ids[0] ) );
		$this->assertNull( get_post( $ids[1] ) );
		$this->assertCount( 4, $this->regular_revision_ids( $post_id ) );
	}
}
