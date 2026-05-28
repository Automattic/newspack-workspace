<?php
/**
 * Tests for the Content Gifting class.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gifting;

/**
 * Tests for the Content Gifting class.
 */
class Test_Content_Gifting extends \WP_UnitTestCase {

	/**
	 * User ID
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Post ID
	 *
	 * @var int
	 */
	protected $post_id;

	/**
	 * Test set up.
	 */
	public function set_up() {
		parent::set_up();

		// Enable content gifting.
		update_option( Content_Gifting::META, true );

		$this->user_id = $this->factory->user->create();
		$this->post_id = $this->factory->post->create();

		wp_set_current_user( $this->user_id );

		// Mock the post having restrictions but not restricted for the current user.
		add_filter( 'newspack_post_has_restrictions', '__return_true' );
		add_filter( 'newspack_is_post_restricted', '__return_false' );
	}

	/**
	 * Test can_gift_post().
	 */
	public function test_can_gift_post() {
		$can_gift = Content_Gifting::can_gift_post( $this->post_id );
		$this->assertTrue( $can_gift );

		// Disable content gifting.
		update_option( Content_Gifting::META, false );
		$can_gift = Content_Gifting::can_gift_post( $this->post_id, true );
		$this->assertWPError( $can_gift );
		$this->assertEquals( 'not_enabled', $can_gift->get_error_code() );

		update_option( Content_Gifting::META, true );

		// Mock the post not having restrictions.
		remove_filter( 'newspack_post_has_restrictions', '__return_true' );
		$can_gift = Content_Gifting::can_gift_post( $this->post_id, true );
		$this->assertWPError( $can_gift );
		$this->assertEquals( 'not_restricted', $can_gift->get_error_code() );

		// Mock the post restricted for the current user.
		add_filter( 'newspack_post_has_restrictions', '__return_true' );
		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$can_gift = Content_Gifting::can_gift_post( $this->post_id, true );
		$this->assertWPError( $can_gift );
		$this->assertEquals( 'post_restricted', $can_gift->get_error_code() );
	}

	/**
	 * Test generate_key().
	 */
	public function test_generate_key() {
		$key = Content_Gifting::generate_key( $this->post_id );
		$this->assertNotEmpty( $key );
		$this->assertStringContainsString( $this->user_id . '|', $key );
	}

	/**
	 * Test generate_key() with existing key.
	 */
	public function test_generate_key_with_existing_key() {
		$key = Content_Gifting::generate_key( $this->post_id );
		$key2 = Content_Gifting::generate_key( $this->post_id );
		$this->assertEquals( $key, $key2 );
	}

	/**
	 * Test generate_key() with limit reached.
	 */
	public function test_generate_key_limit() {
		Content_Gifting::set_gifting_limit( 1 );
		$key = Content_Gifting::generate_key( $this->post_id );
		$this->assertNotEmpty( $key );

		$post2 = $this->factory->post->create();
		$key2 = Content_Gifting::generate_key( $post2 );
		$this->assertWPError( $key2 );
		$this->assertEquals( 'limit_reached', $key2->get_error_code() );

		Content_Gifting::set_gifting_limit( 2 );
		$key2 = Content_Gifting::generate_key( $post2 );
		$this->assertNotEmpty( $key2 );
	}

	/**
	 * Test get_key_data().
	 */
	public function test_get_key_data() {
		$key = Content_Gifting::generate_key( $this->post_id );
		$data = Content_Gifting::get_key_data( $this->post_id, $key );

		$this->assertNotEmpty( $data );
		$this->assertArrayHasKey( 'key', $data );
		$this->assertArrayHasKey( 'timestamp', $data );
		$this->assertStringContainsString( $data['key'], $key );
	}

	/**
	 * Test get_key_data() with expired key.
	 */
	public function test_get_key_data_expired() {
		$key = Content_Gifting::generate_key( $this->post_id );

		// Manually change the timestamp to be right after the expiration time.
		$expiration = Content_Gifting::get_expiration_time_in_seconds() + 1;
		$data = get_user_meta( $this->user_id, Content_Gifting::META, true );
		$data['keys'][ $this->post_id ]['timestamp'] = time() - $expiration;
		update_user_meta( $this->user_id, Content_Gifting::META, $data );

		$data = Content_Gifting::get_key_data( $this->post_id, $key );
		$this->assertEmpty( $data );
	}

	/**
	 * Test get_key_data() with custom expiration time.
	 */
	public function test_get_key_data_custom_expiration_time() {
		Content_Gifting::set_expiration_time( 1 );
		Content_Gifting::set_expiration_time_unit( 'hours' );

		$key = Content_Gifting::generate_key( $this->post_id );

		$data = get_user_meta( $this->user_id, Content_Gifting::META, true );
		$data['keys'][ $this->post_id ]['timestamp'] = time() - ( 60 * 60 * 2 ); // 2 hours ago.
		update_user_meta( $this->user_id, Content_Gifting::META, $data );

		$data = Content_Gifting::get_key_data( $this->post_id, $key );
		$this->assertEmpty( $data );
	}
}
