<?php
/**
 * Newspack UI Theme style adapter.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a block theme's global styles and prints the matching newspack-ui custom properties.
 *
 * A theme opts in with add_theme_support( 'newspack-ui-theme-style' ). The newspack_ui_theme_style_active filter
 * can force the Neutral style back on.
 */
class Newspack_UI_Theme_Style {

	const THEME_SUPPORT = 'newspack-ui-theme-style';

	/**
	 * Preset types that "var:preset|<type>|<slug>" can point at: settings path and value key.
	 */
	const PRESET_TYPES = [
		'border-radius' => [ [ 'border', 'radiusSizes' ], 'size' ],
		'color'         => [ [ 'color', 'palette' ], 'color' ],
		'font-family'   => [ [ 'typography', 'fontFamilies' ], 'fontFamily' ],
		'font-size'     => [ [ 'typography', 'fontSizes' ], 'size' ],
		'gradient'      => [ [ 'color', 'gradients' ], 'gradient' ],
		'spacing'       => [ [ 'spacing', 'spacingSizes' ], 'size' ],
	];

	/**
	 * Custom property prefix to the value type its values are checked as. A property under no prefix is never printed.
	 */
	const PROPERTY_TYPES = [
		'--newspack-ui-color-'                => 'color',
		'--newspack-ui-radius-'               => 'length',
		'--newspack-ui-font-family-'          => 'font-family',
		'--newspack-ui-font-size-'            => 'length',
		'--newspack-ui-line-height-'          => 'line-height',
		'--newspack-ui-button-font-family'    => 'font-family',
		'--newspack-ui-button-font-weight'    => 'weight',
		'--newspack-ui-button-letter-spacing' => 'letter-spacing',
		'--newspack-ui-button-text-transform' => 'text-transform',
		'--newspack-ui-button-white-space'    => 'white-space',
		'--newspack-ui-heading-font-weight'   => 'weight',
		'--newspack-ui-link-hover-decoration' => 'text-decoration',
		'--newspack-ui-field-radius'          => 'length',
		'--newspack-ui-field-border-width'    => 'length',
		'--newspack-ui-field-border-color'    => 'color',
		'--newspack-ui-field-bg'              => 'color',
		'--newspack-ui-field-ink'             => 'color',
		'--newspack-ui-field-font-family'     => 'font-family',
		'--newspack-ui-field-caret'           => 'reference',
		'--newspack-ui-field-ink-subtle'      => 'color',
		'--newspack-ui-field-ink-muted'       => 'color',
	];

	/**
	 * Type scale tokens to the font size preset slug they read.
	 */
	const SIZE_SCALE = [
		'caption' => 'xx-small',
		'small'   => 'x-small',
		'body'    => 'small',
		'title-m' => 'medium',
		'title-l' => 'x-large',
		'display' => 'xx-large',
	];

	/**
	 * The global styles subtrees the adapter reads, as key paths.
	 */
	const READ_PATHS = [
		[ 'color' ],
		[ 'elements', 'button' ],
		[ 'blocks', 'core/button', 'border' ],
		[ 'blocks', 'core/button', 'color' ],
		[ 'blocks', 'core/button', 'typography' ],
		[ 'blocks', 'core/button', ':hover' ],
		[ 'blocks', 'core/button', ':active' ],
		[ 'elements', 'link' ],
		[ 'elements', 'caption' ],
		[ 'elements', 'heading' ],
		[ 'elements', 'textInput' ],
		[ 'elements', 'select' ],
		[ 'typography' ],
	];

	/**
	 * A font family list: quoted names (a quote of the other kind may sit inside) and plain names.
	 */
	const FONT_FAMILY_PATTERN = '/^\s*(?:"[^"\r\n\f]*"|\'[^\'\r\n\f]*\'|[A-Za-z0-9 ._-]+)\s*(?:,\s*(?:"[^"\r\n\f]*"|\'[^\'\r\n\f]*\'|[A-Za-z0-9 ._-]+)\s*)*$/';

	/**
	 * Most passes of reference resolution, for presets that are themselves references.
	 */
	const MAX_PASSES = 3;

	/**
	 * Resolved global styles for this request.
	 *
	 * @var array|null
	 */
	private static ?array $global_styles = null;

	/**
	 * The ':root' rule for this request.
	 *
	 * @var string|null
	 */
	private static ?string $css = null;

	/**
	 * Whether the site's Theme style printed palette colors.
	 *
	 * @var bool|null
	 */
	private static ?bool $has_palette = null;

	/**
	 * Register the hooks that drop the memoized global styles and print the rule.
	 */
	public static function init(): void {
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'print_css' ], 20 );
		\add_action( 'enqueue_block_assets', [ __CLASS__, 'print_css' ], 20 );
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'print_css' ], 20 );
		\add_action( 'save_post_wp_global_styles', [ __CLASS__, 'reset_cache' ], 10, 0 );
		\add_action( 'switch_theme', [ __CLASS__, 'reset_cache' ], 10, 0 );
		\add_action( 'switch_blog', [ __CLASS__, 'reset_cache' ], 10, 0 );
	}

	/**
	 * Attach the ':root' rule to the newspack-ui stylesheet.
	 *
	 * The guard reads the handle's own inline data rather than a static flag, because the editor collects
	 * its iframe assets in a separate styles instance, possibly sharing registered handles.
	 */
	public static function print_css(): void {
		if ( ! self::is_active() || ! \wp_style_is( 'newspack-ui', 'enqueued' ) ) {
			return;
		}

		$css = self::get_site_css();
		if ( '' === $css ) {
			return;
		}

		$inline = (array) \wp_styles()->get_data( 'newspack-ui', 'after' );
		if ( in_array( $css, $inline, true ) ) {
			return;
		}

		\wp_add_inline_style( 'newspack-ui', $css );
	}

	/**
	 * Drop the memoized global styles.
	 */
	public static function reset_cache(): void {
		self::$global_styles = null;
		self::$css           = null;
		self::$has_palette   = null;
	}

	/**
	 * Whether the Theme style is on: the theme opted in and no filter turned it off.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		$supported = \current_theme_supports( self::THEME_SUPPORT );

		/**
		 * Filters whether the newspack-ui Theme style is active. Returning false forces the Neutral style.
		 *
		 * Forcing Neutral on a dark block theme also gives the theme's own notices Neutral status colors, since they read newspack-ui first.
		 *
		 * @param bool $supported Whether the active theme declares support for the Theme style.
		 */
		return (bool) \apply_filters( 'newspack_ui_theme_style_active', $supported );
	}

	/**
	 * The theme's global styles with every preset and custom reference resolved.
	 *
	 * @return array
	 */
	public static function get_global_styles(): array {
		if ( null === self::$global_styles ) {
			$styles = \wp_get_global_styles( [], [ 'transforms' => [ 'resolve-variables' ] ] );
			$styles = is_array( $styles ) ? $styles : [];

			$picked = [];
			foreach ( self::READ_PATHS as $path ) {
				$subtree = self::dig( $styles, $path );
				if ( null === $subtree ) {
					continue;
				}
				$target = &$picked;
				foreach ( $path as $key ) {
					if ( ! isset( $target[ $key ] ) || ! is_array( $target[ $key ] ) ) {
						$target[ $key ] = [];
					}
					$target = &$target[ $key ];
				}
				$target = $subtree;
				unset( $target );
			}

			$authored = self::get_authored_styles();
			$picked   = self::apply_fluid_sizes( self::mask_to_authored( $picked, $authored ), $authored, self::get_settings() );

			self::$global_styles = self::resolve_references( $picked, self::get_settings() );
		}

		return self::$global_styles;
	}

	/**
	 * The ':root' rule for the site's own global styles, solved once per request.
	 *
	 * @return string The rule, or an empty string when there is nothing to print.
	 */
	public static function get_site_css(): string {
		if ( null === self::$css ) {
			$user_styles = self::get_live_user_styles();
			$solved      = self::solve(
				self::get_global_styles(),
				self::get_settings(),
				$user_styles,
				static function () {
					return self::get_live_variation_styles();
				}
			);

			self::$has_palette = $solved['has_palette'];
			self::$css         = self::render( $solved['properties'], $solved['high_contrast'] );
		}

		return self::$css;
	}

	/**
	 * The button radius the Theme style prints as '--newspack-ui-radius-button'.
	 *
	 * @return string The length, or an empty string when the style does not print one.
	 */
	public static function get_button_radius(): string {
		return self::get_radius_properties( self::get_global_styles() )['--newspack-ui-radius-button'] ?? '';
	}

	/**
	 * Whether the site's Theme style prints palette colors.
	 *
	 * @return bool
	 */
	public static function has_color_properties(): bool {
		self::get_site_css();

		return true === self::$has_palette;
	}

	/**
	 * The palette the adapter derives the colors from.
	 *
	 * @param array      $global_styles Resolved global styles.
	 * @param array|null $settings      Merged global settings, or null for the site's own.
	 *
	 * @return array{base:string,contrast:string,brand:string,brand_text:string}
	 */
	public static function get_palette( array $global_styles, ?array $settings = null ): array {
		$settings = $settings ?? self::get_settings();

		$brand = self::plain_hex( self::button_value( $global_styles, [ 'color', 'background' ] ) );
		if ( '' === $brand ) {
			$accent = self::flatten_presets( self::dig( $settings, [ 'color', 'palette' ] ) )['accent']['color'] ?? '';
			$brand  = self::plain_hex( $accent );
		}

		return [
			'base'       => is_string( $global_styles['color']['background'] ?? null ) ? $global_styles['color']['background'] : '',
			'contrast'   => is_string( $global_styles['color']['text'] ?? null ) ? $global_styles['color']['text'] : '',
			'brand'      => $brand,
			'brand_text' => self::plain_hex( self::button_value( $global_styles, [ 'color', 'text' ] ) ),
		];
	}

	/**
	 * Every custom property the Theme style sets, as '--newspack-ui-*' => value.
	 *
	 * @param array          $global_styles    Resolved global styles.
	 * @param array|null     $settings         Merged global settings, or null for the site's own.
	 * @param array          $user_styles      The user origin's styles.
	 * @param array|\Closure $variation_styles The styles of the theme's style variations, or a closure returning them.
	 *
	 * @return array<string,string>
	 */
	public static function get_properties( array $global_styles, ?array $settings = null, array $user_styles = [], array|\Closure $variation_styles = [] ): array {
		return self::solve( $global_styles, $settings ?? self::get_settings(), $user_styles, $variation_styles )['properties'];
	}

	/**
	 * Solve every property and note whether the palette printed.
	 *
	 * @param array          $global_styles    Resolved global styles.
	 * @param array          $settings         Merged global settings.
	 * @param array          $user_styles      The user origin's styles.
	 * @param array|\Closure $variation_styles The styles of the theme's style variations, or a closure returning them.
	 *
	 * @return array{properties:array<string,string>,has_palette:bool,high_contrast:array<string,string>}
	 */
	private static function solve( array $global_styles, array $settings, array $user_styles, array|\Closure $variation_styles ): array {
		$has_palette = false;
		$properties  = array_merge(
			self::get_color_properties( $global_styles, $settings, $user_styles, $variation_styles, $has_palette ),
			self::get_radius_properties( $global_styles ),
			self::get_typography_properties( $global_styles, $settings ),
			self::get_field_properties( $global_styles )
		);

		if ( ! isset( $properties['--newspack-ui-field-caret'] ) ) {
			$base = self::expand_hex( (string) self::get_palette( $global_styles, $settings )['base'] );
			if ( '' !== $base && ! self::prefers_dark_caret( $base ) ) {
				$properties['--newspack-ui-field-caret'] = 'var(--newspack-ui-field-caret-light)';
			}
		}

		return [
			'properties'    => $properties,
			'has_palette'   => $has_palette,
			'high_contrast' => $has_palette ? Newspack_UI_Palette::get_high_contrast_properties( self::get_palette( $global_styles, $settings ) ) : [],
		];
	}

	/**
	 * The palette roles, the button state overrides, the link colors and the fine print color.
	 *
	 * The fine print color is the caption color the publisher set, which the theme renders its own captions with, so it is not APCA-checked.
	 *
	 * @param array          $global_styles    Resolved global styles.
	 * @param array          $settings         Merged global settings.
	 * @param array          $user_styles      The user origin's styles.
	 * @param array|\Closure $variation_styles The styles of the theme's style variations, or a closure returning them.
	 * @param bool           $has_palette      Set to whether the palette printed.
	 *
	 * @return array<string,string>
	 */
	private static function get_color_properties( array $global_styles, array $settings, array $user_styles, array|\Closure $variation_styles, bool &$has_palette ): array {
		$palette = self::get_palette( $global_styles, $settings );
		$states  = self::get_user_states( $user_styles, $variation_styles, $settings );
		$shares  = is_array( $settings['custom']['state'] ?? null ) ? $settings['custom']['state'] : [];
		foreach ( [ 'hover', 'pressed' ] as $state ) {
			if ( is_string( $shares[ $state ] ?? null ) && 1 === preg_match( '/^\s*(\d+(?:\.\d+)?)\s*%\s*$/', $shares[ $state ], $match ) && (float) $match[1] <= 100 ) {
				$palette[ $state . '_share' ] = (float) $match[1] / 100;
			}
		}
		$palette['skip_states'] = array_keys( $states );

		$properties  = Newspack_UI_Palette::get_properties( $palette );
		$has_palette = [] !== $properties;
		if ( isset( $properties['--newspack-ui-color-button-bg'] ) ) {
			if ( isset( $states['hover'] ) ) {
				$properties['--newspack-ui-color-button-bg-hover'] = $states['hover'];
				$properties['--newspack-ui-color-btn-hover']       = $states['hover'];
			}
			if ( isset( $states['pressed'] ) ) {
				$properties['--newspack-ui-color-btn-pressed'] = $states['pressed'];
			}

			$hover_text = self::plain_hex( self::button_value( $global_styles, [ ':hover', 'color', 'text' ] ) );
			if ( '' !== $hover_text && isset( $properties['--newspack-ui-color-btn-hover'] ) && Newspack_UI_Palette::lc( $hover_text, $properties['--newspack-ui-color-btn-hover'] ) >= Newspack_UI_Palette::BUTTON_MIN_LC ) {
				$properties['--newspack-ui-color-button-text-hover'] = $hover_text;
			}
		}

		$link = $global_styles['elements']['link'] ?? [];
		self::put( $properties, '--newspack-ui-color-link', $link['color']['text'] ?? null );
		self::put( $properties, '--newspack-ui-color-link-hover', $link[':hover']['color']['text'] ?? null );
		self::put( $properties, '--newspack-ui-link-hover-decoration', $link[':hover']['typography']['textDecoration'] ?? null );

		$fine = self::plain_hex( $global_styles['elements']['caption']['color']['text'] ?? '' );
		if ( '' !== $fine ) {
			$properties['--newspack-ui-color-text-fine'] = $fine;
		}

		return $properties;
	}

	/**
	 * The hover and pressed fills the publisher set themselves, as plain hex colors.
	 *
	 * Only the user origin counts: the theme's own states are mixes of its fill, and the palette computes those.
	 * A state equal to one a style variation sets was copied in when the variation was picked, so it is the theme's.
	 *
	 * @param array          $user_styles      The user origin's styles.
	 * @param array|\Closure $variation_styles The styles of the theme's style variations, or a closure returning them.
	 * @param array          $settings         Merged global settings.
	 *
	 * @return array<string,string> 'hover' and 'pressed' to a hex color, for the states that are set.
	 */
	private static function get_user_states( array $user_styles, array|\Closure $variation_styles, array $settings ): array {
		$selectors = [
			'hover'   => ':hover',
			'pressed' => ':active',
		];
		$found     = [];
		foreach ( $selectors as $state => $selector ) {
			$found[ $state ] = self::button_value( $user_styles, [ $selector, 'color', 'background' ] );
		}
		$found = array_filter( $found, 'is_string' );
		if ( [] === $found ) {
			return [];
		}

		$variations = $variation_styles instanceof \Closure ? $variation_styles() : $variation_styles;
		$theirs     = [];
		foreach ( is_array( $variations ) ? $variations : [] as $styles ) {
			foreach ( $selectors as $state => $selector ) {
				$value = is_array( $styles ) ? self::button_value( $styles, [ $selector, 'color', 'background' ] ) : null;
				if ( is_string( $value ) ) {
					$theirs[ $state ][] = $value;
				}
			}
		}
		$theirs_resolved = self::resolve_references( $theirs, $settings );

		$resolved = self::resolve_references( $found, $settings );
		$states   = [];
		foreach ( $resolved as $state => $value ) {
			$hex = self::plain_hex( $value );
			if ( '' === $hex ) {
				continue;
			}
			$same = array_merge( $theirs[ $state ] ?? [], $theirs_resolved[ $state ] ?? [] );
			if ( in_array( strtolower( trim( $found[ $state ] ) ), array_map( 'strtolower', array_map( 'trim', $same ) ), true ) || in_array( strtolower( $hex ), array_map( 'strtolower', array_map( 'trim', $theirs_resolved[ $state ] ?? [] ) ), true ) ) {
				continue;
			}
			$states[ $state ] = $hex;
		}

		return $states;
	}

	/**
	 * The user origin's styles.
	 *
	 * @return array
	 */
	private static function get_live_user_styles(): array {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return [];
		}
		$raw = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();

		return is_array( $raw['styles'] ?? null ) ? $raw['styles'] : [];
	}

	/**
	 * The styles of every style variation the theme ships.
	 *
	 * @return array[]
	 */
	private static function get_live_variation_styles(): array {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return [];
		}
		$styles = [];
		foreach ( \WP_Theme_JSON_Resolver::get_style_variations() as $variation ) {
			if ( is_array( $variation['styles'] ?? null ) ) {
				$styles[] = $variation['styles'];
			}
		}

		return $styles;
	}

	/**
	 * The styles the theme (and its parent) or the user set, as the merge of the two origins.
	 *
	 * @return array
	 */
	private static function get_authored_styles(): array {
		$theme = [];
		if ( class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			$raw   = \WP_Theme_JSON_Resolver::get_theme_data( [], [ 'with_supports' => false ] )->get_raw_data();
			$theme = is_array( $raw['styles'] ?? null ) ? $raw['styles'] : [];
		}

		return array_replace_recursive( $theme, self::get_live_user_styles() );
	}

	/**
	 * Keep the leaves an authored origin set.
	 *
	 * Core's default origin sets a button fill and an "inherit" button typography that no theme chose.
	 *
	 * @param array $values   The merged styles.
	 * @param array $authored The styles the theme or the user set.
	 *
	 * @return array
	 */
	private static function mask_to_authored( array $values, array $authored ): array {
		$kept = [];
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $authored ) ) {
				continue;
			}
			if ( is_array( $value ) && is_array( $authored[ $key ] ) ) {
				$inner = self::mask_to_authored( $value, $authored[ $key ] );
				if ( [] !== $inner ) {
					$kept[ $key ] = $inner;
				}
			} elseif ( ! is_array( $value ) ) {
				$kept[ $key ] = $value;
			}
		}

		return $kept;
	}

	/**
	 * The fonts, the type scale, the button typography and the fine print.
	 *
	 * @param array $global_styles Resolved global styles.
	 * @param array $settings      Merged global settings.
	 *
	 * @return array<string,string>
	 */
	private static function get_typography_properties( array $global_styles, array $settings ): array {
		$properties = [];
		$heading    = $global_styles['elements']['heading']['typography'] ?? [];
		$caption    = $global_styles['elements']['caption']['typography'] ?? [];
		$sizes      = self::flatten_presets( self::authored_presets( self::dig( $settings, [ 'typography', 'fontSizes' ] ) ) );

		self::put( $properties, '--newspack-ui-font-family-body', $global_styles['typography']['fontFamily'] ?? null );
		self::put( $properties, '--newspack-ui-font-family-heading', $heading['fontFamily'] ?? null );
		self::put( $properties, '--newspack-ui-heading-font-weight', $heading['fontWeight'] ?? null );

		$family = self::button_value( $global_styles, [ 'typography', 'fontFamily' ] );
		self::put( $properties, '--newspack-ui-font-family-control', $family );
		self::put( $properties, '--newspack-ui-button-font-family', $family );
		foreach ( [ [ 'blocks', 'core/button' ], [ 'elements', 'button' ] ] as $root ) {
			$size        = self::dig( $global_styles, array_merge( $root, [ 'typography', 'fontSize' ] ) );
			$line_height = self::dig( $global_styles, array_merge( $root, [ 'typography', 'lineHeight' ] ) );
			$has_size    = null !== $size && '' !== $size && [] !== $size;
			$has_line    = null !== $line_height && '' !== $line_height && [] !== $line_height;
			if ( ! $has_size && ! $has_line ) {
				continue;
			}
			$size        = self::sanitize_value( is_int( $size ) || is_float( $size ) ? (string) $size : $size, 'length' );
			$line_height = self::sanitize_value( is_int( $line_height ) || is_float( $line_height ) ? (string) $line_height : $line_height, 'line-height' );
			if ( '' !== $size && '' !== $line_height ) {
				$properties['--newspack-ui-font-size-button']   = $size;
				$properties['--newspack-ui-line-height-button'] = $line_height;
			}
			break;
		}
		self::put( $properties, '--newspack-ui-button-font-weight', self::button_value( $global_styles, [ 'typography', 'fontWeight' ] ) );
		self::put( $properties, '--newspack-ui-button-letter-spacing', self::button_value( $global_styles, [ 'typography', 'letterSpacing' ] ) );
		self::put( $properties, '--newspack-ui-button-text-transform', self::button_value( $global_styles, [ 'typography', 'textTransform' ] ) );
		foreach ( [ 'font-size-button', 'line-height-button', 'button-font-weight', 'button-letter-spacing', 'button-text-transform' ] as $name ) {
			$name = '--newspack-ui-' . $name;
			if ( isset( $properties[ $name ] ) && '' !== self::sanitize_value( $properties[ $name ], self::property_type( $name ) ) ) {
				$properties['--newspack-ui-button-white-space'] = 'normal';
				break;
			}
		}

		foreach ( self::SIZE_SCALE as $token => $slug ) {
			$pair = self::scale_pair( $sizes, $settings, $slug );
			if ( [] !== $pair ) {
				$properties[ '--newspack-ui-font-size-' . $token ]   = $pair[0];
				$properties[ '--newspack-ui-line-height-' . $token ] = $pair[1];
			}
		}

		$fine_size   = is_string( $caption['fontSize'] ?? null ) ? $caption['fontSize'] : '';
		$fine_height = self::unitless( $caption['lineHeight'] ?? null );
		if ( '' !== self::sanitize_value( $fine_size, 'length' ) ) {
			if ( '' !== $fine_height ) {
				$properties['--newspack-ui-font-size-fine']   = $fine_size;
				$properties['--newspack-ui-line-height-fine'] = $fine_height;
			}
		} else {
			$pair = self::scale_pair( $sizes, $settings, 'x-small' );
			if ( [] !== $pair ) {
				$properties['--newspack-ui-font-size-fine']   = $pair[0];
				$properties['--newspack-ui-line-height-fine'] = $pair[1];
			}
		}
		self::put( $properties, '--newspack-ui-font-family-fine', $caption['fontFamily'] ?? null );

		return $properties;
	}

	/**
	 * A font size preset's resolved size and the unitless line height the theme pairs with it.
	 *
	 * Printed as values rather than var() references: the modal checkout iframe does not load core's global styles.
	 *
	 * @param array  $sizes    Font size presets by slug.
	 * @param array  $settings Merged global settings.
	 * @param string $slug     The font size slug.
	 *
	 * @return string[] The size and the line height, or an empty array unless both are usable.
	 */
	private static function scale_pair( array $sizes, array $settings, string $slug ): array {
		$line_height = self::slug_line_height( $settings, $slug );
		if ( ! isset( $sizes[ $slug ] ) || '' === $line_height ) {
			return [];
		}
		$size = function_exists( 'wp_get_typography_font_size_value' ) ? \wp_get_typography_font_size_value( $sizes[ $slug ], $settings ) : ( $sizes[ $slug ]['size'] ?? null );
		$size = is_int( $size ) || is_float( $size ) ? (string) $size : $size;

		$size = self::sanitize_value( $size, 'length' );

		return '' === $size ? [] : [ $size, $line_height ];
	}

	/**
	 * A unitless line height, as a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string Empty when the value is not a unitless number.
	 */
	private static function unitless( mixed $value ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		return is_string( $value ) && 1 === preg_match( '/^\d+(?:\.\d+)?$/', trim( $value ) ) ? trim( $value ) : '';
	}

	/**
	 * The unitless line height the theme pairs with a font size slug.
	 *
	 * @param array  $settings Merged global settings.
	 * @param string $slug     The font size slug.
	 *
	 * @return string Empty when the theme sets none.
	 */
	private static function slug_line_height( array $settings, string $slug ): string {
		$heights = $settings['custom']['line-height'] ?? $settings['custom']['lineHeight'] ?? null;

		return is_array( $heights ) ? self::unitless( $heights[ $slug ] ?? null ) : '';
	}

	/**
	 * Presets of the theme and custom origins; core's defaults are not a theme's choice.
	 *
	 * @param mixed $presets Presets keyed by origin, or a flat list of presets.
	 *
	 * @return array
	 */
	private static function authored_presets( mixed $presets ): mixed {
		if ( ! is_array( $presets ) || isset( $presets[0] ) ) {
			return $presets;
		}

		return [
			'theme'  => $presets['theme'] ?? [],
			'custom' => $presets['custom'] ?? [],
		];
	}

	/**
	 * Make the literal button and caption font sizes fluid as core does.
	 *
	 * A size that points at a preset is left alone: the preset resolves through core's own fluid rules.
	 *
	 * @param array $styles   The masked styles.
	 * @param array $authored The styles the theme or the user set, unresolved.
	 * @param array $settings Merged global settings.
	 *
	 * @return array
	 */
	public static function apply_fluid_sizes( array $styles, array $authored, array $settings ): array {
		if ( ! function_exists( 'wp_get_typography_font_size_value' ) ) {
			return $styles;
		}
		foreach ( [
			[ 'blocks', 'core/button', 'typography', 'fontSize' ],
			[ 'elements', 'button', 'typography', 'fontSize' ],
			[ 'elements', 'caption', 'typography', 'fontSize' ],
		] as $path ) {
			$raw = self::dig( $authored, $path );
			if ( ! is_string( $raw ) || '' === trim( $raw ) || 0 === strpos( trim( $raw ), 'var' ) || null === self::dig( $styles, $path ) ) {
				continue;
			}
			$fluid = \wp_get_typography_font_size_value( [ 'size' => $raw ], $settings );
			if ( ! is_string( $fluid ) || '' === $fluid ) {
				continue;
			}
			$target = &$styles;
			foreach ( $path as $key ) {
				$target = &$target[ $key ];
			}
			$target = $fluid;
			unset( $target );
		}

		return $styles;
	}

	/**
	 * Set a property when the value is a non-empty string or number.
	 *
	 * @param array  $properties The properties, by reference.
	 * @param string $name       The property name.
	 * @param mixed  $value      The value.
	 */
	private static function put( array &$properties, string $name, mixed $value ): void {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$properties[ $name ] = $value;
		}
	}

	/**
	 * A button style: the Button block's wins over the button element's.
	 *
	 * @param array    $styles Global styles.
	 * @param string[] $path   Keys below the button.
	 *
	 * @return mixed Null when neither sets it.
	 */
	private static function button_value( array $styles, array $path ): mixed {
		foreach ( [ [ 'blocks', 'core/button' ], [ 'elements', 'button' ] ] as $root ) {
			$value = self::dig( $styles, array_merge( $root, $path ) );
			if ( null !== $value && '' !== $value && [] !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * A button radius as one plain length.
	 *
	 * The Site Editor writes a per-corner array once the corners are set apart; four identical corners count as one radius.
	 *
	 * @param mixed $radius The radius from the global styles.
	 *
	 * @return string The length, or an empty string when it is not a single plain length.
	 */
	private static function parse_radius( $radius ): string {
		if ( is_array( $radius ) ) {
			$corners = [];
			foreach ( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] as $corner ) {
				$corners[] = self::parse_radius( $radius[ $corner ] ?? null );
			}
			return '' !== $corners[0] && 1 === count( array_unique( $corners ) ) ? $corners[0] : '';
		}
		if ( 0 === $radius ) {
			return '0px';
		}
		if ( ! is_string( $radius ) ) {
			return '';
		}
		$radius = trim( $radius );
		if ( 1 !== preg_match( '/^(?:(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em)|0)$/D', $radius ) ) {
			return '';
		}

		return '0' === $radius ? '0px' : ( '.' === $radius[0] ? '0' . $radius : $radius );
	}

	/**
	 * The radius roles, all derived from the theme's button radius.
	 *
	 * @param array $global_styles Resolved global styles.
	 *
	 * @return array<string,string> Empty when the button radius is not a plain length.
	 */
	private static function get_radius_properties( array $global_styles ): array {
		$radius = self::parse_radius( $global_styles['blocks']['core/button']['border']['radius'] ?? null );
		if ( '' === $radius ) {
			$radius = self::parse_radius( $global_styles['elements']['button']['border']['radius'] ?? null );
		}
		if ( '' === $radius ) {
			return [];
		}

		return [
			'--newspack-ui-radius-button'  => $radius,
			'--newspack-ui-radius-control' => 'min(' . $radius . ', 12px)',
			'--newspack-ui-radius-badge'   => 'calc(' . $radius . ' * 0.5)',
			'--newspack-ui-radius-surface' => 'min(calc(' . $radius . ' * 1.5), 24px)',
			'--newspack-ui-radius-sheet'   => 'min(calc(' . $radius . ' * 2), 24px)',
		];
	}

	/**
	 * One border property of a field element, as a single value.
	 *
	 * Four identical per-side values count as one; differing sides give nothing.
	 *
	 * @param array  $border   The element's border.
	 * @param string $property 'width', 'color' or 'style'.
	 *
	 * @return mixed Null when the property is not set or the sides differ.
	 */
	private static function border_value( array $border, string $property ): mixed {
		$values = [];
		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			if ( is_array( $border[ $side ] ?? null ) && isset( $border[ $side ][ $property ] ) ) {
				$values[] = $border[ $side ][ $property ];
			}
		}
		if ( [] === $values ) {
			return $border[ $property ] ?? null;
		}

		return 4 === count( $values ) && 1 === count( array_unique( array_map( 'strval', $values ) ) ) ? $values[0] : null;
	}

	/**
	 * A field border width as one plain length.
	 *
	 * @param mixed $width The width from the global styles.
	 *
	 * @return string Empty when it is not a non-negative px, rem or em length, zero, or a var() reference.
	 */
	private static function parse_border_width( $width ): string {
		if ( 0 === $width ) {
			return '0px';
		}
		if ( ! is_string( $width ) ) {
			return '';
		}
		$width = trim( $width );
		if ( '0' === $width ) {
			return '0px';
		}
		if ( 1 === preg_match( '/^(?:(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em))$/D', $width ) ) {
			return '.' === $width[0] ? '0' . $width : $width;
		}

		return 0 === strpos( $width, 'var(' ) ? self::sanitize_value( $width, 'length' ) : '';
	}

	/**
	 * The field tokens, from the text input and select element styles.
	 *
	 * Background and text color print as a pair of plain hex colors from one element, or not at all, with the placeholder, toggle and caret tones solved against that pair.
	 * The border color is the publisher's choice and is not checked.
	 *
	 * @param array $global_styles Resolved global styles.
	 *
	 * @return array<string,string>
	 */
	private static function get_field_properties( array $global_styles ): array {
		$properties = [];
		$elements   = [ 'textInput', 'select' ];

		foreach ( $elements as $element ) {
			$radius = self::parse_radius( $global_styles['elements'][ $element ]['border']['radius'] ?? null );
			if ( '' !== $radius ) {
				$properties['--newspack-ui-field-radius'] = $radius;
				break;
			}
		}

		foreach ( $elements as $element ) {
			$border = $global_styles['elements'][ $element ]['border'] ?? null;
			if ( ! is_array( $border ) ) {
				continue;
			}
			$style = self::border_value( $border, 'style' );
			$width = in_array( $style, [ 'none', 'hidden' ], true ) ? '0px' : self::parse_border_width( self::border_value( $border, 'width' ) );
			if ( '' !== $width ) {
				$properties['--newspack-ui-field-border-width'] = $width;
				break;
			}
		}

		foreach ( $elements as $element ) {
			$border = $global_styles['elements'][ $element ]['border'] ?? null;
			$color  = is_array( $border ) ? self::border_value( $border, 'color' ) : null;
			if ( '' !== self::sanitize_value( $color, 'color' ) ) {
				$properties['--newspack-ui-field-border-color'] = $color;
				break;
			}
		}

		foreach ( $elements as $element ) {
			$background = $global_styles['elements'][ $element ]['color']['background'] ?? null;
			$text       = $global_styles['elements'][ $element ]['color']['text'] ?? null;
			if ( is_string( $background ) && is_string( $text ) && '' !== self::expand_hex( $background ) && '' !== self::expand_hex( $text ) ) {
				$properties['--newspack-ui-field-bg']  = $background;
				$properties['--newspack-ui-field-ink'] = $text;
				$properties                           += self::get_field_tones( $background, $text );
				break;
			}
		}

		foreach ( $elements as $element ) {
			$family = $global_styles['elements'][ $element ]['typography']['fontFamily'] ?? null;
			if ( '' !== self::sanitize_value( $family, 'font-family' ) ) {
				$properties['--newspack-ui-field-font-family'] = $family;
				break;
			}
		}

		return $properties;
	}

	/**
	 * The select caret and the placeholder and toggle tones for a field background and text color.
	 *
	 * @param string $background The field background.
	 * @param string $text       The field text color.
	 *
	 * @return array<string,string> Empty unless both are plain hex colors.
	 */
	private static function get_field_tones( string $background, string $text ): array {
		$background = self::expand_hex( $background );
		$text       = self::expand_hex( $text );
		if ( '' === $background || '' === $text ) {
			return [];
		}
		$tones = Newspack_UI_Palette::text_tones( $text, $background );
		$dark  = self::prefers_dark_caret( $background );

		return [
			'--newspack-ui-field-caret'      => 'var(--newspack-ui-field-caret-' . ( $dark ? 'dark' : 'light' ) . ')',
			'--newspack-ui-field-ink-subtle' => $tones['subtle'],
			'--newspack-ui-field-ink-muted'  => $tones['muted'],
		];
	}

	/**
	 * Whether the dark caret reads at least as well as the light one on a background.
	 *
	 * @param string $background Lowercase '#rrggbb'.
	 *
	 * @return bool
	 */
	private static function prefers_dark_caret( string $background ): bool {
		return Newspack_UI_Palette::lc( '#1e1e1e', $background ) >= Newspack_UI_Palette::lc( '#ffffff', $background );
	}

	/**
	 * A plain hex color as lowercase #rrggbb.
	 *
	 * @param string $value The value.
	 *
	 * @return string Empty when it is not a plain 3 or 6 digit hex color.
	 */
	private static function expand_hex( string $value ): string {
		$hex = strtolower( self::plain_hex( $value ) );
		if ( '' === $hex ) {
			return '';
		}

		return 4 === strlen( $hex ) ? '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3] : $hex;
	}

	/**
	 * The ':root' rule for the Theme style.
	 *
	 * @param array          $global_styles Resolved global styles.
	 * @param array|null     $settings      Merged global settings, or null for the site's own.
	 * @param array          $user_styles      The user origin's styles.
	 * @param array|\Closure $variation_styles The styles of the theme's style variations, or a closure returning them.
	 *
	 * @return string The rule, or an empty string when there is nothing to print.
	 */
	public static function get_css( array $global_styles, ?array $settings = null, array $user_styles = [], array|\Closure $variation_styles = [] ): string {
		$solved = self::solve( $global_styles, $settings ?? self::get_settings(), $user_styles, $variation_styles );

		return self::render( $solved['properties'], $solved['high_contrast'] );
	}

	/**
	 * The ':root' rule for a set of properties, then the same for the properties that apply under `prefers-contrast: more`.
	 *
	 * @param array<string,string> $properties    The properties.
	 * @param array<string,string> $high_contrast The properties for `prefers-contrast: more`.
	 *
	 * @return string The rules, or an empty string when there is nothing to print.
	 */
	private static function render( array $properties, array $high_contrast = [] ): string {
		$base = self::declarations( $properties );
		$more = self::declarations( $high_contrast );

		return ( '' === $base ? '' : ':root{' . $base . '}' ) . ( '' === $more ? '' : '@media (prefers-contrast: more){:root{' . $more . '}}' );
	}

	/**
	 * Declarations for the properties whose values pass the allow-list of their type.
	 *
	 * @param array<string,string> $properties The properties.
	 *
	 * @return string
	 */
	private static function declarations( array $properties ): string {
		$declarations = '';
		foreach ( $properties as $name => $value ) {
			$type  = self::property_type( (string) $name );
			$value = '' === $type ? '' : self::sanitize_value( $value, $type );
			if ( '' === $value ) {
				continue;
			}
			$declarations .= $name . ':' . $value . ';';
		}

		return $declarations;
	}

	/**
	 * Replace "var:preset|<type>|<slug>" and "var:custom|<a>|<b>" strings with the values they point at.
	 *
	 * A reference to an unknown type or slug is left as it is, so the validators drop it.
	 *
	 * @param array $styles   The global styles.
	 * @param array $settings Merged global settings.
	 *
	 * @return array
	 */
	public static function resolve_references( array $styles, array $settings ): array {
		$presets = [];
		foreach ( self::PRESET_TYPES as $type => $definition ) {
			$presets[ $type ] = self::flatten_presets( self::dig( $settings, $definition[0] ) );
		}

		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$next = self::walk( $styles, $presets, $settings );
			if ( $next === $styles ) {
				break;
			}
			$styles = $next;
		}

		return $styles;
	}

	/**
	 * The type a custom property's value is checked as, from its name.
	 *
	 * @param string $name The property name.
	 *
	 * @return string The type, or an empty string for a property no group owns.
	 */
	public static function property_type( string $name ): string {
		foreach ( self::PROPERTY_TYPES as $prefix => $type ) {
			if ( 0 === strpos( $name, $prefix ) && 1 === preg_match( '/^--newspack-ui-[a-z0-9-]+$/', $name ) ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Check a value against the allow-list for its property type.
	 *
	 * @param mixed  $value The value.
	 * @param string $type  One of 'color', 'reference', 'font-family', 'length', 'line-height', 'weight', 'letter-spacing', 'text-transform', 'white-space', 'text-decoration'.
	 *
	 * @return string The trimmed value, or an empty string when it is not allowed.
	 */
	public static function sanitize_value( $value, string $type ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		if ( ! self::is_printable( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value || 1 === preg_match( '/^(?:inherit|initial|unset|revert|revert-layer)$/i', $value ) ) {
			return '';
		}

		if ( 'font-family' !== $type && 1 === preg_match( '/["\']/', $value ) ) {
			return '';
		}

		switch ( $type ) {
			case 'color':
				return 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) || self::is_var( $value, 'color' ) ? $value : '';
			case 'reference':
				return self::is_var( $value, 'reference' ) ? $value : '';
			case 'font-family':
				return self::is_var( $value, 'font-family' ) || 1 === preg_match( self::FONT_FAMILY_PATTERN, $value ) ? $value : '';
			case 'length':
				return self::is_length( $value ) || self::is_var( $value, 'length' ) ? $value : '';
			case 'line-height':
				return 1 === preg_match( '/^\d+(\.\d+)?$/', $value ) || self::is_length( $value ) || self::is_var( $value, 'line-height' ) ? $value : '';
			case 'weight':
				return 1 === preg_match( '/^[1-9]00$|^(normal|bold)$/', $value ) ? $value : '';
			case 'letter-spacing':
				return '0' === $value || self::is_length( $value ) ? $value : '';
			case 'text-transform':
				return 1 === preg_match( '/^(?:none|capitalize|uppercase|lowercase)$/', $value ) ? $value : '';
			case 'white-space':
				return 1 === preg_match( '/^(?:normal|nowrap|pre|pre-wrap|pre-line|break-spaces)$/', $value ) ? $value : '';
			case 'text-decoration':
				return 1 === preg_match( '/^(?:none|(?:underline|overline|line-through)(?: (?:underline|overline|line-through))*)$/', $value ) ? $value : '';
		}

		return '';
	}

	/**
	 * The merged global settings.
	 *
	 * @return array
	 */
	private static function get_settings(): array {
		$settings = \wp_get_global_settings();

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * A plain hex color, or an empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function plain_hex( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $value ) ) ? trim( $value ) : '';
	}

	/**
	 * Follow a path of keys into an array.
	 *
	 * @param array    $data The array.
	 * @param string[] $path The keys.
	 *
	 * @return mixed Null when the path does not exist.
	 */
	private static function dig( array $data, array $path ): mixed {
		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$data = $data[ $key ];
		}

		return $data;
	}

	/**
	 * Flatten the origin-keyed presets of one type to slug => preset, custom over theme over default.
	 *
	 * @param mixed $presets Presets keyed by origin, or a flat list of presets.
	 *
	 * @return array<string,array>
	 */
	private static function flatten_presets( $presets ): array {
		if ( ! is_array( $presets ) ) {
			return [];
		}
		$origins = isset( $presets[0] ) ? [ $presets ] : [ $presets['default'] ?? [], $presets['theme'] ?? [], $presets['custom'] ?? [] ];

		$flat = [];
		foreach ( $origins as $origin ) {
			foreach ( is_array( $origin ) ? $origin : [] as $preset ) {
				if ( is_array( $preset ) && is_string( $preset['slug'] ?? null ) ) {
					$flat[ $preset['slug'] ] = $preset;
				}
			}
		}

		return $flat;
	}

	/**
	 * Resolve the references in a nested array.
	 *
	 * @param array $node     The node.
	 * @param array $presets  Flattened presets by type.
	 * @param array $settings Merged global settings.
	 *
	 * @return array
	 */
	private static function walk( array $node, array $presets, array $settings ): array {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = self::walk( $value, $presets, $settings );
			} elseif ( is_string( $value ) ) {
				$node[ $key ] = self::resolve_string( $value, $presets, $settings );
			}
		}

		return $node;
	}

	/**
	 * Resolve one string.
	 *
	 * @param string $value    The value.
	 * @param array  $presets  Flattened presets by type.
	 * @param array  $settings Merged global settings.
	 *
	 * @return string
	 */
	private static function resolve_string( string $value, array $presets, array $settings ): string {
		$original = $value;
		if ( 1 === preg_match( '/^var\(\s*--wp--preset--([a-z-]+)--([a-z0-9-]+)\s*(?:,[^()]*)?\)$/i', trim( $value ), $match ) ) {
			foreach ( array_keys( self::PRESET_TYPES ) as $type ) {
				if ( 0 === strpos( $match[1] . '--' . $match[2], $type . '--' ) ) {
					$value = 'var:preset|' . $type . '|' . substr( $match[1] . '--' . $match[2], strlen( $type ) + 2 );
					break;
				}
			}
		}
		$parts = explode( '|', $value );
		if ( 3 === count( $parts ) && 'var:preset' === $parts[0] && isset( self::PRESET_TYPES[ $parts[1] ], $presets[ $parts[1] ][ $parts[2] ] ) ) {
			$preset = $presets[ $parts[1] ][ $parts[2] ];
			if ( 'font-size' === $parts[1] && function_exists( 'wp_get_typography_font_size_value' ) ) {
				$size = \wp_get_typography_font_size_value( $preset, $settings );
				return is_string( $size ) || is_int( $size ) || is_float( $size ) ? (string) $size : $original;
			}
			$leaf = $preset[ self::PRESET_TYPES[ $parts[1] ][1] ] ?? null;
			return is_string( $leaf ) ? $leaf : $original;
		}
		if ( count( $parts ) >= 2 && 'var:custom' === $parts[0] ) {
			$node = $settings['custom'] ?? null;
			foreach ( array_slice( $parts, 1 ) as $segment ) {
				$camel = lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', $segment ) ) ) );
				if ( ! is_array( $node ) ) {
					return $original;
				}
				$node = array_key_exists( $segment, $node ) ? $node[ $segment ] : ( $node[ $camel ] ?? null );
			}
			if ( is_string( $node ) || is_int( $node ) || is_float( $node ) ) {
				return (string) $node;
			}
		}

		return $original;
	}

	/**
	 * Whether a value has none of the characters that could end a declaration or a rule.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function is_printable( $value ): bool {
		return is_string( $value )
			&& 1 !== preg_match( '/[;{}<>\\\\\x00-\x1F\x7F]|\/\*/', $value )
			&& self::balanced( $value );
	}

	/**
	 * Whether the parentheses of a value are balanced.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function balanced( string $value ): bool {
		$depth = 0;
		foreach ( str_split( $value ) as $char ) {
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char && --$depth < 0 ) {
				return false;
			}
		}

		return 0 === $depth;
	}

	/**
	 * Whether a value is a single var() reference whose fallback, if any, is itself a valid value of the type.
	 *
	 * @param string $value The value.
	 * @param string $type  The value type.
	 *
	 * @return bool
	 */
	private static function is_var( string $value, string $type ): bool {
		if ( 0 !== strpos( $value, 'var(' ) || ')' !== substr( $value, -1 ) ) {
			return false;
		}
		$depth = 0;
		$last  = strlen( $value ) - 1;
		for ( $i = 3; $i <= $last; $i++ ) {
			if ( '(' === $value[ $i ] ) {
				++$depth;
			} elseif ( ')' === $value[ $i ] && 0 === --$depth && $i < $last ) {
				return false;
			}
		}
		if ( 0 !== $depth || 1 !== preg_match( '/^\s*--[A-Za-z0-9_-]+\s*(?:,(.*))?$/s', substr( $value, 4, -1 ), $match ) ) {
			return false;
		}

		return ! isset( $match[1] ) || '' === trim( $match[1] ) || '' !== self::sanitize_value( $match[1], $type );
	}

	/**
	 * Whether a value is a length, or a clamp(), min(), max() or calc() of lengths.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function is_length( string $value ): bool {
		$number = '-?\d+(?:\.\d+)?';
		if ( 1 === preg_match( '/^' . $number . '(?:px|rem|em|%)$/', $value ) ) {
			return true;
		}
		if ( 1 !== preg_match( '/^(?:clamp|min|max|calc)\(/', $value ) || ! self::balanced( $value ) ) {
			return false;
		}
		$rest = preg_replace( '/(?:clamp|min|max|calc)(?=\()/', '', $value );
		$rest = preg_replace( '/\d+(?:\.\d+)?(?:px|rem|em|vw|vh|%)?/', '', (string) $rest );

		return 1 === preg_match( '/^[\s+\-*\/,.()]*$/', (string) $rest );
	}
}

Newspack_UI_Theme_Style::init();
