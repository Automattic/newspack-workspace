<?php
/**
 * Class Test Layouts List REST
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Admin\Layouts_List_REST;

/**
 * Tests the REST surface the Layouts list DataView consumes.
 *
 * The author field exists so the list never has to ask for
 * `_embed=author`: embedding forces `_links` into the response, and core
 * answers that by computing target hints for every row's `self` link,
 * re-resolving the whole REST route map per row.
 */
class Layouts_List_REST_Test extends WP_UnitTestCase {
	/**
	 * Helper: make a layout post.
	 *
	 * @param array $args Post args.
	 * @return int Post ID.
	 */
	private function make_layout( $args = [] ) {
		return self::factory()->post->create(
			array_merge(
				[
					'post_type'   => Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT,
					'post_status' => 'publish',
					'post_title'  => 'Test layout',
				],
				$args
			)
		);
	}

	/**
	 * The author field is registered on the layouts CPT.
	 */
	public function test_author_field_is_registered_on_layouts_cpt() {
		do_action( 'rest_api_init' );

		global $wp_rest_additional_fields;

		$cpt    = Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT;
		$fields = isset( $wp_rest_additional_fields[ $cpt ] ) ? $wp_rest_additional_fields[ $cpt ] : [];

		$this->assertArrayHasKey( 'newspack_newsletters_author', $fields );
		$this->assertIsCallable( $fields['newspack_newsletters_author']['get_callback'] );
	}

	/**
	 * The field carries what the Author column renders: display name and
	 * an avatar URL.
	 */
	public function test_author_field_returns_display_name_and_avatar() {
		$user_id = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Grace Hopper',
			]
		);
		$post_id = $this->make_layout( [ 'post_author' => $user_id ] );

		$author = Layouts_List_REST::get_author_payload( $post_id );

		$this->assertSame( $user_id, $author['id'] );
		$this->assertSame( 'Grace Hopper', $author['name'] );
		$this->assertNotSame( '', $author['avatar'] );
	}

	/**
	 * A layout whose author no longer exists renders a blank cell rather
	 * than tripping on a missing user.
	 */
	public function test_author_field_is_null_when_the_user_is_gone() {
		$post_id = $this->make_layout( [ 'post_author' => 999999 ] );

		$this->assertNull( Layouts_List_REST::get_author_payload( $post_id ) );
		$this->assertNull( Layouts_List_REST::get_author_payload( 0 ) );
	}

	/**
	 * The field has to survive an actual dispatch, and only in the `edit`
	 * context the list asks for.
	 */
	public function test_the_author_field_reaches_the_response_only_in_edit_context() {
		$user_id = self::factory()->user->create(
			[
				'role'         => 'administrator',
				'display_name' => 'Grace Hopper',
			]
		);
		wp_set_current_user( $user_id );

		// The CPT only registers for a user who can edit others' posts, so
		// `init` in the bootstrap left it out.
		Newspack_Newsletters_Layouts::register_layout_cpt();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->make_layout( [ 'post_author' => $user_id ] );

		$route = '/wp/v2/' . Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT;

		$edit = new WP_REST_Request( 'GET', $route );
		$edit->set_param( 'context', 'edit' );
		$edit_item = rest_do_request( $edit )->get_data()[0];
		$this->assertSame( 'Grace Hopper', $edit_item['newspack_newsletters_author']['name'] );

		$view_item = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data()[0];
		$this->assertArrayNotHasKey( 'newspack_newsletters_author', $view_item );

		$wp_rest_server = null;
	}
}
