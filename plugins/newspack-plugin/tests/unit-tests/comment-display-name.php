<?php
/**
 * Tests the Comment Display Name functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Comment_Display_Name;

/**
 * Tests the Comment Display Name functionality.
 *
 * @group comment-display-name
 */
class Newspack_Test_Comment_Display_Name extends WP_UnitTestCase {

	/**
	 * Create a reader with a generic (email-derived) display name.
	 *
	 * @param string $email Reader email.
	 * @return int User ID.
	 */
	private function create_generic_reader( $email = 'jane.doe@example.com' ) {
		$user_id = Reader_Activation::register_reader( $email );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Test that the display name field renders for a reader with a generic display name.
	 */
	public function test_renders_field_for_generic_display_name() {
		$user_id = $this->create_generic_reader();

		$output = Comment_Display_Name::render_display_name_field( '<submit />' );

		$this->assertStringContainsString( 'name="comment_display_name"', $output );
		$this->assertStringContainsString( 'required', $output );
		$this->assertStringContainsString( '<submit />', $output );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that the display name field does not render for a reader with a custom display name.
	 */
	public function test_does_not_render_for_custom_display_name() {
		$user_id = $this->create_generic_reader();
		wp_update_user(
			[
				'ID'           => $user_id,
				'display_name' => 'Jane Doe',
			]
		);

		$output = Comment_Display_Name::render_display_name_field( '<submit />' );

		$this->assertEquals( '<submit />', $output );

		wp_delete_user( $user_id );
	}

	/**
	 * Test that the display name field does not render for non-reader users.
	 */
	public function test_does_not_render_for_non_reader() {
		$admin_id = wp_insert_user(
			[
				'user_login' => 'test-admin',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'admin@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( $admin_id );

		$output = Comment_Display_Name::render_display_name_field( '<submit />' );

		$this->assertEquals( '<submit />', $output );

		wp_delete_user( $admin_id );
	}

	/**
	 * Test that the display name field does not render for logged-out users.
	 */
	public function test_does_not_render_for_logged_out() {
		wp_set_current_user( 0 );

		$output = Comment_Display_Name::render_display_name_field( '<submit />' );

		$this->assertEquals( '<submit />', $output );
	}

	/**
	 * Test that validation rejects an empty display name.
	 */
	public function test_validate_rejects_empty_display_name() {
		$user_id = $this->create_generic_reader();
		$_POST['comment_display_name'] = '';

		$commentdata = [
			'comment_post_ID' => $this->factory->post->create(),
			'user_id'         => $user_id,
		];

		$this->expectException( WPDieException::class );
		Comment_Display_Name::validate_display_name( $commentdata );

		wp_delete_user( $user_id );
		unset( $_POST['comment_display_name'] );
	}

	/**
	 * Test that validation rejects a display name that matches the generic pattern.
	 */
	public function test_validate_rejects_generic_display_name() {
		$user_id = $this->create_generic_reader( 'john.smith@example.com' );
		$_POST['comment_display_name'] = 'john.smith';

		$commentdata = [
			'comment_post_ID' => $this->factory->post->create(),
			'user_id'         => $user_id,
		];

		$this->expectException( WPDieException::class );
		Comment_Display_Name::validate_display_name( $commentdata );

		wp_delete_user( $user_id );
		unset( $_POST['comment_display_name'] );
	}

	/**
	 * Test that validation passes with a valid display name and sets comment_author.
	 */
	public function test_validate_accepts_valid_display_name() {
		$user_id = $this->create_generic_reader();
		$_POST['comment_display_name'] = 'Jane Doe';

		$commentdata = [
			'comment_post_ID' => $this->factory->post->create(),
			'user_id'         => $user_id,
		];

		$result = Comment_Display_Name::validate_display_name( $commentdata );
		$this->assertEquals( 'Jane Doe', $result['comment_author'] );

		wp_delete_user( $user_id );
		unset( $_POST['comment_display_name'] );
	}

	/**
	 * Test that validation passes for non-reader users without the field.
	 */
	public function test_validate_skips_non_reader() {
		$admin_id = wp_insert_user(
			[
				'user_login' => 'test-admin-2',
				'user_pass'  => wp_generate_password(),
				'user_email' => 'admin2@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( $admin_id );

		$commentdata = [
			'comment_post_ID' => $this->factory->post->create(),
			'user_id'         => $admin_id,
		];

		$result = Comment_Display_Name::validate_display_name( $commentdata );
		$this->assertEquals( $commentdata, $result );

		wp_delete_user( $admin_id );
	}

	/**
	 * Test that validation saves the display name to the user profile and updates comment_author.
	 */
	public function test_validate_saves_display_name() {
		$user_id = $this->create_generic_reader();
		$_POST['comment_display_name'] = 'Jane Doe';

		$commentdata = [
			'comment_post_ID' => $this->factory->post->create(),
			'user_id'         => $user_id,
			'comment_author'  => 'jane-doe', // Generic name set by WP before preprocess_comment.
		];

		$result = Comment_Display_Name::validate_display_name( $commentdata );

		// Comment author should be updated.
		$this->assertEquals( 'Jane Doe', $result['comment_author'] );

		// User profile should be updated.
		$user = get_userdata( $user_id );
		$this->assertEquals( 'Jane Doe', $user->display_name );
		$this->assertEquals( 'Jane', $user->first_name );
		$this->assertEquals( 'Doe', $user->last_name );
		$this->assertFalse( Reader_Activation::reader_has_generic_display_name( $user_id ) );

		wp_delete_user( $user_id );
		unset( $_POST['comment_display_name'] );
	}

	/**
	 * Test that validation handles single-word display names.
	 */
	public function test_validate_saves_single_word_name() {
		$user_id = $this->create_generic_reader();
		$_POST['comment_display_name'] = 'Madonna';

		$commentdata = [
			'comment_post_ID' => $this->factory->post->create(),
			'user_id'         => $user_id,
			'comment_author'  => 'jane-doe',
		];

		$result = Comment_Display_Name::validate_display_name( $commentdata );

		$this->assertEquals( 'Madonna', $result['comment_author'] );

		$user = get_userdata( $user_id );
		$this->assertEquals( 'Madonna', $user->display_name );
		$this->assertFalse( Reader_Activation::reader_has_generic_display_name( $user_id ) );

		wp_delete_user( $user_id );
		unset( $_POST['comment_display_name'] );
	}
}
