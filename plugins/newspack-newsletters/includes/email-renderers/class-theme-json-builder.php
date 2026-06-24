<?php
/**
 * Builds a per-newsletter theme.json array from existing post meta.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Email_Renderers;

defined( 'ABSPATH' ) || exit;

/**
 * Translates Newspack per-newsletter theme post-meta into the theme.json shape
 * the WC renderer consumes. Non-destructive: reads meta, never writes it.
 */
class Theme_Json_Builder {
	/**
	 * Newspack newsletter font-size scale (slug => CSS size). Mirrors
	 * Newspack_Newsletters_Renderer::get_font_size() so presets resolve to the
	 * same pixel values the newsletter editor has always used.
	 *
	 * @var array
	 */
	const FONT_SIZES = [
		'xx-small'     => '8px',
		'x-small'      => '10px',
		'small'        => '12px',
		'normal'       => '16px',
		'medium'       => '16px',
		'large'        => '24px',
		'huge'         => '36px',
		'x-large'      => '36px',
		'xx-large'     => '40px',
		'xxx-large'    => '48px',
		'xxxx-large'   => '56px',
		'xxxxx-large'  => '64px',
		'xxxxxx-large' => '72px',
	];

	/**
	 * Newspack newsletter spacing scale (slug => CSS size). Mirrors the presets
	 * in Newspack_Newsletters_Renderer::get_spacing_value() so
	 * `var:preset|spacing|*` references resolve.
	 *
	 * @var array
	 */
	const SPACING_SIZES = [
		'20' => '8px',
		'30' => '16px',
		'40' => '24px',
		'50' => '32px',
		'60' => '32px',
		'70' => '48px',
		'80' => '64px',
	];

	/**
	 * Default heading font stack when meta is absent/unsupported.
	 *
	 * @var string
	 */
	const DEFAULT_HEADER_FONT = 'Arial, Helvetica, sans-serif';

	/**
	 * Default body font stack when meta is absent/unsupported.
	 *
	 * @var string
	 */
	const DEFAULT_BODY_FONT = 'Georgia, serif';

	/**
	 * Build a theme.json-shaped array for a newsletter.
	 *
	 * @param \WP_Post $post Newsletter post.
	 * @return array
	 */
	public static function build( \WP_Post $post ): array {
		$background = \sanitize_hex_color( (string) \get_post_meta( $post->ID, 'background_color', true ) );
		$text       = \sanitize_hex_color( (string) \get_post_meta( $post->ID, 'text_color', true ) );

		// Resolve fonts through the shared precedence chain: explicit newsletter
		// meta → global styles → active theme fonts → hardcoded default. An
		// un-customized newsletter therefore inherits the theme's fonts (matching
		// the standard post editor) instead of the hardcoded Arial/Georgia.
		$fonts       = Fonts::resolve( $post );
		$header_font = $fonts['header'];
		$body_font   = $fonts['body'];

		$settings = [
			'spacing'    => [
				'spacingSizes' => self::build_presets( self::SPACING_SIZES ),
			],
			'typography' => [
				// Disable fluid typography so font sizes resolve to fixed pixels in email.
				'fluid'     => false,
				'fontSizes' => self::build_presets( self::FONT_SIZES ),
			],
		];

		// Only emit the palette when the newsletter configures one. WP_Theme_JSON::merge()
		// replaces preset arrays per origin, so an empty palette would wipe the editor's
		// default color presets rather than leave them intact.
		$palette = self::build_palette();
		if ( ! empty( $palette ) ) {
			$settings['color'] = [ 'palette' => $palette ];
		}

		$styles = [
			'color'      => [
				'background' => $background ? $background : '#ffffff',
				'text'       => $text ? $text : '#000000',
			],
			'typography' => [
				'fontFamily' => $body_font,
			],
			'elements'   => [
				'heading' => [
					'typography' => [
						'fontFamily' => $header_font,
					],
				],
			],
		];

		// Emit email-safe button styles when the WC renderer is active.
		// The WC email package drops CSS-var and rem values it cannot resolve,
		// so we resolve the theme's button radius and padding to px literals here.
		if ( Feature_Flag::is_enabled() ) {
			$styles['elements']['button'] = [
				'border' => [
					'radius' => self::resolve_button_border_radius(),
				],
			];

			// Emit padding only when the active theme defines button padding.
			// Classic themes (newspack-theme) define no button padding in theme.json,
			// so we must not emit a padding key for them — leaving the render unchanged.
			$padding = self::resolve_button_padding();
			if ( ! empty( $padding ) ) {
				$styles['elements']['button']['spacing'] = [
					'padding' => $padding,
				];
			}
		}

		return [
			'version'  => 3,
			'settings' => $settings,
			'styles'   => $styles,
		];
	}

	/**
	 * Resolve the active theme's button border-radius to an email-safe px string.
	 *
	 * Reads `styles.elements.button.border.radius` from the merged theme.json.
	 * Falls back to `Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS` when the theme
	 * defines nothing.
	 *
	 * @return string Email-safe border-radius value (e.g. "6px", "4px").
	 */
	private static function resolve_button_border_radius(): string {
		$merged = \WP_Theme_JSON_Resolver::get_merged_data();
		return self::resolve_button_border_radius_from_raw( $merged->get_raw_data() );
	}

	/**
	 * Resolve a button border-radius from a raw theme.json data array.
	 *
	 * If the value is a `var( --wp--custom--... )` reference, it is resolved via
	 * `settings.custom`. rem/em values are converted to px (× 16). Values already
	 * in px pass through unchanged. Any non-px result (e.g. `50%`, `vw` units, or
	 * an unresolvable var) falls back to the email-safe default.
	 *
	 * @param array $raw Raw theme.json data array (from WP_Theme_JSON::get_raw_data()).
	 * @return string Email-safe border-radius px value (e.g. "6px", "4px").
	 */
	protected static function resolve_button_border_radius_from_raw( array $raw ): string {
		$radius = $raw['styles']['elements']['button']['border']['radius'] ?? null;

		if ( empty( $radius ) ) {
			return Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS;
		}

		$px = self::resolve_length_to_px( $radius, $raw );

		if ( null === $px ) {
			return Email_Defaults::DEFAULT_BUTTON_BORDER_RADIUS;
		}

		return $px;
	}

	/**
	 * Resolve the active theme's button padding to email-safe px strings, keyed
	 * by side (`top`, `right`, `bottom`, `left`).
	 *
	 * Returns an empty array when the theme defines no button padding (classic
	 * theme scenario), so callers can skip emitting the key entirely.
	 *
	 * @return array<string,string> Map of side → px value for each resolved side.
	 */
	private static function resolve_button_padding(): array {
		$merged = \WP_Theme_JSON_Resolver::get_merged_data();
		return self::resolve_button_padding_from_raw( $merged->get_raw_data() );
	}

	/**
	 * Resolve button padding sides from a raw theme.json data array.
	 *
	 * Reads `styles.elements.button.spacing.padding` and resolves each side
	 * (`top`, `right`, `bottom`, `left`) to a px value via the shared length
	 * resolver. Sides that cannot resolve to px are omitted. Returns an empty
	 * array when no button padding is defined (theme defines nothing → no emit).
	 *
	 * @param array $raw Raw theme.json data array (from WP_Theme_JSON::get_raw_data()).
	 * @return array<string,string> Map of side → px value for each resolved side.
	 */
	protected static function resolve_button_padding_from_raw( array $raw ): array {
		$padding = $raw['styles']['elements']['button']['spacing']['padding'] ?? null;

		if ( empty( $padding ) || ! \is_array( $padding ) ) {
			return [];
		}

		$resolved = [];
		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$value = $padding[ $side ] ?? null;
			if ( ! \is_string( $value ) || '' === $value ) {
				continue;
			}
			$px = self::resolve_length_to_px( $value, $raw );
			if ( null !== $px ) {
				$resolved[ $side ] = $px;
			}
		}

		return $resolved;
	}

	/**
	 * Resolve a CSS length value to an email-safe px string.
	 *
	 * Handles:
	 * - `var( --wp--custom--... )` → traverses `settings.custom` by the
	 *   double-dash-delimited path segments.
	 * - `var( --wp--preset--spacing--N )` → looks up the slug in
	 *   `settings.spacing.spacingSizes` (theme.json preset array format).
	 *   When the theme's size is a px-convertible value (px/rem/em), it is
	 *   used directly; when it is a fluid `clamp(...)` or any other
	 *   non-convertible value the email-safe `SPACING_SIZES` map is used as
	 *   a fallback.
	 * - `rem` / `em` → converts to px (× 16, standard email base font size).
	 * - Plain `px` → passes through unchanged.
	 * - Anything else (percentages, vw, unresolvable vars, etc.) → returns null.
	 *
	 * @param string $value CSS length string to resolve (e.g. "var( --wp--custom--spacing--25 )").
	 * @param array  $raw   Raw theme.json data (from WP_Theme_JSON::get_raw_data()).
	 * @return string|null Resolved px string (e.g. "12px") or null if unresolvable.
	 */
	protected static function resolve_length_to_px( string $value, array $raw ): ?string {
		$value = trim( $value );

		// Resolve a `var( --wp--... )` reference.
		if ( preg_match( '/^var\(\s*(--wp--[a-z0-9_-]+(?:--[a-z0-9_-]+)*)\s*\)$/i', $value, $matches ) ) {
			$var_name = $matches[1]; // e.g. "--wp--custom--spacing--25".

			// Preset spacing var: --wp--preset--spacing--<slug>.
			if ( preg_match( '/^--wp--preset--spacing--([a-z0-9_-]+)$/i', $var_name, $preset_matches ) ) {
				$slug       = $preset_matches[1];
				$size_items = $raw['settings']['spacing']['spacingSizes'] ?? [];

				// Prefer the theme's own preset size — but ONLY when it is a value
				// we can convert to px (px/rem/em literal). Fluid `clamp(...)` values
				// (e.g. newspack-block-theme presets 60/70/80) are NOT email-safe and
				// must be bypassed in favour of the email-safe SPACING_SIZES scale.
				$theme_size = null;
				foreach ( $size_items as $item ) {
					if (
						isset( $item['slug'], $item['size'] ) &&
						is_string( $item['size'] ) &&
						(string) $item['slug'] === $slug
					) {
						$theme_size = $item['size'];
						break;
					}
				}

				// Use the theme size only when it is a directly-convertible literal.
				if ( null !== $theme_size && preg_match( '/^[\d.]+r?em$|^\d+(?:\.\d+)?px$/i', $theme_size ) ) {
					$value = $theme_size;
				} else {
					// Theme size is absent, clamp/fluid, or not a plain px/rem/em →
					// fall back to the email-safe built-in scale.
					$value = self::SPACING_SIZES[ $slug ] ?? null;
					if ( null === $value ) {
						return null;
					}
				}
			} elseif ( preg_match( '/^--wp--custom--(.+)$/i', $var_name, $custom_matches ) ) {
				// Custom var: --wp--custom--<path> where <path> uses -- as separator.
				$segments = explode( '--', $custom_matches[1] );
				$custom   = $raw['settings']['custom'] ?? [];
				foreach ( $segments as $segment ) {
					if ( ! \is_array( $custom ) || ! \array_key_exists( $segment, $custom ) ) {
						return null;
					}
					$custom = $custom[ $segment ];
				}
				if ( \is_string( $custom ) && '' !== $custom ) {
					$value = $custom;
				} else {
					return null;
				}
			} else {
				// Unknown var type — cannot resolve.
				return null;
			}
		}

		// Convert rem/em to px (assume 1rem = 16px, standard for email clients).
		// Preserve fractional precision (e.g. 0.28125rem → 4.5px, 0.375rem → 6px).
		if ( preg_match( '/^([\d.]+)r?em$/i', $value, $m ) ) {
			$px = round( (float) $m[1] * 16, 4 );
			return rtrim( rtrim( sprintf( '%.4f', $px ), '0' ), '.' ) . 'px';
		}

		// Plain px passes through unchanged.
		if ( preg_match( '/^\d+(?:\.\d+)?px$/', $value ) ) {
			return $value;
		}

		// Anything else (percentages, vw, unresolvable, etc.) is not email-safe.
		return null;
	}

	/**
	 * Build the theme color palette from the newsletter color-palette option.
	 *
	 * @return array Theme.json color palette entries.
	 */
	private static function build_palette(): array {
		$option  = \json_decode( (string) \get_option( \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_PALETTE_META, '{}' ), true );
		$palette = [];
		if ( ! \is_array( $option ) ) {
			return $palette;
		}
		foreach ( $option as $slug => $hex ) {
			// Slugs become CSS custom-property/classname fragments, so sanitize them.
			$slug  = \sanitize_key( (string) $slug );
			$color = \sanitize_hex_color( (string) $hex );
			if ( '' === $slug || ! $color ) {
				continue;
			}
			$palette[] = [
				'slug'  => $slug,
				'color' => $color,
				'name'  => $slug,
			];
		}
		return $palette;
	}

	/**
	 * Convert a slug => size map into theme.json preset entries.
	 *
	 * Font-size presets get translatable display names so they surface correctly
	 * in the editor typography panel (matching the labels the legacy MJML path used).
	 * Spacing presets keep slug names (not user-facing).
	 *
	 * @param array $map Slug => CSS size.
	 * @return array Theme.json preset entries ({ slug, size, name }).
	 */
	private static function build_presets( array $map ): array {
		// Translatable display names for the editor-visible font-size presets.
		// Only the slugs that appear in FONT_SIZES and surface in the typography panel.
		$font_size_labels = [
			'xx-small'     => _x( 'Extra Extra Small', 'font size name', 'newspack-newsletters' ),
			'x-small'      => _x( 'Extra Small', 'font size name', 'newspack-newsletters' ),
			'small'        => _x( 'Small', 'font size name', 'newspack-newsletters' ),
			'normal'       => _x( 'Normal', 'font size name', 'newspack-newsletters' ),
			'medium'       => _x( 'Medium', 'font size name', 'newspack-newsletters' ),
			'large'        => _x( 'Large', 'font size name', 'newspack-newsletters' ),
			'huge'         => _x( 'Huge', 'font size name', 'newspack-newsletters' ),
			'x-large'      => _x( 'Extra Large', 'font size name', 'newspack-newsletters' ),
			'xx-large'     => _x( 'Extra Extra Large', 'font size name', 'newspack-newsletters' ),
			'xxx-large'    => _x( 'XXX Large', 'font size name', 'newspack-newsletters' ),
			'xxxx-large'   => _x( 'XXXX Large', 'font size name', 'newspack-newsletters' ),
			'xxxxx-large'  => _x( 'XXXXX Large', 'font size name', 'newspack-newsletters' ),
			'xxxxxx-large' => _x( 'XXXXXX Large', 'font size name', 'newspack-newsletters' ),
		];

		$presets = [];
		foreach ( $map as $slug => $size ) {
			$presets[] = [
				'slug' => (string) $slug,
				'size' => $size,
				'name' => $font_size_labels[ (string) $slug ] ?? (string) $slug,
			];
		}
		return $presets;
	}
}
