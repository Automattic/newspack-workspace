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
	const HANDLE = 'newspack-block-theme-outline-button-guard';

	/**
	 * Drop the styles a test saved.
	 */
	public function tear_down() {
		wp_dequeue_style( self::HANDLE );
		wp_deregister_style( self::HANDLE );
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
	 * A user palette with the given accent and base, saved as the Site Editor saves edited theme colors.
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
						'theme' => [
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
			],
		];
	}

	/**
	 * User styles with an Outline text color set in Styles.
	 *
	 * @param string $text The Outline text color.
	 * @return array
	 */
	private function outline_text( string $text ): array {
		return [
			'styles' => [
				'blocks' => [
					'core/button' => [
						'variations' => [
							'outline' => [ 'color' => [ 'text' => $text ] ],
						],
					],
				],
			],
		];
	}

	/**
	 * The CSS the guard printed.
	 *
	 * @return string
	 */
	private function printed_css(): string {
		Outline_Button_Guard::enqueue_block_assets();
		return implode( '', (array) wp_styles()->get_data( self::HANDLE, 'after' ) );
	}

	/**
	 * Every shipped palette file.
	 *
	 * @return array
	 */
	public function shipped_files(): array {
		$files = [ 'theme.json' => [ 'theme.json' ] ];
		foreach ( glob( dirname( __DIR__ ) . '/styles/*.json' ) as $path ) {
			$name           = 'styles/' . basename( $path );
			$files[ $name ] = [ $name ];
		}
		return $files;
	}

	/**
	 * Every shipped palette keeps the accent for the Outline button.
	 *
	 * @dataProvider shipped_files
	 *
	 * @param string $file Path relative to the theme root.
	 */
	public function test_shipped_palettes_keep_the_accent( $file ) {
		$palette = [];
		foreach ( array_unique( [ 'theme.json', $file ] ) as $name ) {
			$data = wp_json_file_decode( dirname( __DIR__ ) . '/' . $name, [ 'associative' => true ] );
			foreach ( $data['settings']['color']['palette'] ?? [] as $entry ) {
				$palette[ $entry['slug'] ] = $entry['color'];
			}
		}

		$this->set_user_data( $this->palette( $palette['accent'], $palette['base'] ) );
		$this->assertNull( Outline_Button_Guard::get_color() );
	}

	/**
	 * The Outline rules in theme.json read the guarded property, which defaults to the accent.
	 */
	public function test_outline_variation_reads_the_guarded_property() {
		$data = wp_json_file_decode( dirname( __DIR__ ) . '/theme.json', [ 'associative' => true ] );

		$this->assertSame( 'var(--wp--preset--color--accent)', $data['settings']['custom']['button']['outline'] );
		$this->assertSame( 'var(--wp--custom--button--outline)', $data['styles']['blocks']['core/button']['variations']['outline']['color']['text'] );
	}

	/**
	 * The default theme keeps the accent.
	 */
	public function test_default_styles_do_not_trip() {
		$this->assertNull( Outline_Button_Guard::get_color() );
		Outline_Button_Guard::enqueue_block_assets();
		$this->assertFalse( wp_style_is( self::HANDLE, 'enqueued' ) );
	}

	/**
	 * Lime on light grey is invisible, so the text color takes over.
	 */
	public function test_lime_on_grey_flips() {
		$this->assertLessThan( Outline_Button_Guard::MIN_LC, Status_Colors::lc( '#c6ff00', '#dfdfdb' ) );

		$this->set_user_data( $this->palette( '#c6ff00', '#dfdfdb' ) );
		$this->assertSame( ':root:root, :root .editor-styles-wrapper { --wp--custom--button--outline: var(--wp--preset--color--contrast); }', $this->printed_css() );
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
	 * An Outline text color the publisher set in Styles is what the Outline rules read, faint accent or not.
	 *
	 * @dataProvider publisher_colors
	 *
	 * @param string $text     The color saved in Styles.
	 * @param string $expected The value the property takes.
	 */
	public function test_publisher_color_wins( $text, $expected ) {
		$this->set_user_data( $this->palette( '#c6ff00', '#dfdfdb' ) + $this->outline_text( $text ) );

		$this->assertSame( ':root:root, :root .editor-styles-wrapper { --wp--custom--button--outline: ' . $expected . '; }', $this->printed_css() );
	}

	/**
	 * Publisher colors as Styles saves them, and the CSS value each becomes.
	 *
	 * @return array
	 */
	public function publisher_colors(): array {
		return [
			'hex'          => [ '#123456', '#123456' ],
			'preset'       => [ 'var:preset|color|accent-3', 'var(--wp--preset--color--accent-3)' ],
			'css variable' => [ 'var(--wp--preset--color--contrast)', 'var(--wp--preset--color--contrast)' ],
		];
	}

	/**
	 * A Styles value that is not a plain color leaves the guard in charge.
	 */
	public function test_unsafe_publisher_value_is_ignored() {
		$this->set_user_data( $this->palette( '#c6ff00', '#dfdfdb' ) + $this->outline_text( 'red;}body{color:red' ) );

		$this->assertSame( 'var(--wp--preset--color--contrast)', Outline_Button_Guard::get_color() );
	}
}
