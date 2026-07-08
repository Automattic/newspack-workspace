<?php
/**
 * Tests for per-route newsletter authoring permission checks (NPPM-2982).
 *
 * @package Newspack_Newsletters
 */

/**
 * Test_Authoring_Permissions.
 */
class Test_Authoring_Permissions extends WP_UnitTestCase {

	/**
	 * Build a request carrying a post_id param.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_REST_Request
	 */
	private function mjml_request( $post_id ) {
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/post-mjml' );
		$request->set_param( 'post_id', $post_id );
		return $request;
	}

	/**
	 * A contributor who owns the post can request its MJML.
	 */
	public function test_post_mjml_allows_the_post_owner() {
		$author  = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$post_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
				'post_author' => $author,
			]
		);
		wp_set_current_user( $author );
		$this->assertTrue( \Newspack_Newsletters::api_edit_post_permissions_check( $this->mjml_request( $post_id ) ) );
	}

	/**
	 * A contributor who does not own the post is denied.
	 */
	public function test_post_mjml_denies_non_owner_contributor() {
		$owner       = self::factory()->user->create( [ 'role' => 'author' ] );
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$post_id     = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status' => 'draft',
				'post_author' => $owner,
			]
		);
		wp_set_current_user( $contributor );
		$result = \Newspack_Newsletters::api_edit_post_permissions_check( $this->mjml_request( $post_id ) );
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A request without a post_id is denied.
	 */
	public function test_post_mjml_denies_when_post_id_missing() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/post-mjml' );
		$this->assertWPError( \Newspack_Newsletters::api_edit_post_permissions_check( $request ) );
	}

	/**
	 * A contributor can read the layouts list.
	 */
	public function test_layouts_list_allows_contributor() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$request = new WP_REST_Request( 'GET', '/newspack-newsletters/v1/layouts' );
		$this->assertTrue( \Newspack_Newsletters::api_edit_posts_permissions_check( $request ) );
	}

	/**
	 * A subscriber cannot read the layouts list.
	 */
	public function test_layouts_list_denies_subscriber() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$request = new WP_REST_Request( 'GET', '/newspack-newsletters/v1/layouts' );
		$this->assertWPError( \Newspack_Newsletters::api_edit_posts_permissions_check( $request ) );
	}
}
