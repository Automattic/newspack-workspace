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

	/**
	 * Remove options mutated by tests so they never leak between cases.
	 */
	public function tear_down() {
		delete_option( 'newspack_newsletters_color_palette' );
		parent::tear_down();
	}

	/**
	 * With no palette configured, the palette key is omitted so the merge does
	 * not wipe the editor's default color presets.
	 */
	public function test_omits_palette_when_option_unconfigured() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertArrayNotHasKey( 'color', $theme['settings'] );
	}

	/**
	 * Palette entries with invalid hex values are skipped.
	 */
	public function test_palette_skips_invalid_hex_entries() {
		update_option(
			'newspack_newsletters_color_palette',
			wp_json_encode(
				[
					'good' => '#112233',
					'bad'  => 'not-a-hex',
				]
			)
		);
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertNotNull( $this->find_preset( $theme['settings']['color']['palette'], 'good' ) );
		$this->assertNull( $this->find_preset( $theme['settings']['color']['palette'], 'bad' ) );
	}

	/**
	 * Find a preset entry by its slug.
	 *
	 * @param array  $presets Theme.json preset array (palette/fontSizes/spacingSizes).
	 * @param string $slug    Slug to find.
	 * @return array|null
	 */
	private function find_preset( $presets, $slug ) {
		foreach ( (array) $presets as $preset ) {
			if ( isset( $preset['slug'] ) && $slug === $preset['slug'] ) {
				return $preset;
			}
		}
		return null;
	}

	/**
	 * The newsletter color palette option is injected as the theme color palette.
	 */
	public function test_injects_color_palette_from_option() {
		update_option(
			'newspack_newsletters_color_palette',
			wp_json_encode(
				[
					'primary'   => '#003da5',
					'secondary' => '#112233',
				]
			)
		);
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$primary = $this->find_preset( $theme['settings']['color']['palette'], 'primary' );
		$this->assertNotNull( $primary );
		$this->assertSame( '#003da5', $primary['color'] );
	}

	/**
	 * The Newspack font-size scale is injected (e.g. small resolves to 12px).
	 */
	public function test_injects_newspack_font_size_scale() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$small = $this->find_preset( $theme['settings']['typography']['fontSizes'], 'small' );
		$this->assertNotNull( $small );
		$this->assertSame( '12px', $small['size'] );
	}

	/**
	 * The Newspack spacing scale is injected (e.g. preset 50 resolves to 32px).
	 */
	public function test_injects_newspack_spacing_scale() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$fifty = $this->find_preset( $theme['settings']['spacing']['spacingSizes'], '50' );
		$this->assertNotNull( $fifty );
		$this->assertSame( '32px', $fifty['size'] );
	}

	/**
	 * Fluid typography is disabled so font sizes resolve to fixed pixel values.
	 */
	public function test_disables_fluid_typography() {
		$post_id = self::factory()->post->create();

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertFalse( $theme['settings']['typography']['fluid'] );
	}

	/**
	 * Supported font_header/font_body meta map to heading and body font families.
	 */
	public function test_maps_fonts_from_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'font_header', 'Georgia, serif' );
		update_post_meta( $post_id, 'font_body', 'Verdana, sans-serif' );

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( 'Verdana, sans-serif', $theme['styles']['typography']['fontFamily'] );
		$this->assertSame( 'Georgia, serif', $theme['styles']['elements']['heading']['typography']['fontFamily'] );
	}

	/**
	 * Unsupported or empty font meta falls back to the default font stacks.
	 */
	public function test_font_meta_falls_back_to_defaults_when_unsupported() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'font_header', 'Comic Sans' );
		update_post_meta( $post_id, 'font_body', '' );

		$theme = Theme_Json_Builder::build( get_post( $post_id ) );

		$this->assertSame( 'Arial, Helvetica, sans-serif', $theme['styles']['elements']['heading']['typography']['fontFamily'] );
		$this->assertSame( 'Georgia, serif', $theme['styles']['typography']['fontFamily'] );
	}
}
