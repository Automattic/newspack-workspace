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
	 * Build a saved layout carrying campaign_defaults send/audience meta.
	 *
	 * @return int Layout post ID.
	 */
	private function create_layout_with_campaign_defaults() {
		$layout_id = self::factory()->post->create(
			[
				'post_type'   => \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta(
			$layout_id,
			'campaign_defaults',
			wp_json_encode(
				[
					'senderEmail'     => 'newsroom@example.com',
					'send_list_id'    => 'list-123',
					'send_sublist_id' => 'sub-456',
				]
			)
		);
		return $layout_id;
	}

	/**
	 * Find a layout by ID in an api_get_layouts payload.
	 *
	 * @param array $layouts   Payload returned by api_get_layouts().
	 * @param int   $layout_id Layout post ID.
	 * @return object|null
	 */
	private function find_layout( $layouts, $layout_id ) {
		foreach ( $layouts as $layout ) {
			if ( (int) $layout->ID === (int) $layout_id ) {
				return $layout;
			}
		}
		return null;
	}

	/**
	 * The layouts payload withholds campaign_defaults (send/audience defaults)
	 * from a role below edit_others_posts.
	 */
	public function test_layouts_payload_strips_campaign_defaults_for_contributor() {
		$layout_id = $this->create_layout_with_campaign_defaults();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$request  = new WP_REST_Request( 'GET', '/newspack-newsletters/v1/layouts' );
		$response = \Newspack_Newsletters::api_get_layouts( $request );
		$layout   = $this->find_layout( $response->get_data(), $layout_id );
		$this->assertNotNull( $layout, 'The layout should be present in the payload.' );
		$this->assertArrayNotHasKey( 'campaign_defaults', $layout->meta, 'A contributor must not receive send/audience defaults.' );
	}

	/**
	 * The layouts payload keeps campaign_defaults for an editor.
	 */
	public function test_layouts_payload_keeps_campaign_defaults_for_editor() {
		$layout_id = $this->create_layout_with_campaign_defaults();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request  = new WP_REST_Request( 'GET', '/newspack-newsletters/v1/layouts' );
		$response = \Newspack_Newsletters::api_get_layouts( $request );
		$layout   = $this->find_layout( $response->get_data(), $layout_id );
		$this->assertNotNull( $layout, 'The layout should be present in the payload.' );
		$this->assertArrayHasKey( 'campaign_defaults', $layout->meta, 'An editor must still receive send/audience defaults.' );
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

	/**
	 * A lower role hitting color-palette gets 200 but does NOT change the option.
	 */
	public function test_color_palette_write_is_noop_for_lower_role() {
		rest_get_server();
		delete_option( 'newspack_newsletters_color_palette' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/color-palette' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'primary' => '#abcdef' ] ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$stored = json_decode( (string) get_option( 'newspack_newsletters_color_palette', '{}' ), true );
		$this->assertNotSame( '#abcdef', $stored['primary'] ?? null, 'A lower role must not have written the palette.' );
	}

	/**
	 * An editor hitting color-palette writes the option.
	 */
	public function test_color_palette_write_applies_for_editor() {
		rest_get_server();
		delete_option( 'newspack_newsletters_color_palette' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/color-palette' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'primary' => '#abcdef' ] ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$stored = json_decode( (string) get_option( 'newspack_newsletters_color_palette', '{}' ), true );
		$this->assertSame( '#abcdef', $stored['primary'] ?? null );
	}

	/**
	 * An editor's palette write reports that it was applied.
	 */
	public function test_color_palette_reports_written_for_editor() {
		rest_get_server();
		delete_option( 'newspack_newsletters_color_palette' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/color-palette' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'primary' => '#abcdef' ] ) );
		$response = rest_do_request( $request );
		$this->assertTrue( $response->get_data()['updated'], 'A real write must report updated=true.' );
	}

	/**
	 * A lower role's palette no-op reports that nothing was written.
	 */
	public function test_color_palette_reports_not_written_for_lower_role() {
		rest_get_server();
		delete_option( 'newspack_newsletters_color_palette' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$request = new WP_REST_Request( 'POST', '/newspack-newsletters/v1/color-palette' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'primary' => '#abcdef' ] ) );
		$response = rest_do_request( $request );
		$this->assertFalse( $response->get_data()['updated'], 'A skipped write must report updated=false.' );
	}
}
