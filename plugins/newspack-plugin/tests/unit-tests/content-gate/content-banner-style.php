<?php
/**
 * Tests for the content banners' Theme style.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Banner_Style;
use Newspack\Content_Gifting;
use Newspack\Content_Gifting_CTA;
use Newspack\Metering_Countdown;
use Newspack\Newspack_UI_Theme_Style;

/**
 * Tests for the content banners' Theme style.
 */
class Test_Content_Banner_Style extends \WP_UnitTestCase {

	/**
	 * Start every test without the site Theme.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
	}

	/**
	 * Drop what a test added to the theme and request.
	 */
	public function tear_down(): void {
		remove_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		unset( $_GET['content_key'] );
		parent::tear_down();
	}

	/**
	 * Make the active theme's template the given one.
	 *
	 * @param string $template Template directory name.
	 */
	private function use_template( string $template ): void {
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
	public function theme_style_by_template(): array {
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
	public function test_theme_banner_falls_back_to_light_off_the_classic_theme( string $template, string $expected_style ): void {
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
	public function test_banner_colors_save_as_hex_or_empty(): void {
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
	public function test_theme_colors_print_with_readable_text(): void {
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
	 * Stored styles that render as stored when the site Theme is not in use.
	 *
	 * @return array
	 */
	public function classic_styles(): array {
		return [
			'light'         => [ 'light', 'light' ],
			'dark'          => [ 'dark', 'dark' ],
			'unknown value' => [ 'neon', 'light' ],
		];
	}

	/**
	 * Without the site Theme the stored style renders as stored.
	 *
	 * @dataProvider classic_styles
	 *
	 * @param string $stored   Stored style.
	 * @param string $expected Style the banner renders with.
	 */
	public function test_classic_keeps_stored_styles_exactly( string $stored, string $expected ): void {
		$this->assertFalse( Content_Banner_Style::uses_site_theme() );
		$this->assertSame( $expected, Content_Banner_Style::get_render_style( $stored ) );
	}

	/**
	 * With the site Theme in use every stored style renders as the site style, and the stored value is kept.
	 */
	public function test_site_theme_overrides_stored_style(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->assertTrue( Content_Banner_Style::uses_site_theme() );
		foreach ( [ 'light', 'dark', 'theme', 'neon' ] as $stored ) {
			$this->assertSame( Content_Banner_Style::SITE_STYLE, Content_Banner_Style::get_render_style( $stored ) );
		}
		Metering_Countdown::update_settings( [ 'style' => 'dark' ] );
		$this->assertSame( 'dark', Metering_Countdown::get_settings( 'style' ), 'The stored choice is kept for classic.' );
		$this->assertNotContains( Content_Banner_Style::SITE_STYLE, Content_Banner_Style::STYLES );
	}

	/**
	 * The adapter being forced off by its filter leaves the stored style in charge.
	 */
	public function test_stored_style_renders_when_the_adapter_filter_turns_it_off(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		add_filter( 'newspack_ui_theme_style_active', '__return_false' );
		$this->assertFalse( Content_Banner_Style::uses_site_theme() );
		$this->assertSame( 'dark', Content_Banner_Style::get_render_style( 'dark' ) );
		remove_filter( 'newspack_ui_theme_style_active', '__return_false' );
	}

	/**
	 * The site style takes the primary button and prints no inline colors.
	 */
	public function test_site_style_button_class_and_no_inline_style(): void {
		$this->assertSame( 'newspack-ui__button--primary', Content_Banner_Style::get_button_class( Content_Banner_Style::SITE_STYLE ) );
		$this->assertSame( '', Content_Banner_Style::get_inline_style( Content_Banner_Style::SITE_STYLE, '#fff59d', '#0d47a1' ) );
	}

	/**
	 * A gifted view of a gated post, with the style stored as dark, prints the site style: its class, the
	 * primary button and no inline style.
	 */
	public function test_gifting_banner_renders_the_site_style_over_a_stored_style(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		Content_Gifting_CTA::set_style( 'dark' );
		Content_Gifting_CTA::set_bg_color( '#fff59d' );
		Content_Gifting_CTA::set_button_color( '#0d47a1' );

		$gifter  = self::factory()->user->create();
		$post_id = self::factory()->post->create();
		$key     = 'giftkey';
		update_user_meta(
			$gifter,
			Content_Gifting::META,
			[
				'keys'   => [
					$post_id => [
						'key'       => $key,
						'timestamp' => time(),
					],
				],
				'limits' => [],
			]
		);
		add_filter( 'newspack_content_gifting_enabled', '__return_true' );
		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$this->go_to( get_permalink( $post_id ) );
		$_GET['content_key'] = $gifter . '|' . $key;
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		Content_Gifting_CTA::print_cta();
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'newspack-content-gifting__cta is-style-site', $markup );
		$this->assertStringNotContainsString( 'is-style-dark', $markup );
		$this->assertStringNotContainsString( 'style="', $markup );

		unset( $_GET['content_key'] );
		remove_filter( 'newspack_content_gifting_enabled', '__return_true' );
		remove_filter( 'newspack_is_post_restricted', '__return_true' );
	}

	/**
	 * The wizard is told when the site Theme replaces the style option.
	 */
	public function test_wizard_data_reports_site_theme(): void {
		$this->assertFalse( Content_Banner_Style::get_wizard_data()['uses_site_theme'] );
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->assertTrue( Content_Banner_Style::get_wizard_data()['uses_site_theme'] );
	}
}
