<?php
/**
 * Tests for the Newspack\Private_Tags class — public-facing frontend filters:
 * tag link lists, tag clouds, tag archive/feed 404s, and CSS class stripping.
 *
 * Core behavior lives in test-private-tags.php; integrations in
 * test-private-tags-integrations.php.
 *
 * @package Newspack\Tests
 */

use Newspack\Private_Tags;

require_once __DIR__ . '/traits/trait-private-tags-test-helper.php';

/**
 * Private_Tags frontend filter tests.
 *
 * @group private-tags
 */
class Test_Private_Tags_Frontend extends WP_UnitTestCase {

	use Private_Tags_Test_Helper;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->enable_private_tags_feature();
		$this->reset_private_tags_state();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$this->reset_private_tags_state();
		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// filter_tag_links().
	// -----------------------------------------------------------------

	/**
	 * Filter tag links returns unchanged in admin.
	 */
	public function test_filter_tag_links_returns_unchanged_in_admin() {
		set_current_screen( 'edit-post' );
		$links = [ '<a href="x">Private</a>', '<a href="y">Public</a>' ];
		$this->assertSame( $links, Private_Tags::filter_tag_links( $links ) );
		set_current_screen( 'front' );
	}

	/**
	 * Filter tag links returns unchanged when behavior disabled.
	 */
	public function test_filter_tag_links_returns_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'       => false,
				'tag_links' => false,
			]
		);
		$links = [ '<a href="x">Private</a>' ];
		$this->assertSame( $links, Private_Tags::filter_tag_links( $links ) );
	}

	/**
	 * Filter tag links removes private tag and keeps public.
	 */
	public function test_filter_tag_links_removes_private_tag_and_keeps_public() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private, $public ], 'post_tag' );

		// Simulate "in the loop" for this post.
		global $wp_query, $post;
		$prev_post             = $post;
		$post                  = get_post( $post_id );
		$wp_query->in_the_loop = true;
		$GLOBALS['post']       = $post;

		$out = Private_Tags::filter_tag_links( [ '<a>placeholder</a>' ] );

		$wp_query->in_the_loop = false;
		$GLOBALS['post']       = $prev_post;

		$this->assertCount( 1, $out, 'Only the public tag should remain.' );
		$this->assertStringContainsString( 'Jazz', $out[0] );
		$this->assertStringNotContainsString( 'Beastie', $out[0] );
	}

	/**
	 * Filter tag links empty when all tags private.
	 */
	public function test_filter_tag_links_empty_when_all_tags_private() {
		$private = $this->make_private_tag( 'Beastie' );
		$post_id = $this->factory()->post->create();
		wp_set_object_terms( $post_id, [ $private ], 'post_tag' );

		global $wp_query, $post;
		$prev_post             = $post;
		$post                  = get_post( $post_id );
		$wp_query->in_the_loop = true;
		$GLOBALS['post']       = $post;

		$out = Private_Tags::filter_tag_links( [ '<a>placeholder</a>' ] );

		$wp_query->in_the_loop = false;
		$GLOBALS['post']       = $prev_post;

		$this->assertSame( [], $out );
	}

	// -----------------------------------------------------------------
	// filter_tag_cloud().
	// -----------------------------------------------------------------

	/**
	 * Filter tag cloud removes private tags.
	 */
	public function test_filter_tag_cloud_removes_private_tags() {
		$private = $this->make_private_tag( 'Beastie' );
		$public  = $this->make_public_tag( 'Jazz' );
		$tags    = [ get_term( $private, 'post_tag' ), get_term( $public, 'post_tag' ) ];

		$out = Private_Tags::filter_tag_cloud( $tags );

		$this->assertCount( 1, $out );
		$this->assertSame( 'Jazz', reset( $out )->name );
	}

	/**
	 * Filter tag cloud keeps terms from other taxonomies.
	 */
	public function test_filter_tag_cloud_keeps_terms_from_other_taxonomies() {
		$private = $this->make_private_tag( 'Beastie' );
		$cat_id  = $this->factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'News',
			]
		);

		$tags = [ get_term( $private, 'post_tag' ), get_term( $cat_id, 'category' ) ];
		$out  = Private_Tags::filter_tag_cloud( $tags );

		// Private tag removed; category preserved.
		$this->assertCount( 1, $out );
		$this->assertSame( 'News', reset( $out )->name );
	}

	/**
	 * Filter tag cloud returns unchanged when behavior disabled.
	 */
	public function test_filter_tag_cloud_returns_unchanged_when_behavior_disabled() {
		$this->set_private_tags_settings(
			[
				'all'        => false,
				'tag_clouds' => false,
			]
		);
		$private = $this->make_private_tag( 'Beastie' );
		$tags    = [ get_term( $private, 'post_tag' ) ];
		$this->assertSame( $tags, Private_Tags::filter_tag_cloud( $tags ) );
	}

	// -----------------------------------------------------------------
	// disable_tag_archives() — archive vs feed gating.
	// -----------------------------------------------------------------

	/**
	 * Build a WP_Query mock that simulates a main-query tag archive (optionally a feed).
	 *
	 * @param int  $term_id The tag term ID returned by get_queried_object().
	 * @param bool $is_feed Whether the simulated request is a feed.
	 * @return WP_Query
	 */
	private function build_archive_query_stub( $term_id, $is_feed = false ) {
		$term  = get_term( $term_id, 'post_tag' );
		$query = $this->getMockBuilder( WP_Query::class )
			->onlyMethods( [ 'is_main_query', 'is_tag', 'is_feed', 'get_queried_object' ] )
			->getMock();
		$query->method( 'is_main_query' )->willReturn( true );
		$query->method( 'is_tag' )->willReturn( true );
		$query->method( 'is_feed' )->willReturn( $is_feed );
		$query->method( 'get_queried_object' )->willReturn( $term );
		return $query;
	}

	/**
	 * Disable tag archives sets 404 for private tag archive.
	 */
	public function test_disable_tag_archives_sets_404_for_private_tag_archive() {
		$private = $this->make_private_tag( 'Beastie' );
		$query   = $this->build_archive_query_stub( $private, false );

		Private_Tags::disable_tag_archives( $query );

		$this->assertTrue( $query->is_404(), 'Private tag archive should set 404.' );
	}

	/**
	 * Disable tag archives does not 404 public tag.
	 */
	public function test_disable_tag_archives_does_not_404_public_tag() {
		$public = $this->make_public_tag( 'Jazz' );
		$query  = $this->build_archive_query_stub( $public, false );

		Private_Tags::disable_tag_archives( $query );

		$this->assertFalse( $query->is_404() );
	}

	/**
	 * Disable tag archives respects archives behavior flag.
	 */
	public function test_disable_tag_archives_respects_archives_behavior_flag() {
		$this->set_private_tags_settings(
			[
				'all'      => false,
				'archives' => false,
				'feeds'    => true,
			]
		);
		$private = $this->make_private_tag( 'Beastie' );
		$query   = $this->build_archive_query_stub( $private, false );

		Private_Tags::disable_tag_archives( $query );

		// Archive 404 disabled — private archive should pass through.
		$this->assertFalse( $query->is_404() );
	}

	/**
	 * Disable tag archives 404s feed when feeds enabled.
	 */
	public function test_disable_tag_archives_404s_feed_when_feeds_enabled() {
		$private = $this->make_private_tag( 'Beastie' );
		$query   = $this->build_archive_query_stub( $private, true );

		Private_Tags::disable_tag_archives( $query );

		$this->assertTrue( $query->is_404() );
	}

	/**
	 * Disable tag archives respects feeds behavior flag.
	 */
	public function test_disable_tag_archives_respects_feeds_behavior_flag() {
		$this->set_private_tags_settings(
			[
				'all'      => false,
				'archives' => true,
				'feeds'    => false,
			]
		);
		$private = $this->make_private_tag( 'Beastie' );
		$query   = $this->build_archive_query_stub( $private, true );

		Private_Tags::disable_tag_archives( $query );

		// Feeds 404 disabled — private feed should pass through.
		$this->assertFalse( $query->is_404() );
	}

	// -----------------------------------------------------------------
	// post_class / body_class — CSS class stripping.
	// -----------------------------------------------------------------

	/**
	 * Filter post class strips private tag class.
	 */
	public function test_filter_post_class_strips_private_tag_class() {
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$classes = [ 'post', 'tag-jazz', 'tag-' . $slug ];

		$out = Private_Tags::filter_post_class( $classes );

		$this->assertNotContains( 'tag-' . $slug, $out );
		$this->assertContains( 'tag-jazz', $out );
		$this->assertContains( 'post', $out );
	}

	/**
	 * Filter body class strips private tag class.
	 */
	public function test_filter_body_class_strips_private_tag_class() {
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$classes = [ 'tag-' . $slug, 'home' ];

		$out = Private_Tags::filter_body_class( $classes );

		$this->assertNotContains( 'tag-' . $slug, $out );
		$this->assertContains( 'home', $out );
	}

	/**
	 * Filter post class unchanged when css classes disabled.
	 */
	public function test_filter_post_class_unchanged_when_css_classes_disabled() {
		$this->set_private_tags_settings(
			[
				'all'         => false,
				'css_classes' => false,
			]
		);
		$private = $this->make_private_tag( 'Beastie' );
		$slug    = get_term( $private, 'post_tag' )->slug;
		$classes = [ 'tag-' . $slug ];

		$this->assertSame( $classes, Private_Tags::filter_post_class( $classes ) );
	}
}
