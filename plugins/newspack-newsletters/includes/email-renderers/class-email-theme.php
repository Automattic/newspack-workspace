<?php
/**
 * Canonical email theme fragment shared by the newsletter editor and the WC renderer.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for block styling that must match between the editor
 * canvas and the rendered email. Currently carries only the button; grows into
 * the full canonical email theme as more blocks are reconciled.
 */
class Email_Theme {
	/**
	 * Canonical theme.json fragment.
	 *
	 * @param \WP_Post|null $post          Newsletter post (reserved for future per-newsletter styling).
	 * @param string|null   $primary_color Pre-resolved primary color for the button background. When
	 *                                     null, it is resolved via Lite_Site::get_primary_color(). The
	 *                                     editor must pass this explicitly because the primary color is
	 *                                     not resolvable inside the `wp_theme_json_data_theme` filter on
	 *                                     block themes (see resolve_primary_color()).
	 * @return array Theme.json-shaped array.
	 */
	public static function canonical( ?\WP_Post $post = null, ?string $primary_color = null ): array {
		unset( $post ); // Reserved; the button is not yet per-newsletter.
		$button = self::button_styles( $primary_color );
		return [
			'version' => 3,
			'styles'  => [
				// The button is defined at BOTH the element and the core/button block
				// level. The render only needs the element styles (it inlines them),
				// but in the editor canvas a block theme's own `styles.blocks.core/button`
				// rule (selector `.wp-block-button .wp-block-button__link`) sits at equal
				// specificity and later source order than the element rule, so it would
				// otherwise win on shared properties like padding. Defining core/button
				// here merges over the theme's and keeps the editor matching the render.
				'elements' => [
					'button' => $button,
				],
				'blocks'   => [
					'core/button' => $button,
				],
			],
		];
	}

	/**
	 * Resolve the button's primary background color.
	 *
	 * On block themes, `Lite_Site::get_primary_color()` returns the literal
	 * `currentcolor` when called inside the `wp_theme_json_data_theme` filter (it
	 * guards against re-entry, because reading global settings re-fires that
	 * filter). The newsletter editor applies the canonical button from within that
	 * filter, so it would otherwise get `currentcolor` instead of the real brand
	 * color the render uses. When a `WP_Theme_JSON_Data` is supplied (the editor
	 * path) we recover the color from its already-resolved palette, which is
	 * available in-filter without recursion.
	 *
	 * @param \WP_Theme_JSON_Data|null $theme_json Theme JSON being filtered, when called from the editor.
	 * @return string A hex color (or other valid CSS color), never `currentcolor`.
	 */
	public static function resolve_primary_color( $theme_json = null ): string {
		$primary = '';
		if ( method_exists( '\Newspack\Lite_Site', 'get_primary_color' ) ) {
			$primary = (string) \Newspack\Lite_Site::get_primary_color();
		}

		if ( self::is_unresolved( $primary ) && $theme_json instanceof \WP_Theme_JSON_Data ) {
			$palette_color = self::first_palette_color( $theme_json->get_data() );
			if ( '' !== $palette_color ) {
				$primary = $palette_color;
			}
		}

		return self::is_unresolved( $primary ) ? '#36f' : $primary;
	}

	/**
	 * Whether a resolved primary color is unusable (empty or the recursion-guard sentinel).
	 *
	 * @param string $color Color value.
	 * @return bool
	 */
	private static function is_unresolved( string $color ): bool {
		return '' === $color || 'currentcolor' === strtolower( $color );
	}

	/**
	 * First palette color from a theme.json data array, mirroring the origin
	 * preference (custom > theme > default) used by Lite_Site::get_primary_color().
	 *
	 * @param array $data Theme.json data array (from WP_Theme_JSON_Data::get_data()).
	 * @return string Hex color, or '' when no palette is present.
	 */
	private static function first_palette_color( array $data ): string {
		$palette = $data['settings']['color']['palette'] ?? [];

		// Flat list (already merged across origins).
		if ( isset( $palette[0]['color'] ) ) {
			return (string) $palette[0]['color'];
		}

		// Origin-keyed list.
		foreach ( [ 'custom', 'theme', 'default' ] as $origin ) {
			if ( ! empty( $palette[ $origin ][0]['color'] ) ) {
				return (string) $palette[ $origin ][0]['color'];
			}
		}

		return '';
	}

	/**
	 * Branded button styling applied to both the editor and the render.
	 *
	 * @param string|null $primary_color Pre-resolved primary color, or null to resolve now.
	 * @return array Theme.json `elements.button` styles.
	 */
	private static function button_styles( ?string $primary_color = null ): array {
		$primary = ( null === $primary_color ) ? self::resolve_primary_color() : $primary_color;
		return [
			'color'   => [
				'background' => $primary,
				'text'       => '#fff',
			],
			'border'  => [
				'radius' => '5px',
			],
			'spacing' => [
				'padding' => [
					'top'    => '12px',
					'bottom' => '12px',
					'left'   => '24px',
					'right'  => '24px',
				],
			],
		];
	}
}
