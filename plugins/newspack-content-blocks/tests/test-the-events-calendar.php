<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class TheEventsCalendarTest
 *
 * @package Newspack_Content_Blocks
 */

/**
 * The Events Calendar integration: events can be picked in Content Loop and show their schedule.
 */
class TheEventsCalendarTest extends WP_UnitTestCase { // phpcs:ignore

	/**
	 * Register a stand-in for TEC's event post type.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type( 'tribe_events', [ 'public' => true ] );
	}

	/**
	 * Remove the stand-in post type.
	 */
	public function tear_down() {
		unregister_post_type( 'tribe_events' );
		parent::tear_down();
	}

	/**
	 * Events get the post type support the Content Loop editor filters post types by.
	 */
	public function test_event_post_type_supports_content_loop() {
		$args = apply_filters( 'tribe_events_register_event_type_args', [ 'supports' => [ 'title' ] ] );
		$this->assertContains( 'newspack_blocks', $args['supports'] );
	}

	/**
	 * An event's displayed date is its start date, not its publish date.
	 */
	public function test_event_displayed_date_is_start_date() {
		$event_id = self::factory()->post->create( [ 'post_type' => 'tribe_events' ] );
		update_post_meta( $event_id, '_EventStartDate', '2026-12-01 10:00:00' );

		$this->assertSame(
			mysql_to_rfc3339( '2026-12-01 10:00:00' ),
			apply_filters( 'newspack_blocks_displayed_post_date', 'publish-date', get_post( $event_id ) )
		);
	}

	/**
	 * Other post types keep their date and footer.
	 */
	public function test_non_event_posts_are_untouched() {
		$post = get_post( self::factory()->post->create() );
		$this->assertSame( 'publish-date', apply_filters( 'newspack_blocks_displayed_post_date', 'publish-date', $post ) );
		$this->assertSame( 'publish-date', apply_filters( 'newspack_blocks_formatted_displayed_post_date', 'publish-date', $post ) );
		$this->assertSame( 'footer', apply_filters( 'newspack_blocks_article_meta_footer', 'footer', $post ) );
	}

	/**
	 * Without TEC's template functions, an event keeps its formatted date and footer.
	 */
	public function test_event_without_tec_functions_keeps_formatted_date_and_footer() {
		$event = get_post( self::factory()->post->create( [ 'post_type' => 'tribe_events' ] ) );
		$this->assertSame( 'publish-date', apply_filters( 'newspack_blocks_formatted_displayed_post_date', 'publish-date', $event ) );
		$this->assertSame( 'footer', apply_filters( 'newspack_blocks_article_meta_footer', 'footer', $event ) );
	}
}
