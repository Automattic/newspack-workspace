<?php
/**
 * Class Newsletters Test Bulk_Actions
 *
 * @package Newspack_Newsletters
 */

/**
 * Tests the newsletter list-table bulk visibility actions honour per-post edit
 * capability, so a user cannot flip `is_public` on a newsletter they cannot edit.
 */
class Bulk_Actions_Test extends WP_UnitTestCase {

	/**
	 * Ensure the newsletter CPT (and its capability mapping) is registered.
	 */
	public function set_up() {
		parent::set_up();
		\Newspack_Newsletters::register_cpt();
	}

	/**
	 * Create a published newsletter owned by an administrator.
	 *
	 * @return int Newsletter post ID.
	 */
	private function make_admin_newsletter() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		return self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
				'post_author' => $admin,
			]
		);
	}

	/**
	 * A Contributor cannot edit an administrator's newsletter, so the bulk
	 * handler must skip it: the `is_public` meta stays unset and the reported
	 * count is zero.
	 */
	public function test_bulk_handler_skips_newsletters_the_user_cannot_edit() {
		$newsletter  = $this->make_admin_newsletter();
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		wp_set_current_user( $contributor );

		$this->assertFalse( current_user_can( 'edit_post', $newsletter ), 'Precondition: contributor cannot edit the admin newsletter.' );

		$redirect = Newspack_Newsletters_Bulk_Actions::bulk_action_handler( 'http://example.test/', 'newsletters_public', [ $newsletter ] );

		$this->assertFalse( metadata_exists( 'post', $newsletter, 'is_public' ), 'is_public must not be written for a newsletter the user cannot edit.' );
		$this->assertStringContainsString( 'newsletters_public_count=0', urldecode( $redirect ), 'The reported count must not include skipped newsletters.' );
	}

	/**
	 * A user who can edit the newsletter updates it, and the count reflects it.
	 */
	public function test_bulk_handler_updates_newsletters_the_user_can_edit() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$newsletter = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
				'post_author' => $admin,
			]
		);

		$redirect = Newspack_Newsletters_Bulk_Actions::bulk_action_handler( 'http://example.test/', 'newsletters_public', [ $newsletter ] );

		$this->assertTrue( (bool) get_post_meta( $newsletter, 'is_public', true ), 'is_public should be set true for an editable newsletter.' );
		$this->assertStringContainsString( 'newsletters_public_count=1', urldecode( $redirect ) );
	}

	/**
	 * The non-public branch writes is_public=false and counts it, on an
	 * editable newsletter.
	 */
	public function test_bulk_handler_non_public_branch_updates_and_counts() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$newsletter = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'publish',
				'post_author' => $admin,
			]
		);
		update_post_meta( $newsletter, 'is_public', true );

		$redirect = Newspack_Newsletters_Bulk_Actions::bulk_action_handler( 'http://example.test/', 'newsletters_non_public', [ $newsletter ] );

		$this->assertTrue( metadata_exists( 'post', $newsletter, 'is_public' ), 'is_public should still be set.' );
		$this->assertFalse( (bool) get_post_meta( $newsletter, 'is_public', true ), 'is_public should be flipped to false.' );
		$this->assertStringContainsString( 'newsletters_non_public_count=1', urldecode( $redirect ) );
	}

	/**
	 * Ids that are not newsletters are ignored, even when the user can edit them.
	 */
	public function test_bulk_handler_ignores_non_newsletter_ids() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$post = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_author' => $admin,
			]
		);

		$redirect = Newspack_Newsletters_Bulk_Actions::bulk_action_handler( 'http://example.test/', 'newsletters_public', [ $post ] );

		$this->assertFalse( metadata_exists( 'post', $post, 'is_public' ), 'is_public must not be written on a non-newsletter post.' );
		$this->assertStringContainsString( 'newsletters_public_count=0', urldecode( $redirect ) );
	}
}
