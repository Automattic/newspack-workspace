<?php
/**
 * Outline button guard tests.
 *
 * @package Newspack_Block_Theme
 */

use Newspack_Block_Theme\Contrast;
use Newspack_Block_Theme\Outline_Button_Guard;
use Newspack_Block_Theme\Status_Colors;

/**
 * Tests the readability guard on the Outline button text and border.
 */
class Test_Outline_Button_Guard extends WP_UnitTestCase {
	/**
	 * Drop the styles a test saved.
	 */
	public function tear_down() {
		wp_dequeue_style( 'newspack-block-theme-outline-button-guard' );
		wp_deregister_style( 'newspack-block-theme-outline-button-guard' );
		wp_clean_theme_json_cache();
		Contrast::reset_button_styles();
		parent::tear_down();
	}

	/**
	 * Save user global styles and palette for the current theme.
	 *
	 * @param array $data The user theme.json settings and styles.
	 */
	private function set_user_data( array $data ) {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash(
					wp_json_encode(
						$data + [
							'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
							'isGlobalStylesUserThemeJSON' => true,
						]
					)
				),
			]
		);
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		Contrast::reset_button_styles();
	}

	/**
	 * A user palette with the given accent and base.
	 *
	 * @param string $accent The accent color.
	 * @param string $base   The base color.
	 * @return array
	 */
	private function palette( string $accent, string $base ): array {
		return [
			'settings' => [
				'color' => [
					'palette' => [
						[
							'slug'  => 'accent',
							'color' => $accent,
							'name'  => 'Accent',
						],
						[
							'slug'  => 'base',
							'color' => $base,
							'name'  => 'Base',
						],
					],
				],
			],
		];
	}

	/**
	 * The shipped accent on base pairs and the Lc of each.
	 *
	 * @return array
	 */
	public function shipped_vectors(): array {
		return [
			'theme.json'  => [ '#003da5', '#ffffff', 91.0 ],
			'02-arc'      => [ '#7047eb', '#ffffff', 76.9 ],
			'04-bulletin' => [ '#d63638', '#ffffff', 71.4 ],
			'06-foundry'  => [ '#375844', '#ffffff', 87.5 ],
			'08-ledger'   => [ '#c93a00', '#f2f0ea', 65.1 ],
			'10-nocturne' => [ '#ffc857', '#1e1e1e', 76.6 ],
		];
	}

	/**
	 * Every shipped palette keeps the accent for the Outline button.
	 *
	 * @dataProvider shipped_vectors
	 *
	 * @param string $accent The accent.
	 * @param string $base   The base.
	 * @param float  $lc     Expected Lc.
	 */
	public function test_shipped_palettes_stay_above_the_threshold( $accent, $base, $lc ) {
		$this->assertEqualsWithDelta( $lc, Status_Colors::lc( $accent, $base ), 0.1 );
		$this->assertGreaterThanOrEqual( Outline_Button_Guard::MIN_LC, Status_Colors::lc( $accent, $base ) );
	}

	/**
	 * The default theme keeps the accent.
	 */
	public function test_default_styles_do_not_trip() {
		$this->assertFalse( Outline_Button_Guard::is_tripped() );
		Outline_Button_Guard::enqueue_block_assets();
		$this->assertFalse( wp_style_is( 'newspack-block-theme-outline-button-guard', 'enqueued' ) );
	}

	/**
	 * Lime on light grey is invisible, so the text color takes over.
	 */
	public function test_lime_on_grey_flips() {
		$this->assertLessThan( Outline_Button_Guard::MIN_LC, Status_Colors::lc( '#c6ff00', '#dfdfdb' ) );

		$this->set_user_data( $this->palette( '#c6ff00', '#dfdfdb' ) );
		$this->assertTrue( Outline_Button_Guard::is_tripped() );

		Outline_Button_Guard::enqueue_block_assets();
		$css = implode( '', (array) wp_styles()->get_data( 'newspack-block-theme-outline-button-guard', 'after' ) );
		$this->assertSame( ':root:root, :root .editor-styles-wrapper { --wp--custom--button--outline: var(--wp--preset--color--contrast); }', $css );
	}

	/**
	 * A dark base reads an amber accent, and trips on a dark accent.
	 */
	public function test_dark_base() {
		$this->set_user_data( $this->palette( '#ffc857', '#1e1e1e' ) );
		$this->assertFalse( Outline_Button_Guard::is_tripped() );

		$this->set_user_data( $this->palette( '#2a2a2a', '#1e1e1e' ) );
		$this->assertTrue( Outline_Button_Guard::is_tripped() );
	}

	/**
	 * An Outline text color the publisher set in Styles wins.
	 */
	public function test_publisher_color_wins() {
		$this->set_user_data(
			$this->palette( '#c6ff00', '#dfdfdb' ) + [
				'styles' => [
					'blocks' => [
						'core/button' => [
							'variations' => [
								'outline' => [ 'color' => [ 'text' => '#123456' ] ],
							],
						],
					],
				],
			]
		);

		$this->assertFalse( Outline_Button_Guard::is_tripped() );
	}
}
