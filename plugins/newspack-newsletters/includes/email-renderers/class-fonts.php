<?php
/**
 * Shared font resolver used by both the WC email renderer and the editor canvas.
 *
 * Ensures render and canvas agree: un-customized newsletters inherit the active
 * theme's fonts instead of a hardcoded default.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves newsletter body/header font stacks with this precedence:
 *   1. Explicit newsletter font meta (font_header/font_body) — whitelist-validated.
 *   2. Global styles typography.fontFamily.
 *   3. Active theme fonts via newspack_font_stack() — matches the standard post editor.
 *   4. Hardcoded fallback (Theme_Json_Builder defaults) — standalone / no-theme.
 *
 * All theme function and theme-mod calls are guarded for standalone use.
 */
class Fonts {

	/**
	 * Mirrors `--newspack-theme-font-body` (sass/variables-site/_fonts.scss).
	 * Used when no `font_body` mod is set — newspack_font_stack() returns a
	 * degenerate stack for an unset mod, so we use the theme's CSS default instead.
	 *
	 * @var string
	 */
	const THEME_DEFAULT_BODY_FONT = 'georgia, garamond, "Times New Roman", serif';

	/**
	 * Mirrors `--newspack-theme-font-heading` (sass/variables-site/_fonts.scss);
	 * used when no `font_header` mod is set.
	 *
	 * @var string
	 */
	const THEME_DEFAULT_HEADER_FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", "Roboto", "Oxygen", "Ubuntu", "Cantarell", "Fira Sans", "Droid Sans", "Helvetica Neue", sans-serif';

	/**
	 * Per-request memo of resolved font stacks, keyed by post ID (or NO_POST_MEMO_KEY).
	 * The resolve() chain fires multiple times per request; memo avoids repeated lookups.
	 *
	 * @var array<int|string,array{body:string,header:string}>
	 */
	private static $memo = [];

	/**
	 * Sentinel memo key for the no-post (create) resolution path.
	 *
	 * @var string
	 */
	const NO_POST_MEMO_KEY = '__no_post__';

	/**
	 * Resolve body and header font stacks for a newsletter.
	 *
	 * When $post is null (e.g. post-new.php), the per-post meta step is skipped
	 * and resolution falls through global → theme → fallback.
	 *
	 * @param \WP_Post|null $post Newsletter post, or null to skip meta resolution.
	 * @return array{body:string,header:string} Resolved font stacks.
	 */
	public static function resolve( ?\WP_Post $post ): array {
		$memo_key = $post instanceof \WP_Post ? $post->ID : self::NO_POST_MEMO_KEY;
		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}

		$body_meta   = $post instanceof \WP_Post ? (string) \get_post_meta( $post->ID, 'font_body', true ) : '';
		$header_meta = $post instanceof \WP_Post ? (string) \get_post_meta( $post->ID, 'font_header', true ) : '';

		$resolved = [
			'body'   => self::resolve_side(
				$body_meta,
				'body',
				Theme_Json_Builder::DEFAULT_BODY_FONT
			),
			'header' => self::resolve_side(
				$header_meta,
				'header',
				Theme_Json_Builder::DEFAULT_HEADER_FONT
			),
		];

		self::$memo[ $memo_key ] = $resolved;
		return $resolved;
	}

	/**
	 * Clear the resolution memo. Test seam: call between cases that mutate
	 * global styles or theme mods for a reused post ID.
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$memo = [];
	}

	/**
	 * Resolve a single side (body or header) through the precedence chain.
	 *
	 * @param string $meta_value Stored font meta value for this side.
	 * @param string $side       'body' or 'header'.
	 * @param string $fallback   Hardcoded fallback stack.
	 * @return string Resolved font stack.
	 */
	private static function resolve_side( string $meta_value, string $side, string $fallback ): string {
		// 1. Explicit, supported newsletter font meta wins.
		$explicit = self::validate_meta_font( $meta_value );
		if ( null !== $explicit ) {
			return $explicit;
		}

		// 2. Global styles typography.fontFamily.
		$global = self::resolve_global_font( $side );
		if ( null !== $global ) {
			return $global;
		}

		// 3. Active theme fonts (matches the standard post editor).
		$theme = self::resolve_theme_font( $side );
		if ( null !== $theme ) {
			return $theme;
		}

		// 4. Hardcoded fallback (standalone / no theme).
		return $fallback;
	}

	/**
	 * Validate a font meta value against the supported-fonts whitelist.
	 *
	 * @param string $font Stored font meta value.
	 * @return string|null The font if supported, or null.
	 */
	private static function validate_meta_font( string $font ): ?string {
		if ( $font && \in_array( $font, \Newspack_Newsletters::$supported_fonts, true ) ) {
			return $font;
		}
		return null;
	}

	/**
	 * Resolve the global-styles font family for a side, if set.
	 *
	 * Reads typography.fontFamily (body) or elements.heading.typography.fontFamily
	 * (header). The newspack_newsletters_test_global_styles filter is a test seam.
	 *
	 * @param string $side 'body' or 'header'.
	 * @return string|null The global font family, or null when unset/unavailable.
	 */
	private static function resolve_global_font( string $side ): ?string {
		$styles = self::get_global_styles();
		if ( ! \is_array( $styles ) ) {
			return null;
		}

		if ( 'header' === $side ) {
			$value = $styles['elements']['heading']['typography']['fontFamily'] ?? null;
		} else {
			$value = $styles['typography']['fontFamily'] ?? null;
		}

		if ( ! \is_string( $value ) || '' === \trim( $value ) ) {
			return null;
		}

		// Block themes return CSS custom property references (e.g. var(--wp--preset--font-family--inter)).
		// Email clients can't resolve var(), so treat it as unset and fall through.
		if ( false !== \stripos( $value, 'var(' ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Fetch the site's global styles array defensively.
	 *
	 * @return array|null Global styles array, or null when unavailable.
	 */
	private static function get_global_styles(): ?array {
		/**
		 * Test seam: lets unit tests inject a global-styles array.
		 *
		 * @param array|null $styles Global styles array, or null to read live.
		 */
		$injected = \apply_filters( 'newspack_newsletters_test_global_styles', null );
		if ( \is_array( $injected ) ) {
			return $injected;
		}

		if ( ! \function_exists( 'wp_get_global_styles' ) ) {
			return null;
		}

		$styles = \wp_get_global_styles();
		return \is_array( $styles ) ? $styles : null;
	}

	/**
	 * Resolve the active theme's font stack for a side.
	 *
	 * Mirrors the newspack-theme contract: when font_body/font_header mod is set,
	 * builds the stack via newspack_font_stack(); when unset, uses the THEME_DEFAULT_*
	 * constants to match the post editor exactly (newspack_font_stack('','serif')
	 * yields a degenerate stack for an unset mod). Returns null when no Newspack
	 * theme is detected (standalone install).
	 *
	 * Note: detection keys off function_exists('newspack_font_stack').
	 *
	 * @param string $side 'body' or 'header'.
	 * @return string|null The theme font stack, or null when no Newspack theme.
	 */
	private static function resolve_theme_font( string $side ): ?string {
		// Detect the Newspack theme via its font helper. Absent → standalone.
		if ( ! \function_exists( 'newspack_font_stack' ) || ! \function_exists( 'get_theme_mod' ) ) {
			return null;
		}

		if ( 'header' === $side ) {
			$primary      = (string) \get_theme_mod( 'font_header', '' );
			$fallback     = (string) \get_theme_mod( 'font_header_stack', 'serif' );
			$theme_default = self::THEME_DEFAULT_HEADER_FONT;
		} else {
			$primary      = (string) \get_theme_mod( 'font_body', '' );
			$fallback     = (string) \get_theme_mod( 'font_body_stack', 'serif' );
			$theme_default = self::THEME_DEFAULT_BODY_FONT;
		}

		// Mod unset → use the theme's CSS-var default (matches the post editor).
		if ( '' === \trim( $primary ) ) {
			return $theme_default;
		}

		$stack = \newspack_font_stack( $primary, $fallback );
		if ( \is_string( $stack ) && '' !== \trim( $stack ) ) {
			return $stack;
		}
		return $theme_default;
	}
}
