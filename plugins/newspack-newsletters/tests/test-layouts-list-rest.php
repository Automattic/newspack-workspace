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
}
