<?php
/**
 * Tests for the `backfill-post-subtitles` CLI command.
 *
 * The sanitizer itself (`newspack_sanitize_post_subtitle()`, normally defined by
 * newspack-theme) is not plugin code and is not loaded here, so a minimal stub with the
 * theme's own allow-list stands in for it — this suite is about which rows the command
 * reads, writes, and reports, not about what the sanitizer does to a given string.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Post_Subtitle_Backfill;

require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
require_once dirname( __DIR__, 2 ) . '/mocks/post-subtitle-sanitizer-mock.php';
require_once dirname( __DIR__, 3 ) . '/includes/cli/class-post-subtitle-backfill.php';

/**
 * Test the backfill-post-subtitles command.
 *
 * @group cli
 */
class Test_Post_Subtitle_Backfill extends WP_UnitTestCase {

	/**
	 * Writes a `newspack_post_subtitle` value straight to postmeta, bypassing
	 * update_post_meta() and any sanitize_callback — the shape of data this command
	 * exists to clean up.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $value   Raw meta value.
	 *
	 * @return void
	 */
	private function seed_raw_subtitle( int $post_id, string $value ) {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->postmeta,
			[
				'post_id'  => $post_id,
				'meta_key' => 'newspack_post_subtitle',
			]
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->postmeta,
			[
				'post_id'    => $post_id,
				'meta_key'   => 'newspack_post_subtitle',
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * The stored value, read past the meta cache — the same row the command itself
	 * reads and writes.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	private function stored_subtitle( int $post_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'newspack_post_subtitle'",
				$post_id
			)
		);
	}

	/**
	 * Runs the command and returns its whole transcript.
	 *
	 * @param array $assoc_args Named args, e.g. [ 'live' => true ].
	 *
	 * @return string
	 */
	private function run_backfill( array $assoc_args = [] ): string {
		WP_CLI::reset();
		( new Post_Subtitle_Backfill() )->backfill( [], $assoc_args );
		return implode( "\n", WP_CLI::$output );
	}

	/**
	 * A raw value from before the sanitizer shipped is rewritten through it, and a
	 * second run finds nothing left to change.
	 */
	public function test_sanitizes_a_raw_value_when_live() {
		$post_id = self::factory()->post->create();
		$this->seed_raw_subtitle( $post_id, '<script>alert(1)</script><b>Bold</b> subtitle' );

		$output = $this->run_backfill( [ 'live' => true ] );

		$this->assertSame( 'alert(1)<b>Bold</b> subtitle', $this->stored_subtitle( $post_id ) );
		$this->assertStringContainsString( 'Subtitle sanitized for: ' . $post_id, $output );
		$this->assertStringContainsString( 'Success: 1 row(s) updated.', $output );

		$second_run = $this->run_backfill( [ 'live' => true ] );
		$this->assertStringContainsString( 'Every stored subtitle already matches what the sanitizer produces.', $second_run );
	}

	/**
	 * Dry-run is the default: it reports what would change and writes nothing.
	 */
	public function test_dry_run_reports_without_writing() {
		$post_id = self::factory()->post->create();
		$this->seed_raw_subtitle( $post_id, '<script>alert(1)</script><b>Bold</b> subtitle' );

		$output = $this->run_backfill();

		$this->assertSame( '<script>alert(1)</script><b>Bold</b> subtitle', $this->stored_subtitle( $post_id ), 'Dry-run must not write.' );
		$this->assertStringContainsString( '*** DRY RUN MODE', $output );
		$this->assertStringContainsString( 'Subtitle would be sanitized for: ' . $post_id, $output );
		$this->assertStringContainsString( '1 row(s) would change. Pass --live to write.', $output );
	}

	/**
	 * A value that already matches what the sanitizer produces is scanned but left
	 * alone, and is not counted as a change.
	 */
	public function test_leaves_an_already_sanitized_value_alone() {
		$post_id = self::factory()->post->create();
		$this->seed_raw_subtitle( $post_id, '<b>Already clean</b>' );

		$output = $this->run_backfill( [ 'live' => true ] );

		$this->assertSame( '<b>Already clean</b>', $this->stored_subtitle( $post_id ) );
		$this->assertStringContainsString( 'Scanned 1 post(s) with a non-empty subtitle.', $output );
		$this->assertStringContainsString( 'Every stored subtitle already matches what the sanitizer produces.', $output );
	}

	/**
	 * An empty string is not "stored"; the row is not scanned and is never touched.
	 */
	public function test_skips_an_empty_subtitle() {
		$post_id = self::factory()->post->create();
		$this->seed_raw_subtitle( $post_id, '' );

		$output = $this->run_backfill( [ 'live' => true ] );

		$this->assertStringContainsString( 'No posts carry a non-empty newspack_post_subtitle value.', $output );
	}

	/**
	 * A trashed post keeps its raw value; restoring it is what returns it to scope.
	 */
	public function test_skips_trashed_posts() {
		$post_id = self::factory()->post->create( [ 'post_status' => 'trash' ] );
		$this->seed_raw_subtitle( $post_id, '<script>alert(1)</script><b>Bold</b> subtitle' );

		$output = $this->run_backfill( [ 'live' => true ] );

		$this->assertSame( '<script>alert(1)</script><b>Bold</b> subtitle', $this->stored_subtitle( $post_id ) );
		$this->assertStringContainsString( 'No posts carry a non-empty newspack_post_subtitle value.', $output );
	}
}
