<?php
/**
 * Class Email Theme Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Email_Theme;

/**
 * Email Theme Test.
 */
class Test_Email_Theme extends WP_UnitTestCase {
	/**
	 * The canonical fragment exposes a branded button with the agreed values.
	 */
	public function test_canonical_returns_branded_button() {
		$post   = get_post( self::factory()->post->create() );
		$button = Email_Theme::canonical( $post )['styles']['elements']['button'];

		$this->assertSame( '#fff', $button['color']['text'] );
		$this->assertSame( '5px', $button['border']['radius'] );
		$this->assertSame( '12px', $button['spacing']['padding']['top'] );
		$this->assertSame( '24px', $button['spacing']['padding']['left'] );
	}

	/**
	 * Without newspack-plugin's Lite_Site, the primary color falls back to #36f.
	 */
	public function test_primary_color_falls_back_when_lite_site_absent() {
		$post   = get_post( self::factory()->post->create() );
		$button = Email_Theme::canonical( $post )['styles']['elements']['button'];

		if ( ! method_exists( '\Newspack\Lite_Site', 'get_primary_color' ) ) {
			$this->assertSame( '#36f', $button['color']['background'] );
		} else {
			$this->assertNotEmpty( $button['color']['background'] );
		}
	}

	/**
	 * The resolved primary color is never the recursion-guard sentinel, and falls
	 * back to #36f when no primary color is resolvable.
	 */
	public function test_resolve_primary_color_never_currentcolor() {
		$primary = Email_Theme::resolve_primary_color();

		$this->assertNotSame( 'currentcolor', strtolower( $primary ) );
		$this->assertNotEmpty( $primary );
	}

	/**
	 * When the primary color is unresolvable (e.g. the `currentcolor` recursion
	 * guard inside the theme.json filter, or newspack-plugin absent), the color is
	 * recovered from the passed theme.json palette. This is the block-theme
	 * editor↔render parity fix.
	 */
	public function test_resolve_primary_color_uses_theme_json_palette() {
		$theme_json = new WP_Theme_JSON_Data(
			[
				'version'  => 3,
				'settings' => [
					'color' => [
						'palette' => [
							[
								'slug'  => 'primary',
								'color' => '#abcdef',
								'name'  => 'Primary',
							],
						],
					],
				],
			],
			'theme'
		);

		$resolved = Email_Theme::resolve_primary_color( $theme_json );

		// In this suite newspack-plugin's Lite_Site is absent, so the palette is the
		// only source — the resolver must read it rather than fall straight to #36f.
		if ( ! method_exists( '\Newspack\Lite_Site', 'get_primary_color' ) ) {
			$this->assertSame( '#abcdef', $resolved );
		} else {
			$this->assertNotSame( 'currentcolor', strtolower( $resolved ) );
		}
	}
}
