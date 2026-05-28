<?php
/**
 * Unit tests for the Post_Meta class.
 *
 * @package Newspack\Tests\Unit\Collections
 */

namespace Newspack\Tests\Unit\Collections;

use WP_UnitTestCase;
use Newspack\Collections\Post_Meta;

/**
 * Test the Post_Meta functionality.
 */
class Test_Post_Meta extends WP_UnitTestCase {

	use Traits\Trait_Collections_Test;
	use Traits\Trait_Meta_Handler_Test;

	/**
	 * Set up the test environment.
	 */
	public function set_up() {
		parent::set_up();

		// Register the meta field.
		Post_Meta::register_meta();
	}

	/**
	 * Test that the post meta is registered.
	 *
	 * @covers \Newspack\Collections\Post_Meta::register_meta
	 * @covers \Newspack\Collections\Post_Meta::register_meta_for_object
	 * @covers \Newspack\Collections\Post_Meta::get_meta_definitions
	 */
	public function test_register_meta() {
		$this->assertMetaFieldsRegistered( Post_Meta::class, 'post', 'post' );
		$this->assertFrontendMetaDefinitionsValid( Post_Meta::class );
		$post_id = $this->factory()->post->create();
		$this->assertMetaValueCanBeSetAndRetrieved( Post_Meta::class, $post_id, 'is_cover_story', true );
	}

	/**
	 * Test that the meta is sanitized as a number.
	 *
	 * @covers \Newspack\Collections\Post_Meta::set
	 * @covers \Newspack\Collections\Post_Meta::get
	 * @covers \Newspack\Collections\Post_Meta::get_meta_definitions
	 */
	public function test_post_meta_sanitization() {
		$post_id = $this->factory()->post->create();
		Post_Meta::set( $post_id, 'post_order', '123abc' );
		$this->assertSame( '123', Post_Meta::get( $post_id, 'post_order' ), 'Meta value should be sanitized to number.' );
	}

	/**
	 * Test that the auth callback works (user can edit posts).
	 *
	 * @covers \Newspack\Collections\Post_Meta::auth_callback
	 */
	public function test_post_meta_auth_callback() {
		$this->set_current_user_role( 'administrator' );
		$this->assertTrue( Post_Meta::auth_callback(), 'Auth callback should return true for user with edit_posts.' );
	}
}
