<?php
/**
 * Tests for the `backfill-post-subtitles` CLI command.
 *
 * This suite is about which rows the command reads, writes, and reports, not about
 * what the sanitizer does to a given string — see post-subtitle-sanitizer-mock.php for
 * why a stub stands in for the real one.
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
	 * reads and writes. Only meaningful when the post has a single subtitle row; use
	 * stored_subtitles() for a post seeded with more than one.
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
	 * Every stored value for a post, ordered by meta_id — for a post seeded with more
	 * than one `newspack_post_subtitle` row.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string[]
	 */
	private function stored_subtitles( int $post_id ): array {
		global $wpdb;
		return $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'newspack_post_subtitle' ORDER BY meta_id",
				$post_id
			)
		);
	}

	/**
	 * Adds a second (or further) `newspack_post_subtitle` row to a post without
	 * touching any row seed_raw_subtitle() already wrote — the duplicate-row shape
	 * WordPress allows even though this meta key is registered `single => true`.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $value   Raw meta value.
	 *
	 * @return void
	 */
	private function seed_additional_raw_subtitle( int $post_id, string $value ) {
		global $wpdb;
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
		$this->assertStringContainsString( 'Scanned 1 subtitle row(s).', $output );
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

	/**
	 * A literal backslash in the sanitized value survives the write.
	 *
	 * Unlike update_post_meta(), update_metadata_by_mid() does not unslash its value —
	 * a wp_slash() call creeping back into the write path would have this silently
	 * strip every backslash.
	 */
	public function test_preserves_a_literal_backslash_when_live() {
		$post_id = self::factory()->post->create();
		$this->seed_raw_subtitle( $post_id, '<script>x</script>C:\\Temp' );

		$this->run_backfill( [ 'live' => true ] );

		$this->assertSame( 'xC:\\Temp', $this->stored_subtitle( $post_id ) );
	}

	/**
	 * A revision keeps its own raw value; this meta key is registered without
	 * revisions_enabled, so nothing in core's revision machinery ever reads or restores
	 * a revision's own copy, and there is nothing to sanitize it for.
	 */
	public function test_excludes_revisions() {
		$parent_id   = self::factory()->post->create();
		$revision_id = self::factory()->post->create(
			[
				'post_type'   => 'revision',
				'post_parent' => $parent_id,
			]
		);
		$this->seed_raw_subtitle( $revision_id, '<script>alert(1)</script><b>Bold</b> subtitle' );

		$output = $this->run_backfill( [ 'live' => true ] );

		$this->assertSame( '<script>alert(1)</script><b>Bold</b> subtitle', $this->stored_subtitle( $revision_id ) );
		$this->assertStringContainsString( 'No posts carry a non-empty newspack_post_subtitle value.', $output );
	}

	/**
	 * A non-string stored value (corrupted or written outside the registered `string`
	 * type) is scanned but skipped, rather than cast to the literal string "Array" and
	 * written back.
	 */
	public function test_skips_a_non_string_meta_value() {
		global $wpdb;
		$post_id = self::factory()->post->create();
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->postmeta,
			[
				'post_id'  => $post_id,
				'meta_key' => 'newspack_post_subtitle',
			]
		);
		$serialized = serialize( [ 'unexpected' => 'array' ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->postmeta,
			[
				'post_id'    => $post_id,
				'meta_key'   => 'newspack_post_subtitle',
				'meta_value' => $serialized, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $post_id, 'post_meta' );

		$output = $this->run_backfill( [ 'live' => true ] );

		$this->assertSame( $serialized, $this->stored_subtitle( $post_id ) );
		$this->assertStringContainsString( 'Scanned 1 subtitle row(s).', $output );
		$this->assertStringContainsString( 'Every stored subtitle already matches what the sanitizer produces.', $output );
	}

	/**
	 * A post with two `newspack_post_subtitle` rows — a shape WordPress allows even
	 * though this meta key is registered `single => true` — has each row sanitized by
	 * its own meta_id, rather than one row's read value overwriting both on write.
	 */
	public function test_sanitizes_duplicate_subtitle_rows_independently() {
		$post_id = self::factory()->post->create();
		$this->seed_raw_subtitle( $post_id, '<b>Already clean</b>' );
		$this->seed_additional_raw_subtitle( $post_id, '<script>alert(1)</script><b>Bold</b> subtitle' );

		$output = $this->run_backfill( [ 'live' => true ] );

		$this->assertSame(
			[ '<b>Already clean</b>', 'alert(1)<b>Bold</b> subtitle' ],
			$this->stored_subtitles( $post_id ),
			'The already-clean row must survive untouched and the dirty row must be sanitized, not collapsed into one value.'
		);
		$this->assertStringContainsString( 'Scanned 2 subtitle row(s).', $output );
	}

	/**
	 * A failed write is reported, and the command exits non-zero rather than letting
	 * WP-CLI report success while an unsanitized row remains stored.
	 */
	public function test_exits_nonzero_when_a_write_fails() {
		$post_id = self::factory()->post->create();
		$this->seed_raw_subtitle( $post_id, '<script>alert(1)</script><b>Bold</b> subtitle' );

		add_filter( 'update_post_metadata_by_mid', '__return_false' );
		try {
			$output = $this->run_backfill( [ 'live' => true ] );
		} finally {
			remove_filter( 'update_post_metadata_by_mid', '__return_false' );
		}

		$this->assertSame( '<script>alert(1)</script><b>Bold</b> subtitle', $this->stored_subtitle( $post_id ), 'A failed write must not be reported as changed.' );
		$this->assertStringContainsString( 'update_metadata_by_mid() failed for: ' . $post_id, $output );
		$this->assertSame( 1, WP_CLI::$halt_code );
	}

	/**
	 * A row whose stored value no longer matches what the upfront batch read saw is
	 * left alone rather than overwritten — guarding against a save that lands in the
	 * gap between that read and this row's own write (an editor's own edit, or another
	 * run of this command overlapping this one).
	 */
	public function test_write_sanitized_value_skips_a_row_that_changed_since_it_was_read() {
		$post_id = self::factory()->post->create();
		$this->seed_raw_subtitle( $post_id, '<script>alert(1)</script><b>Bold</b> subtitle' );

		global $wpdb;
		$meta_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'newspack_post_subtitle'",
				$post_id
			)
		);

		// The row as a batch read saw it, before a save lands in the gap.
		$stale_row = (object) [
			'meta_id'    => $meta_id,
			'post_id'    => $post_id,
			'meta_value' => '<script>alert(1)</script><b>Bold</b> subtitle', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		];

		// A save on the same row — same meta_id — lands in the gap before the write.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->postmeta,
			[ 'meta_value' => "An editor's newer subtitle" ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			[ 'meta_id' => $meta_id ]
		);
		wp_cache_delete( $post_id, 'post_meta' );

		$write_sanitized_value = new ReflectionMethod( Post_Subtitle_Backfill::class, 'write_sanitized_value' );
		$write_sanitized_value->setAccessible( true );
		$result = $write_sanitized_value->invoke( null, $stale_row, 'alert(1)<b>Bold</b> subtitle' );

		$this->assertSame( 'concurrent-change', $result );
		$this->assertSame( "An editor's newer subtitle", $this->stored_subtitle( $post_id ), 'The newer value must survive untouched.' );
	}
}
