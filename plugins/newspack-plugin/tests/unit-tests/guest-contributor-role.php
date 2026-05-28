<?php
/**
 * Tests the Guest_Contributor_Role.
 *
 * @package Newspack\Tests
 */

use Newspack\Guest_Contributor_Role;

/**
 * Tests the Guest_Contributor_Role.
 */
class Newspack_Test_Guest_Contributor_Role extends WP_UnitTestCase {

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		wp_reset_postdata();
	}

	/**
	 * On a post with author.
	 */
	public function test_guest_contributor_role_get_dummy_email() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user = get_userdata( 1 );

		// Mirror the sanitization in get_dummy_email_address() — user_login could contain @.
		$expected = str_replace( '@', '', $user->user_login ) . '@' . $email_domain;

		$dummy_email = Guest_Contributor_Role::get_dummy_email_address( $user );
		$this->assertSame( $expected, $dummy_email );

		$dummy_email = Guest_Contributor_Role::get_dummy_email_address( $user->user_login );
		$this->assertSame( $expected, $dummy_email );
	}

	/**
	 * Test that @ in user_login is stripped when generating dummy email.
	 */
	public function test_guest_contributor_role_get_dummy_email_with_at_in_login() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();

		$user             = new stdClass();
		$user->user_login = 'legacy-author@old-domain.com';

		$expected = 'legacy-authorold-domain.com@' . $email_domain;

		$dummy_email = Guest_Contributor_Role::get_dummy_email_address( $user );
		$this->assertSame( $expected, $dummy_email );

		$dummy_email = Guest_Contributor_Role::get_dummy_email_address( $user->user_login );
		$this->assertSame( $expected, $dummy_email );
	}

	/**
	 * On a post with author.
	 */
	public function test_guest_contributor_role_dummy_email_hiding_default() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-contributor',
				'user_pass'  => '123',
				'user_email' => 'guest-contributor@' . $email_domain,
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);
		$post_id = \wp_insert_post(
			[
				'post_title'  => 'Title',
				'post_status' => 'publish',
				'post_author' => $user_id,
			]
		);
		global $wp_query;
		$wp_query = new WP_Query(
			[
				'p' => $post_id,
			]
		);
		$post = get_post( $post_id );
		setup_postdata( $post );

		self::assertEquals(
			Guest_Contributor_Role::should_display_author_email( true ),
			false,
			'Email should be hidden for a Guest Contributor with a dummy email.'
		);

		// Update the user's email address.
		\wp_update_user(
			[
				'ID'         => $user_id,
				'user_email' => 'guest-contributor@legit-domain.com',
			]
		);
		self::assertEquals(
			Guest_Contributor_Role::should_display_author_email( true ),
			true,
			'Email should be displayed for a Guest Contributor with a regular email.'
		);
	}

	/**
	 * On a post with no author.
	 */
	public function test_guest_contributor_role_dummy_email_hiding_no_author() {
		global $wp_query;
		$wp_query->is_singular = true;
		$should_hide = Guest_Contributor_Role::should_display_author_email( true );
		self::assertEquals( null, get_the_author_meta( 'ID' ) );
		self::assertEquals(
			true,
			$should_hide,
			'Function should run successfully even if post apparently has no author. This can happen with co-authors-plus Guest Authors.'
		);
	}

	/**
	 * Test should_display_coauthor_email returns false for guest contributors with dummy emails.
	 */
	public function test_should_display_coauthor_email_with_dummy_email() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-coauthor-1',
				'user_pass'  => '123',
				'user_email' => 'guest-coauthor-1@' . $email_domain,
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_coauthor_email( true, $user_id ),
			'Email should be hidden for a Guest Contributor with a dummy email.'
		);
	}

	/**
	 * Test should_display_coauthor_email returns true for guest contributors with real emails.
	 */
	public function test_should_display_coauthor_email_with_real_email() {
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-coauthor-2',
				'user_pass'  => '123',
				'user_email' => 'guest-coauthor-2@real-domain.com',
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);

		self::assertEquals(
			true,
			Guest_Contributor_Role::should_display_coauthor_email( true, $user_id ),
			'Email should be displayed for a Guest Contributor with a real email.'
		);
	}

	/**
	 * Test should_display_coauthor_email returns false when value is already false.
	 */
	public function test_should_display_coauthor_email_respects_false_value() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-coauthor-3',
				'user_pass'  => '123',
				'user_email' => 'guest-coauthor-3@real-domain.com',
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_coauthor_email( false, $user_id ),
			'Email should remain hidden when value is already false, even with real email.'
		);
	}

	/**
	 * Test should_display_coauthor_email returns true for regular users.
	 */
	public function test_should_display_coauthor_email_for_regular_user() {
		$user_id = \wp_insert_user(
			[
				'user_login' => 'regular-author',
				'user_pass'  => '123',
				'user_email' => 'regular-author@domain.com',
				'role'       => 'author',
			]
		);

		self::assertEquals(
			true,
			Guest_Contributor_Role::should_display_coauthor_email( true, $user_id ),
			'Email should be displayed for regular users without the guest contributor role.'
		);
	}

	/**
	 * Test should_display_coauthor_email with user having multiple roles including guest contributor.
	 */
	public function test_should_display_coauthor_email_with_multiple_roles() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'multi-role-user',
				'user_pass'  => '123',
				'user_email' => 'multi-role@' . $email_domain,
				'role'       => 'author',
			]
		);

		$user = get_userdata( $user_id );
		$user->add_role( Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME );

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_coauthor_email( true, $user_id ),
			'Email should be hidden for users with guest contributor role and dummy email, even if they have other roles.'
		);
	}

	/**
	 * Test should_display_author_email respects false value.
	 */
	public function test_should_display_author_email_respects_false_value() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-author-false',
				'user_pass'  => '123',
				'user_email' => 'guest-author-false@' . $email_domain,
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);
		$post_id = \wp_insert_post(
			[
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
				'post_author' => $user_id,
			]
		);
		global $wp_query;
		$wp_query = new WP_Query(
			[
				'p' => $post_id,
			]
		);
		$post = get_post( $post_id );
		setup_postdata( $post );

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_author_email( false ),
			'should_display_author_email should return false when value is already false.'
		);
	}

	/**
	 * Test should_display_author_email with user having multiple roles including guest contributor.
	 */
	public function test_should_display_author_email_with_multiple_roles() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'multi-role-author',
				'user_pass'  => '123',
				'user_email' => 'multi-role-author@' . $email_domain,
				'role'       => 'author',
			]
		);

		$user = get_userdata( $user_id );
		$user->add_role( Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME );

		$post_id = \wp_insert_post(
			[
				'post_title'  => 'Multi Role Post',
				'post_status' => 'publish',
				'post_author' => $user_id,
			]
		);
		global $wp_query;
		$wp_query = new WP_Query(
			[
				'p' => $post_id,
			]
		);
		$post = get_post( $post_id );
		setup_postdata( $post );

		self::assertEquals(
			false,
			Guest_Contributor_Role::should_display_author_email( true ),
			'Email should be hidden for users with guest contributor role and dummy email, even if they have other roles.'
		);
	}

	/**
	 * Test should_display_author_email returns true when not on author or singular page.
	 */
	public function test_should_display_author_email_not_on_author_or_singular() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();
		$user_id = \wp_insert_user(
			[
				'user_login' => 'guest-home-page',
				'user_pass'  => '123',
				'user_email' => 'guest-home@' . $email_domain,
				'role'       => Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME,
			]
		);

		global $wp_query;
		$wp_query = new WP_Query();
		$wp_query->is_home = true;

		self::assertEquals(
			true,
			Guest_Contributor_Role::should_display_author_email( true ),
			'Email should not be filtered when not on author or singular pages.'
		);
	}

	/**
	 * Test is_dummy_email_address identifies dummy emails correctly.
	 */
	public function test_is_dummy_email_address() {
		$email_domain = Guest_Contributor_Role::get_dummy_email_domain();

		self::assertTrue(
			Guest_Contributor_Role::is_dummy_email_address( 'test@' . $email_domain ),
			'Should identify dummy email with default domain.'
		);

		self::assertFalse(
			Guest_Contributor_Role::is_dummy_email_address( 'test@real-domain.com' ),
			'Should not identify real email as dummy.'
		);
	}
}
