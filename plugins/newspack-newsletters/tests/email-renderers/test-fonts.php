<?php
/**
 * Class Test_Fonts
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Fonts;
use Newspack\Newsletters\Email_Renderers\Theme_Json_Builder;

/**
 * Tests for the shared font resolver.
 *
 * Precedence (highest first): explicit newsletter font meta → global styles typography.fontFamily →
 * active theme newspack_font_stack() → hardcoded DEFAULT_BODY_FONT / DEFAULT_HEADER_FONT fallback.
 */
class Test_Fonts extends WP_UnitTestCase {

	/**
	 * Clean up filters/options mutated by tests.
	 */
	public function tear_down() {
		remove_all_filters( 'newspack_newsletters_test_global_styles' );
		Fonts::reset_memo();
		parent::tear_down();
	}

	/**
	 * Explicit, supported font meta wins over everything else.
	 */
	public function test_explicit_meta_wins() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'font_header', 'Georgia, serif' );
		update_post_meta( $post_id, 'font_body', 'Verdana, sans-serif' );

		$fonts = Fonts::resolve( get_post( $post_id ) );

		$this->assertSame( 'Verdana, sans-serif', $fonts['body'] );
		$this->assertSame( 'Georgia, serif', $fonts['header'] );
	}

	/**
	 * Unsupported explicit meta is rejected and falls through to the hardcoded fallback
	 * (when no theme function or global styles are available).
	 */
	public function test_unsupported_meta_falls_through() {
		if ( function_exists( 'newspack_font_stack' ) ) {
			$this->markTestSkipped( 'Theme font fn present; this case asserts the hardcoded-fallback branch.' );
		}
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'font_header', 'Comic Sans' );
		update_post_meta( $post_id, 'font_body', 'Wingdings' );

		$fonts = Fonts::resolve( get_post( $post_id ) );

		// No theme fn / no global styles in the default test theme → hardcoded fallback.
		$this->assertSame( Theme_Json_Builder::DEFAULT_BODY_FONT, $fonts['body'] );
		$this->assertSame( Theme_Json_Builder::DEFAULT_HEADER_FONT, $fonts['header'] );
	}

	/**
	 * Global styles typography.fontFamily wins over theme fonts and fallback when no explicit meta is set.
	 */
	public function test_global_styles_win_over_theme_and_fallback() {
		$post_id = self::factory()->post->create();

		add_filter(
			'newspack_newsletters_test_global_styles',
			function () {
				return [
					'typography' => [ 'fontFamily' => 'GlobalBody, sans-serif' ],
					'elements'   => [
						'heading' => [
							'typography' => [ 'fontFamily' => 'GlobalHeading, serif' ],
						],
					],
				];
			}
		);

		$fonts = Fonts::resolve( get_post( $post_id ) );

		$this->assertSame( 'GlobalBody, sans-serif', $fonts['body'] );
		$this->assertSame( 'GlobalHeading, serif', $fonts['header'] );
	}

	/**
	 * A global body font with no heading override applies to body; header falls through to the
	 * next branch (theme/fallback) independently.
	 */
	public function test_global_body_only_leaves_header_to_fall_through() {
		if ( function_exists( 'newspack_font_stack' ) ) {
			$this->markTestSkipped( 'Theme font fn present; header would resolve to the theme stack, not the hardcoded fallback.' );
		}
		$post_id = self::factory()->post->create();

		add_filter(
			'newspack_newsletters_test_global_styles',
			function () {
				return [
					'typography' => [ 'fontFamily' => 'GlobalBody, sans-serif' ],
				];
			}
		);

		$fonts = Fonts::resolve( get_post( $post_id ) );

		$this->assertSame( 'GlobalBody, sans-serif', $fonts['body'] );
		// No global heading, no theme fn → hardcoded header fallback.
		$this->assertSame( Theme_Json_Builder::DEFAULT_HEADER_FONT, $fonts['header'] );
	}

	/**
	 * When newspack_font_stack() is absent, the resolver falls back to the hardcoded defaults without fataling.
	 */
	public function test_falls_back_to_hardcoded_when_theme_fn_absent() {
		if ( function_exists( 'newspack_font_stack' ) ) {
			$this->markTestSkipped( 'newspack_font_stack() is defined in this process; cannot exercise the absent-fn branch.' );
		}

		$post_id = self::factory()->post->create();

		$fonts = Fonts::resolve( get_post( $post_id ) );

		$this->assertSame( Theme_Json_Builder::DEFAULT_BODY_FONT, $fonts['body'] );
		$this->assertSame( Theme_Json_Builder::DEFAULT_HEADER_FONT, $fonts['header'] );
	}

	/**
	 * When newspack_font_stack() exists, the resolver uses the theme's resolved stacks as the default.
	 * Stand-in functions are defined to mirror the real theme's API (function definitions persist per-process).
	 */
	public function test_theme_fonts_used_as_default_when_theme_fn_present() {
		require_once __DIR__ . '/fixtures/theme-font-functions.php';

		$post_id = self::factory()->post->create();

		// Simulate the theme mods the real theme reads.
		add_filter(
			'theme_mod_font_body',
			function () {
				return 'Source Serif Pro';
			}
		);
		add_filter(
			'theme_mod_font_body_stack',
			function () {
				return 'serif';
			}
		);
		add_filter(
			'theme_mod_font_header',
			function () {
				return 'Source Sans Pro';
			}
		);
		add_filter(
			'theme_mod_font_header_stack',
			function () {
				return 'sans_serif';
			}
		);

		$fonts = Fonts::resolve( get_post( $post_id ) );

		$this->assertSame( newspack_font_stack( 'Source Serif Pro', 'serif' ), $fonts['body'] );
		$this->assertSame( newspack_font_stack( 'Source Sans Pro', 'sans_serif' ), $fonts['header'] );
		$this->assertStringContainsString( 'Source Serif Pro', $fonts['body'] );
		$this->assertStringContainsString( 'Source Sans Pro', $fonts['header'] );

		remove_all_filters( 'theme_mod_font_body' );
		remove_all_filters( 'theme_mod_font_body_stack' );
		remove_all_filters( 'theme_mod_font_header' );
		remove_all_filters( 'theme_mod_font_header_stack' );
	}

	/**
	 * Unset customizer font mods use the theme's CSS-var default stacks — not the degenerate
	 * newspack_font_stack('', 'serif') result.
	 */
	public function test_unset_theme_mods_use_theme_css_default_stacks() {
		require_once __DIR__ . '/fixtures/theme-font-functions.php';

		$post_id = self::factory()->post->create();

		// Force the mods to be empty (unset).
		add_filter( 'theme_mod_font_body', '__return_empty_string' );
		add_filter( 'theme_mod_font_header', '__return_empty_string' );

		$fonts = Fonts::resolve( get_post( $post_id ) );

		remove_filter( 'theme_mod_font_body', '__return_empty_string' );
		remove_filter( 'theme_mod_font_header', '__return_empty_string' );

		$this->assertSame( Fonts::THEME_DEFAULT_BODY_FONT, $fonts['body'] );
		$this->assertSame( Fonts::THEME_DEFAULT_HEADER_FONT, $fonts['header'] );
		$this->assertStringContainsString( 'garamond', $fonts['body'] );
		$this->assertStringContainsString( 'apple-system', $fonts['header'] );
	}

	/**
	 * A global font expressed as a CSS custom property (var(...)) falls through to the theme branch —
	 * the email CSS inliner and email clients can't resolve custom properties.
	 */
	public function test_global_var_font_falls_through_to_theme() {
		require_once __DIR__ . '/fixtures/theme-font-functions.php';

		$post_id = self::factory()->post->create();

		// Global styles return an unresolvable CSS var for both sides.
		add_filter(
			'newspack_newsletters_test_global_styles',
			function () {
				return [
					'typography' => [ 'fontFamily' => 'var(--wp--preset--font-family--inter)' ],
					'elements'   => [
						'heading' => [
							'typography' => [ 'fontFamily' => 'var(--wp--preset--font-family--montserrat)' ],
						],
					],
				];
			}
		);

		// Theme mods unset → theme branch yields the CSS-var default stacks.
		add_filter( 'theme_mod_font_body', '__return_empty_string' );
		add_filter( 'theme_mod_font_header', '__return_empty_string' );

		$fonts = Fonts::resolve( get_post( $post_id ) );

		remove_filter( 'theme_mod_font_body', '__return_empty_string' );
		remove_filter( 'theme_mod_font_header', '__return_empty_string' );

		// The var() globals are skipped; resolution lands on the theme defaults,
		// never returning the unresolvable var() into the email theme.json.
		$this->assertStringNotContainsString( 'var(', $fonts['body'], 'Body must not contain an unresolvable CSS var.' );
		$this->assertStringNotContainsString( 'var(', $fonts['header'], 'Header must not contain an unresolvable CSS var.' );
		$this->assertSame( Fonts::THEME_DEFAULT_BODY_FONT, $fonts['body'] );
		$this->assertSame( Fonts::THEME_DEFAULT_HEADER_FONT, $fonts['header'] );
	}

	/**
	 * Resolving with null (post-new.php create path) skips per-post meta and falls through to the
	 * hardcoded fallback — null is accepted and meta lookups are skipped.
	 */
	public function test_resolves_without_post_falls_through_to_fallback() {
		if ( function_exists( 'newspack_font_stack' ) ) {
			$this->markTestSkipped( 'Theme font fn present; this case asserts the no-post hardcoded-fallback branch.' );
		}

		$fonts = Fonts::resolve( null );

		$this->assertSame( Theme_Json_Builder::DEFAULT_BODY_FONT, $fonts['body'] );
		$this->assertSame( Theme_Json_Builder::DEFAULT_HEADER_FONT, $fonts['header'] );
	}

	/**
	 * Resolving with null still picks up site-wide global styles typography — only the meta step is skipped.
	 */
	public function test_resolves_without_post_honors_global_styles() {
		add_filter(
			'newspack_newsletters_test_global_styles',
			function () {
				return [
					'typography' => [ 'fontFamily' => 'GlobalBody, sans-serif' ],
					'elements'   => [
						'heading' => [
							'typography' => [ 'fontFamily' => 'GlobalHeading, serif' ],
						],
					],
				];
			}
		);

		$fonts = Fonts::resolve( null );

		$this->assertSame( 'GlobalBody, sans-serif', $fonts['body'] );
		$this->assertSame( 'GlobalHeading, serif', $fonts['header'] );
	}

	/**
	 * The resolver always returns both keys as non-empty strings.
	 */
	public function test_returns_body_and_header_keys() {
		$post_id = self::factory()->post->create();

		$fonts = Fonts::resolve( get_post( $post_id ) );

		$this->assertArrayHasKey( 'body', $fonts );
		$this->assertArrayHasKey( 'header', $fonts );
		$this->assertNotEmpty( $fonts['body'] );
		$this->assertNotEmpty( $fonts['header'] );
	}
}
