<?php
/**
 * Tests for the feed build date patch.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests;

/**
 * Tests that a feed's build date keeps up with scheduled posts.
 *
 * `wp_publish_post()` leaves `post_modified` alone, so a post written days
 * before its scheduled slot goes live carrying an old modified date. Core
 * builds `lastBuildDate` (RSS) and the channel `<updated>` (Atom) from
 * `post_modified_gmt` only, so the feed advertises a build date older than its
 * own newest item, and an aggregator that checks that date first skips the
 * article.
 *
 * @group patches
 */
class Test_Patches_Feed_Build_Date extends \WP_UnitTestCase {

	/**
	 * Create a published post with independent publish and last-edit dates.
	 *
	 * The row is written directly because `wp_update_post()` would stamp
	 * `post_modified` with the current time and destroy the fixture. What it
	 * reproduces is the row cron leaves behind: `wp_publish_post()` writes
	 * `post_status` and nothing else.
	 *
	 * @param string $published_gmt Publish date, `Y-m-d H:i:s` in UTC.
	 * @param string $modified_gmt  Last-edit date, `Y-m-d H:i:s` in UTC.
	 * @return int Post ID.
	 */
	private function create_post( $published_gmt, $modified_gmt ) {
		global $wpdb;

		$post_id = self::factory()->post->create(
			[
				'post_date'     => $published_gmt,
				'post_date_gmt' => $published_gmt,
			]
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->posts,
			[
				'post_modified'     => $modified_gmt,
				'post_modified_gmt' => $modified_gmt,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		return $post_id;
	}

	/**
	 * Run the main feed query, as a feed template would see it.
	 */
	private function query_feed() {
		$this->go_to( '/?feed=rss2' );
	}

	/**
	 * The build date follows the newest publish date, not the newest edit.
	 */
	public function test_build_date_covers_a_post_published_after_its_last_edit() {
		$this->create_post( '2026-08-12 09:00:00', '2026-08-09 11:05:42' );
		$this->query_feed();

		$this->assertSame(
			'2026-08-12 09:00:00',
			get_feed_build_date( 'Y-m-d H:i:s' ),
			'The build date should be the scheduled post\'s publish date, not its stale modified date.'
		);
	}

	/**
	 * A post edited after publication still wins, so the patch never moves the
	 * build date backwards.
	 */
	public function test_build_date_still_follows_the_newest_edit() {
		$this->create_post( '2026-08-10 08:00:00', '2026-08-10 08:00:00' );
		$this->create_post( '2026-08-08 08:00:00', '2026-08-11 16:30:00' );
		$this->query_feed();

		$this->assertSame( '2026-08-11 16:30:00', get_feed_build_date( 'Y-m-d H:i:s' ) );
	}

	/**
	 * The requested format is honoured, since the filter receives an
	 * already-formatted string and has to rebuild the date from scratch.
	 */
	public function test_build_date_honours_the_requested_format() {
		$this->create_post( '2026-08-12 09:00:00', '2026-08-09 11:05:42' );
		$this->query_feed();

		$this->assertSame( '2026-08-12T09:00:00Z', get_feed_build_date( 'Y-m-d\TH:i:s\Z' ) );
	}

	/**
	 * Comment dates count on a comment feed. The filter replaces core's value
	 * outright, so it has to carry them over or a comment feed's build date
	 * moves backwards to the newest post date.
	 */
	public function test_comment_feed_build_date_covers_the_newest_comment() {
		$post_id = $this->create_post( '2026-08-08 08:00:00', '2026-08-08 08:00:00' );
		self::factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_date'     => '2026-08-11 12:00:00',
				'comment_date_gmt' => '2026-08-11 12:00:00',
			]
		);
		$this->go_to( '/?feed=comments-rss2' );

		$this->assertSame( '2026-08-11 12:00:00', get_feed_build_date( 'Y-m-d H:i:s' ) );
	}
	/**
	 * A zeroed modified date, which legacy imports leave behind, gives way to
	 * the publish date. Core formats the zeroed value instead of discarding it,
	 * because `date_create_immutable_from_format()` parses
	 * `0000-00-00 00:00:00` into year -001 rather than failing, so the feed
	 * advertises `Tue, 30 Nov -001` as its build date.
	 */
	public function test_zeroed_modified_date_gives_way_to_the_publish_date() {
		$this->create_post( '2026-08-12 09:00:00', '0000-00-00 00:00:00' );
		$this->query_feed();

		$this->assertSame( '2026-08-12 09:00:00', get_feed_build_date( 'Y-m-d H:i:s' ) );
	}
}
