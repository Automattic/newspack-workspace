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
	 * Test that a revision is not accessible.
	 */
	public function test_revision_is_not_accessible() {
		$post        = $this->factory()->post->create_and_get( [ 'post_status' => 'publish' ] );
		$revision_id = wp_save_post_revision( $post->ID );
		$this->assertFalse( Lite_Site::is_post_accessible( get_post( $revision_id ) ) );
	}
}
