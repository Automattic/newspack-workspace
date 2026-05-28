<?php
/**
 * Tests for the Co_Authors_Plus_RSS_Feed integration.
 *
 * @package Newspack\Tests
 */

// Mock get_coauthors() to simulate Co-Authors Plus being active.
// Uses a global so individual tests can control the return value.
// The `function_exists( 'get_coauthors' )` guard in Co_Authors_Plus_RSS_Feed cannot be
// exercised once this mock is defined, but that branch is trivially correct.
require_once __DIR__ . '/../../mocks/co-authors-plus-mocks.php';

/**
 * Tests the Co_Authors_Plus_RSS_Feed integration.
 */
class Newspack_Test_CAP_RSS_Feed extends WP_UnitTestCase {

	/**
	 * Reset state before each test.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['_test_cap_coauthors'] = [];
		global $wp_query;
		$wp_query->is_feed = false;
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		parent::tear_down();
		unset( $GLOBALS['_test_cap_coauthors'] );
		global $wp_query;
		$wp_query->is_feed = false;
		wp_reset_postdata();
	}

	/**
	 * Build a mock coauthor object.
	 *
	 * @param string $display_name Display name.
	 * @return object
	 */
	private function make_coauthor( $display_name ) {
		return (object) [ 'display_name' => $display_name ];
	}

	/**
	 * Apply the the_author filter with a controlled feed context.
	 *
	 * @param bool $is_feed Whether to simulate a feed request.
	 * @param int  $post_id Optional post ID to set up loop context.
	 * @return string Filtered author string.
	 */
	private function filter_author( $is_feed, $post_id = null ) {
		global $wp_query, $post;
		if ( $post_id ) {
			$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );
		}
		$wp_query->is_feed = $is_feed;
		return apply_filters( 'the_author', 'Original Author' );
	}

	/**
	 * Returns the original author outside of a feed context.
	 */
	public function test_returns_original_author_outside_feed() {
		$post_id = $this->factory->post->create();
		$GLOBALS['_test_cap_coauthors'] = [ $this->make_coauthor( 'Jane Smith' ) ];

		$this->assertSame( 'Original Author', $this->filter_author( false, $post_id ) );
	}

	/**
	 * Returns the original author when get_coauthors returns an empty array.
	 */
	public function test_returns_original_author_when_no_coauthors() {
		$post_id = $this->factory->post->create();
		$GLOBALS['_test_cap_coauthors'] = [];

		$this->assertSame( 'Original Author', $this->filter_author( true, $post_id ) );
	}

	/**
	 * Joins two co-authors with "and" in a feed.
	 */
	public function test_returns_combined_coauthor_names_in_feed() {
		$post_id = $this->factory->post->create();
		$GLOBALS['_test_cap_coauthors'] = [
			$this->make_coauthor( 'Jane Smith' ),
			$this->make_coauthor( 'John Doe' ),
		];

		$this->assertSame( 'Jane Smith and John Doe', $this->filter_author( true, $post_id ) );
	}

	/**
	 * Joins three or more co-authors with commas and an Oxford comma before "and".
	 */
	public function test_returns_oxford_comma_separated_coauthor_names_in_feed() {
		$post_id = $this->factory->post->create();
		$GLOBALS['_test_cap_coauthors'] = [
			$this->make_coauthor( 'Alice' ),
			$this->make_coauthor( 'Bob' ),
			$this->make_coauthor( 'Carol' ),
		];

		$this->assertSame( 'Alice, Bob, and Carol', $this->filter_author( true, $post_id ) );
	}

	/**
	 * Strips HTML tags and decodes entities from display names.
	 */
	public function test_strips_html_and_decodes_entities_in_feed() {
		$post_id = $this->factory->post->create();
		$GLOBALS['_test_cap_coauthors'] = [ $this->make_coauthor( '<b>Jane &amp; Smith</b>' ) ];

		$this->assertSame( 'Jane & Smith', $this->filter_author( true, $post_id ) );
	}
}
