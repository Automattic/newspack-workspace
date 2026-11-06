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
		unset( $_GET[ Content_Gifting::QUERY_ARG ] );
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
	 * Text colors, like the other colors, save as hex or empty.
	 */
	public function test_banner_text_colors_save_as_hex_or_empty(): void {
		Metering_Countdown::update_settings(
			[
				'text_color'        => '#102030',
				'button_text_color' => 'red;background:url(https://example.test/x.png)',
			]
		);
		Content_Gifting_CTA::set_text_color( '#abc' );
		Content_Gifting_CTA::set_button_text_color( 'javascript:alert(1)' );

		$this->assertSame( '#102030', Metering_Countdown::get_settings( 'text_color' ) );
		$this->assertSame( '', Metering_Countdown::get_settings( 'button_text_color' ) );
		$this->assertSame( '#abc', Content_Gifting_CTA::get_text_color() );
		$this->assertSame( '', Content_Gifting_CTA::get_button_text_color() );
	}

	/**
	 * A picked text color replaces the black or white pick, and stands alone when no background is picked.
	 */
	public function test_theme_text_colors_override_the_automatic_pick(): void {
		$this->assertSame(
			'--newspack-content-banner-bg:#fff59d;--newspack-content-banner-ink:#102030;--newspack-content-banner-button-bg:#0d47a1;--newspack-content-banner-button-ink:#ffeb3b',
			Content_Banner_Style::get_inline_style( 'theme', '#fff59d', '#0d47a1', '#102030', '#ffeb3b' )
		);
		$this->assertSame(
			'--newspack-content-banner-ink:#102030;--newspack-content-banner-button-ink:#ffeb3b',
			Content_Banner_Style::get_inline_style( 'theme', '', '', '#102030', '#ffeb3b' )
		);
		$this->assertSame(
			'--newspack-content-banner-bg:#fff59d;--newspack-content-banner-ink:black;--newspack-content-banner-button-bg:#0d47a1;--newspack-content-banner-button-ink:white',
			Content_Banner_Style::get_inline_style( 'theme', '#fff59d', '#0d47a1', '', '' ),
			'Without text colors the Theme style prints what it always has.'
		);
	}

	/**
	 * The site style prints only what is picked, and picks black or white only for a color it was not given text for.
	 */
	public function test_site_style_prints_only_picked_colors(): void {
		$this->assertSame( '', Content_Banner_Style::get_inline_style( Content_Banner_Style::SITE_STYLE, '', '' ) );
		$this->assertSame(
			'--newspack-content-banner-bg:#fff59d;--newspack-content-banner-ink:black',
			Content_Banner_Style::get_inline_style( Content_Banner_Style::SITE_STYLE, '#fff59d', '' )
		);
		$this->assertSame(
			'--newspack-content-banner-button-bg:#0d47a1;--newspack-content-banner-button-ink:white',
			Content_Banner_Style::get_inline_style( Content_Banner_Style::SITE_STYLE, '', '#0d47a1' )
		);
		$this->assertSame(
			'--newspack-content-banner-bg:#fff59d;--newspack-content-banner-ink:#102030;--newspack-content-banner-button-bg:#0d47a1;--newspack-content-banner-button-ink:#ffeb3b',
			Content_Banner_Style::get_inline_style( Content_Banner_Style::SITE_STYLE, '#fff59d', '#0d47a1', '#102030', '#ffeb3b' )
		);
		$this->assertSame(
			'--newspack-content-banner-ink:#102030',
			Content_Banner_Style::get_inline_style( Content_Banner_Style::SITE_STYLE, '', '', '#102030', 'not a color' )
		);
	}

	/**
	 * The shadow is on unless turned off, and only the Theme and site styles can turn it off.
	 */
	public function test_shadow_class_is_added_only_when_off_on_theme_and_site_styles(): void {
		$this->assertSame( '', Content_Banner_Style::get_shadow_class( 'theme', true ) );
		$this->assertSame( 'has-no-shadow', Content_Banner_Style::get_shadow_class( 'theme', false ) );
		$this->assertSame( 'has-no-shadow', Content_Banner_Style::get_shadow_class( Content_Banner_Style::SITE_STYLE, false ) );
		$this->assertSame( '', Content_Banner_Style::get_shadow_class( 'light', false ) );
		$this->assertSame( '', Content_Banner_Style::get_shadow_class( 'dark', false ) );
	}

	/**
	 * The shadow setting defaults to on and saves as a boolean.
	 */
	public function test_shadow_setting_defaults_on_and_saves_as_a_boolean(): void {
		$this->assertTrue( (bool) Metering_Countdown::get_settings( 'shadow' ) );
		$this->assertTrue( Content_Gifting_CTA::get_shadow() );

		Metering_Countdown::update_settings( [ 'shadow' => false ] );
		Content_Gifting_CTA::set_shadow( false );
		$this->assertFalse( (bool) Metering_Countdown::get_settings( 'shadow' ) );
		$this->assertFalse( Content_Gifting_CTA::get_shadow() );

		Metering_Countdown::update_settings( [ 'shadow' => 'garbage' ] );
		Content_Gifting_CTA::set_shadow( 'garbage' );
		$this->assertFalse( (bool) Metering_Countdown::get_settings( 'shadow' ), 'A value that is not true turns the shadow off.' );
		$this->assertFalse( Content_Gifting_CTA::get_shadow() );

		Metering_Countdown::update_settings( [ 'shadow' => true ] );
		Content_Gifting_CTA::set_shadow( true );
		$this->assertTrue( (bool) Metering_Countdown::get_settings( 'shadow' ) );
		$this->assertTrue( Content_Gifting_CTA::get_shadow() );
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
	}

	/**
	 * A gifted view of a gated post, with the Theme style stored, prints the site style.
	 */
	public function test_gifting_banner_renders_the_site_style_over_a_stored_style(): void {
		$this->use_template( 'newspack-theme' );
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		Content_Gifting_CTA::set_style( 'theme' );
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
		$_GET[ Content_Gifting::QUERY_ARG ] = $gifter . '|' . $key;
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		Content_Gifting_CTA::print_cta();
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'newspack-content-gifting__cta is-style-site', $markup );
		$this->assertStringNotContainsString( 'is-style-theme', $markup );
	}

	/**
	 * A gifted view on the site Theme prints the picked colors on the banner.
	 */
	public function test_gifting_banner_prints_picked_colors_on_the_site_style(): void {
		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		Content_Gifting_CTA::set_bg_color( '#fff59d' );
		Content_Gifting_CTA::set_text_color( '#102030' );
		Content_Gifting_CTA::set_button_color( '#0d47a1' );
		Content_Gifting_CTA::set_button_text_color( '#ffeb3b' );

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
		$_GET[ Content_Gifting::QUERY_ARG ] = $gifter . '|' . $key;
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		Content_Gifting_CTA::print_cta();
		$markup = ob_get_clean();

		$this->assertStringContainsString( 'is-style-site', $markup );
		$this->assertStringContainsString(
			'style="--newspack-content-banner-bg:#fff59d;--newspack-content-banner-ink:#102030;--newspack-content-banner-button-bg:#0d47a1;--newspack-content-banner-button-ink:#ffeb3b"',
			$markup
		);
	}

	/**
	 * Print the gifted banner of a restricted post and return its markup.
	 *
	 * @return string
	 */
	private function print_gifted_banner(): string {
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
		$_GET[ Content_Gifting::QUERY_ARG ] = $gifter . '|' . $key;
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ob_start();
		Content_Gifting_CTA::print_cta();
		return ob_get_clean();
	}

	/**
	 * A gifted banner has no shadow class by default, and gets it when the shadow is off on the Theme and site styles.
	 */
	public function test_gifting_banner_shadow_class(): void {
		$this->use_template( 'newspack-theme' );
		Content_Gifting_CTA::set_style( 'theme' );
		$this->assertStringContainsString( 'newspack-content-gifting__cta is-style-theme"', $this->print_gifted_banner() );

		Content_Gifting_CTA::set_shadow( false );
		$this->assertStringContainsString( 'newspack-content-gifting__cta is-style-theme has-no-shadow"', $this->print_gifted_banner() );

		Content_Gifting_CTA::set_style( 'dark' );
		$this->assertStringNotContainsString( 'has-no-shadow', $this->print_gifted_banner(), 'Dark keeps its shadow.' );

		add_theme_support( Newspack_UI_Theme_Style::THEME_SUPPORT );
		$this->assertStringContainsString( 'is-style-site has-no-shadow"', $this->print_gifted_banner() );
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
