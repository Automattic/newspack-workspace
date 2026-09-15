<?php
/**
 * Test Lite Site functionality.
 *
 * @package Newspack\Tests
 * @covers \Newspack\Lite_Site
 */

namespace Newspack\Tests\Unit\Lite_Site;

use Newspack\Lite_Site;

/**
 * Test class for Lite Site.
 *
 * @group lite-site
 */
class Test_Lite_Site extends \WP_UnitTestCase {
	/**
	 * Test that a published post is accessible.
	 */
	public function test_published_post_is_accessible() {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$this->assertTrue( Lite_Site::is_post_accessible( $post ) );
	}

	/**
	 * Test that a draft post is not accessible.
	 */
	public function test_draft_post_is_not_accessible() {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'draft' ] );
		$this->assertFalse( Lite_Site::is_post_accessible( $post ) );
	}

	/**
	 * Test that a private post is not accessible.
	 */
	public function test_private_post_is_not_accessible() {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'private' ] );
		$this->assertFalse( Lite_Site::is_post_accessible( $post ) );
	}

	/**
	 * Test that a password-protected post is not accessible.
	 */
	public function test_password_protected_post_is_not_accessible() {
		$post = $this->factory()->post->create_and_get(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
			]
		);
		$this->assertFalse( Lite_Site::is_post_accessible( $post ) );
	}

	/**
	 * Test that a draft page is not accessible.
	 */
	public function test_draft_page_is_not_accessible() {
		$post = $this->factory()->post->create_and_get(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
			]
		);
		$this->assertFalse( Lite_Site::is_post_accessible( $post ) );
	}

	/**
	 * Test that the archive listing excludes password-protected posts.
	 */
	public function test_archive_posts_exclude_password_protected() {
		$public_id    = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		$protected_id = $this->factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
			]
		);

		$ids = wp_list_pluck( Lite_Site::get_archive_posts(), 'ID' );

		$this->assertContains( $public_id, $ids );
		$this->assertNotContains( $protected_id, $ids );
	}

	/**
	 * Test that the archive listing excludes password-protected sticky posts.
	 */
	public function test_archive_posts_exclude_password_protected_sticky() {
		$protected_sticky_id = $this->factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
			]
		);
		update_option( 'sticky_posts', [ $protected_sticky_id ] );

		$ids = wp_list_pluck( Lite_Site::get_archive_posts(), 'ID' );

		$this->assertNotContains( $protected_sticky_id, $ids );
	}

	/**
	 * Test that the archive listing renders sticky posts first.
	 */
	public function test_archive_posts_put_sticky_first() {
		$older_id = $this->factory()->post->create(
			[
				'post_status' => 'publish',
				'post_date'   => '2020-01-01 10:00:00',
			]
		);
		$this->factory()->post->create( [ 'post_status' => 'publish' ] );
		update_option( 'sticky_posts', [ $older_id ] );

		$posts = Lite_Site::get_archive_posts();

		$this->assertSame( $older_id, $posts[0]->ID );
	}

	/**
	 * Test that style tags are removed from content along with their CSS.
	 */
	public function test_clean_content_removes_style_tags_and_their_css() {
		$content = '<p>Before</p><style>.my-class { color: red; }</style><p>After</p>';
		$cleaned = Lite_Site::clean_content( $content );

		$this->assertStringNotContainsString( '.my-class', $cleaned );
		$this->assertStringNotContainsString( 'color: red', $cleaned );
		$this->assertStringContainsString( '<p>Before</p>', $cleaned );
		$this->assertStringContainsString( '<p>After</p>', $cleaned );
	}

	/**
	 * Test that the byline defaults to the post author.
	 */
	public function test_get_authors_defaults_to_post_author() {
		$author_id = $this->factory()->user->create( [ 'display_name' => 'Account Author' ] );
		$post      = $this->factory()->post->create_and_get( [ 'post_author' => $author_id ] );

		$this->assertStringContainsString( 'Account Author', Lite_Site::get_authors( $post ) );
	}

	/**
	 * Test that an active custom byline is honored over the post author.
	 */
	public function test_get_authors_honors_custom_byline() {
		$author_id = $this->factory()->user->create( [ 'display_name' => 'Account Author' ] );
		$post      = $this->factory()->post->create_and_get( [ 'post_author' => $author_id ] );

		update_post_meta( $post->ID, \Newspack\Bylines::META_KEY_ACTIVE, 1 );
		update_post_meta( $post->ID, \Newspack\Bylines::META_KEY_BYLINE, 'By Custom Person with reporting from Jane Doe' );

		$authors = Lite_Site::get_authors( $post );

		$this->assertStringContainsString( 'Custom Person', $authors );
		$this->assertStringNotContainsString( 'Account Author', $authors );
	}

	/**
	 * Test that a content-gated post is not accessible.
	 */
	public function test_gated_post_is_not_accessible() {
		$post = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );

		$restrict = function( $restricted, $post_id ) use ( $post ) {
			return $post_id === $post->ID ? true : $restricted;
		};
		add_filter( 'newspack_is_post_restricted', $restrict, 10, 2 );

		$this->assertFalse( Lite_Site::is_post_accessible( $post ) );

		remove_filter( 'newspack_is_post_restricted', $restrict );
		$this->assertTrue( Lite_Site::is_post_accessible( $post ) );
	}

	/**
	 * Test that the archive listing excludes content-gated posts.
	 */
	public function test_archive_posts_exclude_gated_posts() {
		$public_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		$gated_id  = $this->factory()->post->create( [ 'post_status' => 'publish' ] );

		$restrict = function( $restricted, $post_id ) use ( $gated_id ) {
			return $post_id === $gated_id ? true : $restricted;
		};
		add_filter( 'newspack_is_post_restricted', $restrict, 10, 2 );

		$ids = wp_list_pluck( Lite_Site::get_archive_posts(), 'ID' );

		remove_filter( 'newspack_is_post_restricted', $restrict );

		$this->assertContains( $public_id, $ids );
		$this->assertNotContains( $gated_id, $ids );
	}

	/**
	 * Test that more than five sticky posts all render first.
	 */
	public function test_archive_posts_hoist_all_sticky_posts() {
		$sticky_ids = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$sticky_ids[] = $this->factory()->post->create(
				[
					'post_status' => 'publish',
					'post_date'   => sprintf( '2020-01-%02d 10:00:00', $i ),
				]
			);
		}
		$newest_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		update_option( 'sticky_posts', $sticky_ids );

		$ids = wp_list_pluck( Lite_Site::get_archive_posts(), 'ID' );

		$this->assertEqualsCanonicalizing( $sticky_ids, array_slice( $ids, 0, 6 ) );
		$this->assertSame( $newest_id, $ids[6] );
	}

	/**
	 * Test that sticky posts respect the categories setting.
	 */
	public function test_archive_posts_sticky_respect_categories() {
		$category_id    = $this->factory()->category->create();
		$in_category_id = $this->factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_category' => [ $category_id ],
			]
		);
		$sticky_other_category_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		update_option( 'sticky_posts', [ $sticky_other_category_id ] );
		update_option( Lite_Site::OPTION_NAME, [ 'categories' => [ $category_id ] ] );

		$ids = wp_list_pluck( Lite_Site::get_archive_posts(), 'ID' );

		$this->assertContains( $in_category_id, $ids );
		$this->assertNotContains( $sticky_other_category_id, $ids );
	}

	/**
	 * Test that an unclosed HTML comment does not blank the article.
	 */
	public function test_clean_content_survives_unclosed_comment() {
		$content = '<p>First paragraph.</p><!-- wp:html --><!-- unclosed'
			. str_repeat( '<p>More text to force backtracking.</p>', 50 );
		$cleaned = Lite_Site::clean_content( $content );

		$this->assertStringContainsString( 'First paragraph.', $cleaned );
	}

	/**
	 * Test that closed HTML comments are still removed.
	 */
	public function test_clean_content_removes_closed_comments() {
		$cleaned = Lite_Site::clean_content( '<p>Before</p><!-- wp:paragraph --><p>After</p>' );

		$this->assertStringNotContainsString( 'wp:paragraph', $cleaned );
		$this->assertStringContainsString( '<p>Before</p>', $cleaned );
		$this->assertStringContainsString( '<p>After</p>', $cleaned );
	}

	/**
	 * Test that a revision is not accessible.
	 */
	public function test_revision_is_not_accessible() {
		$post        = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$revision_id = wp_save_post_revision( $post->ID );
		$this->assertFalse( Lite_Site::is_post_accessible( get_post( $revision_id ) ) );
	}
}
