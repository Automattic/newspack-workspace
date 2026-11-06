<?php
/**
 * Tests for the content banners' Theme style.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Banner_Style;
use Newspack\Content_Gifting_CTA;
use Newspack\Metering_Countdown;
use Newspack\Newspack_UI_Theme_Style;

/**
 * Tests for the content banners' Theme style.
 */
class Test_Content_Banner_Style extends \WP_UnitTestCase {

	/**
	 * Make the active theme's template the given one.
	 *
	 * @param string $template Template directory name.
	 */
	private function use_template( $template ) {
		add_filter(
			'template',
			function () use ( $template ) {
				return $template;
			}
		);
	}

	/**
	 * Templates and the style a saved Theme banner renders with.
	 *
	 * @return array
	 */
	public function theme_style_by_template() {
		return [
			'classic theme or a child of it' => [ 'newspack-theme', 'theme' ],
			'block theme'                    => [ 'newspack-block-theme', 'light' ],
			'any other theme'                => [ 'twentytwentyfive', 'light' ],
		];
	}

	/**
	 * A banner saved as Theme renders as Light unless the theme is newspack-theme or its child.
	 *
	 * @dataProvider theme_style_by_template
	 *
	 * @param string $template       Active template.
	 * @param string $expected_style Style the banner renders with.
	 */
	public function test_theme_banner_falls_back_to_light_off_the_classic_theme( $template, $expected_style ) {
		$this->use_template( $template );
		Metering_Countdown::update_settings( [ 'style' => 'theme' ] );
		Content_Gifting_CTA::set_style( 'theme' );

		$this->assertSame( 'theme', Metering_Countdown::get_settings( 'style' ), 'The stored style survives a theme switch.' );
		$this->assertSame( 'theme', Content_Gifting_CTA::get_style(), 'The stored style survives a theme switch.' );
		$this->assertSame( $expected_style, Content_Banner_Style::get_render_style( Metering_Countdown::get_settings( 'style' ) ) );
		$this->assertSame( $expected_style, Content_Banner_Style::get_render_style( Content_Gifting_CTA::get_style() ) );
	}

	/**
	 * Saved colors are hex or empty, so nothing else reaches the inline style.
	 */
	public function test_banner_colors_save_as_hex_or_empty() {
		Metering_Countdown::update_settings(
			[
				'bg_color'     => '#1A237E',
				'button_color' => 'red;background:url(https://example.test/x.png)',
			]
		);
		Content_Gifting_CTA::set_bg_color( '#abc' );
		Content_Gifting_CTA::set_button_color( 'javascript:alert(1)' );

		$this->assertSame( '#1A237E', Metering_Countdown::get_settings( 'bg_color' ) );
		$this->assertSame( '', Metering_Countdown::get_settings( 'button_color' ) );
		$this->assertSame( '#abc', Content_Gifting_CTA::get_bg_color() );
		$this->assertSame( '', Content_Gifting_CTA::get_button_color() );
	}

	/**
	 * Custom colors print as custom properties with black or white text picked for each color.
	 */
	public function test_theme_colors_print_with_readable_text() {
		$this->assertSame(
			'--newspack-content-banner-bg:#fff59d;--newspack-content-banner-ink:black;--newspack-content-banner-button-bg:#0d47a1;--newspack-content-banner-button-ink:white',
			Content_Banner_Style::get_inline_style( 'theme', '#fff59d', '#0d47a1' )
		);
		$this->assertSame(
			'--newspack-content-banner-button-bg:#ffd54f;--newspack-content-banner-button-ink:black',
			Content_Banner_Style::get_inline_style( 'theme', '', '#ffd54f' ),
			'An empty color leaves the stylesheet default in place.'
		);
		$this->assertSame( '', Content_Banner_Style::get_inline_style( 'light', '#fff59d', '#0d47a1' ), 'Only the Theme style prints colors.' );
	}

	/**
	 * Stored styles and the style they render with when the site Theme is not in use.
	 *
	 * @return array
	 */
	public function classic_styles() {
		return [
			'light'                       => [ 'newspack-theme', 'light', 'light' ],
			'dark'                        => [ 'newspack-theme', 'dark', 'dark' ],
			'theme on the classic theme'  => [ 'newspack-theme', 'theme', 'theme' ],
			'theme off the classic theme' => [ 'newspack-block-theme', 'theme', 'light' ],
			'unknown value'               => [ 'newspack-theme', 'neon', 'light' ],
		];
	}

	/**
	 * Without the adapter the stored style renders exactly as before.
	 *
	 * @dataProvider classic_styles
	 *
	 * @param string $template Active template.
	 * @param string $stored   Stored style.
	 * @param string $expected Style the banner renders with.
	 */
	public function test_classic_keeps_stored_styles_exactly( $template, $stored, $expected ) {
		$this->use_template( $template );
		$this->assertFalse( Content_Banner_Style::uses_site_theme() );
		$this->assertSame( $expected, Content_Banner_Style::get_render_style( $stored ) );
	}

	/**
	 * When the adapter is active every stored style renders with the site Theme, and the stored value is kept.
	 */
	public function test_site_theme_overrides_stored_style() {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->assertTrue( Content_Banner_Style::uses_site_theme() );
		foreach ( [ 'light', 'dark', 'theme', 'neon' ] as $stored ) {
			$this->assertSame( 'site', Content_Banner_Style::get_render_style( $stored ) );
		}
		Metering_Countdown::update_settings( [ 'style' => 'dark' ] );
		$this->assertSame( 'dark', Metering_Countdown::get_settings( 'style' ), 'The stored choice is kept for classic.' );
		$this->assertNotContains( 'site', Content_Banner_Style::STYLES );
		remove_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
	}

	/**
	 * The site style uses the primary button and prints no inline colors.
	 */
	public function test_site_style_button_class_and_no_inline_style() {
		$this->assertSame( 'newspack-ui__button--primary', Content_Banner_Style::get_button_class( 'site' ) );
		$this->assertSame( '', Content_Banner_Style::get_inline_style( 'site', '#fff59d', '#0d47a1' ) );
	}

	/**
	 * The wizard is told when the site Theme replaces the style option.
	 */
	public function test_wizard_data_reports_site_theme() {
		$this->assertFalse( Content_Banner_Style::get_wizard_data()['uses_site_theme'] );
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->assertTrue( Content_Banner_Style::get_wizard_data()['uses_site_theme'] );
		remove_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
	}
}
