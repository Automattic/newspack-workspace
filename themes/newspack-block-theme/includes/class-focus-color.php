<?php
/**
 * Newspack Block Theme focus color.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the focus indicator color in step with a block's text color when that
 * color is a custom value or a palette entry the stylesheet does not know about.
 */
final class Focus_Color {
	/**
	 * Palette slugs the stylesheet maps through their .has-<slug>-color classes.
	 *
	 * Keep in sync with the slug lists in src/scss/_css-variables.scss.
	 */
	const STYLESHEET_SLUGS = [
		'accent',
		'accent-2',
		'accent-3',
		'base',
		'base-2',
		'base-3',
		'base-4',
		'base-5',
		'contrast',
		'contrast-2',
		'contrast-3',
		'primary',
		'primary-variation',
		'secondary',
		'secondary-variation',
		'dark-gray',
		'medium-gray',
		'light-gray',
		'white',
	];

	/**
	 * Initializer.
	 */
	public static function init() {
		\add_filter( 'render_block', [ __CLASS__, 'render_block' ], 10, 2 );
	}

	/**
	 * Add --wp--custom--focus--color to the wrapper of a block with a custom text color.
	 *
	 * Palette slugs the stylesheet knows are handled there through their
	 * .has-<slug>-color classes. Blocks that skip text color serialization, such
	 * as buttons and search, put that color on an inner control rather than on
	 * the surface the focus line is drawn against, so they are left alone.
	 *
	 * @param string $block_content The rendered block markup.
	 * @param array  $block         The parsed block.
	 * @return string The block markup.
	 */
	public static function render_block( $block_content, $block ) {
		if ( ! is_string( $block_content ) || '' === $block_content || ! is_array( $block ) ) {
			return $block_content;
		}

		$block_name = $block['blockName'] ?? null;
		$block_type = is_string( $block_name ) ? \WP_Block_Type_Registry::get_instance()->get_registered( $block_name ) : null;
		if ( $block_type && \wp_should_skip_block_supports_serialization( $block_type, 'color', 'text' ) ) {
			return $block_content;
		}

		$attrs = $block['attrs'] ?? [];
		$color = self::get_custom_text_color( is_array( $attrs ) ? $attrs : [] );
		if ( null === $color ) {
			return $block_content;
		}

		$declaration = \safecss_filter_attr( '--wp--custom--focus--color: ' . $color );
		if ( '' === $declaration ) {
			return $block_content;
		}

		$processor = new \WP_HTML_Tag_Processor( $block_content );
		if ( ! $processor->next_tag() ) {
			return $block_content;
		}

		$style = $processor->get_attribute( 'style' );
		$style = is_string( $style ) ? rtrim( trim( $style ), ';' ) : '';
		$processor->set_attribute( 'style', '' === $style ? $declaration : $style . ';' . $declaration );

		return $processor->get_updated_html();
	}

	/**
	 * Resolve the CSS value of a block's text color when the stylesheet cannot.
	 *
	 * Follows core's precedence: a palette slug in textColor wins over a value in
	 * style.color.text. Navigation keeps its custom value in customTextColor.
	 *
	 * @param array $attrs The block attributes.
	 * @return string|null The CSS color value, or null when there is nothing to add.
	 */
	private static function get_custom_text_color( array $attrs ): ?string {
		$slug = $attrs['textColor'] ?? null;
		if ( is_string( $slug ) && '' !== $slug ) {
			return self::get_preset_color( $slug );
		}

		$text = $attrs['style']['color']['text'] ?? $attrs['customTextColor'] ?? null;
		if ( ! is_string( $text ) || '' === $text ) {
			return null;
		}

		if ( preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/i', $text, $matches ) ) {
			return self::get_preset_color( $matches[1] );
		}

		return $text;
	}

	/**
	 * Resolve a palette slug to its preset variable.
	 *
	 * Returns null for slugs the stylesheet already maps and for slugs found in
	 * no palette origin, whose preset variable is never defined and would make
	 * the focus color invalid for the whole subtree.
	 *
	 * @param string $slug The palette slug.
	 * @return string|null The preset variable, or null when there is nothing to add.
	 */
	private static function get_preset_color( string $slug ): ?string {
		if ( ! preg_match( '/^[a-z0-9-]+$/i', $slug ) || in_array( $slug, self::STYLESHEET_SLUGS, true ) || ! self::is_palette_slug( $slug ) ) {
			return null;
		}

		return 'var(--wp--preset--color--' . \_wp_to_kebab_case( $slug ) . ')';
	}

	/**
	 * Whether a slug is defined in any palette origin: default, theme or custom.
	 *
	 * @param string $slug The palette slug.
	 * @return bool
	 */
	private static function is_palette_slug( string $slug ): bool {
		$palette = \wp_get_global_settings( [ 'color', 'palette' ] );
		if ( ! is_array( $palette ) ) {
			return false;
		}

		foreach ( $palette as $entries ) {
			if ( is_array( $entries ) && in_array( $slug, array_column( $entries, 'slug' ), true ) ) {
				return true;
			}
		}

		return false;
	}
}

Focus_Color::init();
