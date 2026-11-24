<?php
/**
 * Newspack override of the WC email-editor core/social-links renderer.
 *
 * The package renders icons as `display: inline-table` pills concatenated with
 * no spacing — unlike the editor canvas, which spaces them with the block's gap.
 * The block's `spacing` attribute only affects the wrapper, never between icons,
 * so spacing must be injected into the rendered markup.
 *
 * This override defers to the package for the markup, then adjusts each icon pill:
 * a horizontal margin so the icons are spaced like the canvas, padding in px (the
 * package's `0.25em` resolves against a small font size), Newspack's style variants
 * (filled/circle, black/white), and black icons for a dark custom icon color.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Social_Links as Package_Social_Links;
use Automattic\WooCommerce\EmailEditor\Integrations\Utils\Social_Links_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Adjusts the package's core/social-links email render to match the editor.
 */
class Social_Links extends Package_Social_Links {

	/**
	 * Horizontal margin applied to each side of an icon pill. 6px per side
	 * yields a 12px gap between adjacent icons, matching the canvas block gap.
	 */
	const ICON_SIDE_MARGIN = '6px';

	/**
	 * Pattern matching each icon pill's style marker. The package ends each pill
	 * style with `display:inline-table;float:none;` — a margin is appended to
	 * this marker to space the icons. Whitespace-tolerant in case of formatting changes.
	 *
	 * Couples to package-internal markup; `test_social_links_icons_are_spaced`
	 * pins it and fails loudly if the marker changes.
	 */
	const PILL_STYLE_PATTERN = '/display:\s*inline-table;\s*float:\s*none;/';

	/**
	 * Pattern matching a package icon URL, capturing the service and the variant.
	 */
	const ICON_URL_PATTERN = '#/icons/([a-z0-9-]+)/\1-(white|brand)\.png#';

	/**
	 * Newspack's social-links style variants (registered in the editor), mapped to the
	 * pill background and the icon color they use there. `transparent` removes the
	 * background; `brand` (default style) uses the service's brand color.
	 */
	const STYLE_VARIANTS = [
		'is-style-filled-black'        => [ 'transparent', 'black' ],
		'is-style-filled-white'        => [ 'transparent', 'white' ],
		'is-style-circle-black'        => [ '#000000', 'white' ],
		'is-style-circle-white'        => [ '#ffffff', 'black' ],
		'is-style-filled-primary-text' => [ 'transparent', 'primary-text' ],
	];

	/**
	 * Render the social-links block, then adjust its icons to match the editor.
	 *
	 * @param string            $block_content     Block content.
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$parsed_block = self::apply_justification( $parsed_block );
		$html         = parent::render_content( $block_content, $parsed_block, $rendering_context );
		$html         = self::style_icons( $html, $parsed_block );
		return $this->space_icons( $html );
	}

	/**
	 * Resolve the pill background and icon variant for the block's style and colors.
	 *
	 * @param array $attrs Social-links block attributes.
	 * @return array{0:?string,1:string} Pill background (`transparent`, `brand`, a color,
	 *                                   or null to keep the package's) and icon variant
	 *                                   (`white` or `black`).
	 */
	private static function resolve_appearance( array $attrs ): array {
		$class_name = (string) ( $attrs['className'] ?? '' );
		foreach ( self::STYLE_VARIANTS as $style => $appearance ) {
			if ( preg_match( '/(^|\s)' . preg_quote( $style, '/' ) . '(\s|$)/', $class_name ) ) {
				list( $background, $icon ) = $appearance;
				if ( 'primary-text' === $icon ) {
					$icon = self::is_dark( self::primary_text_color() ) ? 'black' : 'white';
				}
				return [ $background, $icon ];
			}
		}
		// Default style: icons sit on their brand color unless a background is set (the
		// package then already applies it), and take the custom icon color when there is
		// one. The package drops the brand color as soon as the icon color isn't white,
		// leaving white icons on nothing, so it is restored here. The icons are PNGs, so
		// a dark icon color uses the black icon set.
		$icon_color = (string) ( $attrs['iconColorValue'] ?? '' );
		$background = empty( $attrs['iconBackgroundColorValue'] ) ? 'brand' : null;
		return [ $background, '' !== $icon_color && self::is_dark( $icon_color ) ? 'black' : 'white' ];
	}

	/**
	 * Apply the resolved appearance and px padding to each icon pill.
	 *
	 * Leaves logos-only rows (brand-colored icons, no background) to the package.
	 *
	 * @param string $html         Rendered social-links HTML.
	 * @param array  $parsed_block Parsed social-links block.
	 * @return string
	 */
	public static function style_icons( string $html, array $parsed_block ): string {
		$attrs      = $parsed_block['attrs'] ?? [];
		$class_name = (string) ( $attrs['className'] ?? '' );
		if ( false !== strpos( $class_name, 'is-style-logos-only' ) ) {
			return $html;
		}
		list( $background, $icon ) = self::resolve_appearance( $attrs );
		$services                  = self::rendered_services( $parsed_block );
		$padding                   = self::icon_padding( (int) Social_Links_Helper::get_social_link_size_option_value( $attrs['size'] ?? Social_Links_Helper::get_default_social_link_size() ) );
		$cell_padding              = false !== strpos( $class_name, 'is-style-pill-shape' ) ? $padding . ' 0' : $padding;

		$processor = new \WP_HTML_Tag_Processor( $html );
		$index     = -1;
		$state     = '';
		while ( $processor->next_tag() ) {
			$tag   = $processor->get_tag();
			$style = (string) $processor->get_attribute( 'style' );
			if ( 'TABLE' === $tag && preg_match( self::PILL_STYLE_PATTERN, $style ) ) {
				$state = 'pill';
				++$index;
				$fill = 'brand' === $background
					? Social_Links_Helper::get_service_brand_color( $services[ $index ] ?? '' )
					: $background;
				if ( null !== $fill ) {
					$style = (string) preg_replace( '/background-color:[^;]*;?/i', '', $style );
					if ( 'transparent' !== $fill && '' !== $fill ) {
						$style = 'background-color:' . $fill . ';' . $style;
					}
				}
				$processor->set_attribute( 'style', $style );
				continue;
			}
			if ( 'pill' === $state && 'TR' === $tag ) {
				// The package pads the row by 0.25em, which resolves against the cell's small
				// font size; the padding moves to the icon cell in px below.
				$processor->set_attribute( 'style', (string) preg_replace( '/(^|;)\s*padding:\s*0\.25em;?/i', '$1', $style ) );
				$state = 'row';
				continue;
			}
			if ( 'row' === $state && 'TD' === $tag ) {
				$state = 'cell';
				$style = rtrim( trim( $style ), ';' );
				$processor->set_attribute( 'style', ( '' === $style ? '' : $style . ';' ) . 'padding:' . $cell_padding . ';' );
				continue;
			}
			if ( 'cell' === $state && 'IMG' === $tag ) {
				$state = '';
				if ( preg_match( self::ICON_URL_PATTERN, (string) $processor->get_attribute( 'src' ), $matches ) ) {
					$url = self::icon_url( $matches[1], $icon, $background );
					if ( '' !== $url ) {
						$processor->set_attribute( 'src', $url );
					}
				}
			}
		}
		return $processor->get_updated_html();
	}

	/**
	 * Services of the links the package renders, in order: one per icon pill.
	 *
	 * Read from the block rather than from the icon URLs in the rendered HTML, because
	 * the package still renders a pill, with an empty image, for a service it has no icon
	 * for; reading the URLs would shift every later pill onto the wrong service.
	 *
	 * @param array $parsed_block Parsed social-links block.
	 * @return string[]
	 */
	private static function rendered_services( array $parsed_block ): array {
		$services = [];
		foreach ( $parsed_block['innerBlocks'] ?? [] as $link ) {
			$service = (string) ( $link['attrs']['service'] ?? '' );
			// The package skips links without a service or URL, so they get no pill.
			if ( '' !== $service && '' !== (string) ( $link['attrs']['url'] ?? '' ) ) {
				$services[] = strtolower( $service );
			}
		}
		return $services;
	}

	/**
	 * Padding around an icon inside its pill, matching vanilla WordPress (`0.25em` of
	 * the icon size: 6px for the normal 24px icons).
	 *
	 * @param int $icon_size Icon size in px.
	 * @return string
	 */
	private static function icon_padding( int $icon_size ): string {
		$icon_size = $icon_size > 0 ? $icon_size : 24;
		return round( $icon_size / 4 ) . 'px';
	}

	/**
	 * URL of the icon to use for a service.
	 *
	 * White icons come from the package. Black icons come from Newspack's own set (the
	 * package only ships white and brand-colored ones); for a service without a black
	 * icon, a transparent pill falls back to the brand-colored icon so it stays visible,
	 * and a colored pill keeps the white one.
	 *
	 * @param string      $service    Service name.
	 * @param string      $icon       Icon variant, `white` or `black`.
	 * @param string|null $background Pill background (`transparent`, `brand`, a color, or null).
	 * @return string Icon URL, or '' to keep the package's.
	 */
	private static function icon_url( string $service, string $icon, ?string $background ): string {
		if ( 'black' !== $icon ) {
			return '';
		}
		$file = 'assets/black-' . $service . '.png';
		if ( file_exists( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . $file ) ) {
			return plugins_url( $file, NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'newspack-newsletters.php' );
		}
		if ( 'transparent' === $background ) {
			return ( new Package_Social_Links() )->get_service_icon_url( $service, 'brand' );
		}
		return '';
	}

	/**
	 * The newsletter palette's primary text color, used by the "filled primary text"
	 * style (as the MJML renderer does).
	 *
	 * @return string
	 */
	private static function primary_text_color(): string {
		$palette = json_decode( (string) get_option( \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_PALETTE_META, '{}' ), true );
		$color   = is_array( $palette ) ? (string) ( $palette['primary-text'] ?? '' ) : '';
		return 'black' === $color ? '#000000' : $color;
	}

	/**
	 * Whether a hex color is dark (relative luminance below 0.5).
	 *
	 * @param string $color Hex color.
	 * @return bool False for anything that isn't a hex color.
	 */
	private static function is_dark( string $color ): bool {
		$hex = ltrim( (string) sanitize_hex_color( trim( $color ) ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return false;
		}
		$luminance = ( 0.2126 * hexdec( substr( $hex, 0, 2 ) ) + 0.7152 * hexdec( substr( $hex, 2, 2 ) ) + 0.0722 * hexdec( substr( $hex, 4, 2 ) ) ) / 255;
		return $luminance < 0.5;
	}

	/**
	 * Map `layout.justifyContent` to `textAlign` for the package renderer.
	 *
	 * The package derives alignment from `textAlign`/`align`, not from the flex
	 * layout's `justifyContent` — so a centered row in the editor renders
	 * left-aligned in email. Only applied when `textAlign` isn't already set.
	 *
	 * @param array $parsed_block Parsed block.
	 * @return array Parsed block, possibly with `attrs.textAlign` set.
	 */
	private static function apply_justification( array $parsed_block ): array {
		if ( ! empty( $parsed_block['attrs']['textAlign'] ) ) {
			return $parsed_block;
		}
		$justify = $parsed_block['attrs']['layout']['justifyContent'] ?? '';
		$map     = [
			'left'   => 'left',
			'center' => 'center',
			'right'  => 'right',
		];
		if ( isset( $map[ $justify ] ) ) {
			$parsed_block['attrs']['textAlign'] = $map[ $justify ];
		}
		return $parsed_block;
	}

	/**
	 * Add a horizontal margin to each icon pill so icons are spaced like the editor.
	 *
	 * Returns HTML unchanged if the package marker is absent (no breakage).
	 * Coalesces to input on PCRE error — a null return would violate the `: string`
	 * type and, since the package has no per-block try/catch, collapse the whole
	 * newsletter body.
	 *
	 * @param string $html Rendered social-links HTML.
	 * @return string
	 */
	private function space_icons( string $html ): string {
		return preg_replace(
			self::PILL_STYLE_PATTERN,
			sprintf( '$0 margin-left: %1$s; margin-right: %1$s;', self::ICON_SIDE_MARGIN ),
			$html
		) ?? $html;
	}
}

// Self-register via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'core/social-links', Social_Links::class );
