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
	 * Test that a revision is not accessible.
	 */
	public function test_revision_is_not_accessible() {
		$post        = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$revision_id = wp_save_post_revision( $post->ID );
		$this->assertFalse( Lite_Site::is_post_accessible( get_post( $revision_id ) ) );
	}
}
