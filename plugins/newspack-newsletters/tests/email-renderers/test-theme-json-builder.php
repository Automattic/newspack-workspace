<?php
/**
 * Class Theme Json Builder Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Theme_Json_Builder;

/**
 * Theme Json Builder Test.
 */
class Test_Theme_Json_Builder extends WP_UnitTestCase {
	/**
	 * Background and text colors are mapped from post meta into theme.json styles.
	 */
	public function test_maps_background_and_text_color_from_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'background_color', '#112233' );
		update_post_meta( $post_id, 'text_color', '#445566' );

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( '#112233', $theme['styles']['color']['background'] );
		$this->assertSame( '#445566', $theme['styles']['color']['text'] );
	}

	/**
	 * Missing color meta falls back to a white background and black text.
	 */
	public function test_defaults_when_meta_absent() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( '#ffffff', $theme['styles']['color']['background'] );
		$this->assertSame( '#000000', $theme['styles']['color']['text'] );
	}

	/**
	 * Invalid or unsafe color meta is rejected and falls back to defaults.
	 */
	public function test_invalid_color_meta_falls_back_to_defaults() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'background_color', 'red; body{display:none}' );
		update_post_meta( $post_id, 'text_color', 'not-a-hex' );

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( '#ffffff', $theme['styles']['color']['background'] );
		$this->assertSame( '#000000', $theme['styles']['color']['text'] );
	}
}
