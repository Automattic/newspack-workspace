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
		'color'       => [ [ 'color', 'palette' ], 'color' ],
		'font-family' => [ [ 'typography', 'fontFamilies' ], 'fontFamily' ],
		'font-size'   => [ [ 'typography', 'fontSizes' ], 'size' ],
		'gradient'    => [ [ 'color', 'gradients' ], 'gradient' ],
		'spacing'     => [ [ 'spacing', 'spacingSizes' ], 'size' ],
	];

	/**
	 * Custom property prefix to the value type its values are checked as. A property under no prefix is never printed.
	 */
	const PROPERTY_TYPES = [
		'--newspack-ui-color-' => 'color',
	];

	/**
	 * The global styles subtrees the adapter reads, as key paths.
	 */
	const READ_PATHS = [
		[ 'color' ],
		[ 'elements', 'button' ],
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
	private static $global_styles = null;

	/**
	 * The ':root' rule for this request.
	 *
	 * @var string|null
	 */
	private static $css = null;

	/**
	 * Register the hooks that drop the memoized global styles.
	 */
	public static function init() {
		\add_action( 'save_post_wp_global_styles', [ __CLASS__, 'reset_cache' ] );
		\add_action( 'switch_theme', [ __CLASS__, 'reset_cache' ] );
	}

	/**
	 * Drop the memoized global styles.
	 */
	public static function reset_cache() {
		self::$global_styles = null;
		self::$css           = null;
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
			self::$css = self::get_css( self::get_global_styles() );
		}

		return self::$css;
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
		$button   = $global_styles['elements']['button']['color'] ?? [];

		$brand = self::plain_hex( $button['background'] ?? '' );
		if ( '' === $brand ) {
			$accent = self::flatten_presets( self::dig( $settings, [ 'color', 'palette' ] ) )['accent']['color'] ?? '';
			$brand  = self::plain_hex( $accent );
		}

		return [
			'base'       => is_string( $global_styles['color']['background'] ?? null ) ? $global_styles['color']['background'] : '',
			'contrast'   => is_string( $global_styles['color']['text'] ?? null ) ? $global_styles['color']['text'] : '',
			'brand'      => $brand,
			'brand_text' => self::plain_hex( $button['text'] ?? '' ),
		];
	}

	/**
	 * Every custom property the Theme style sets, as '--newspack-ui-*' => value.
	 *
	 * @param array      $global_styles Resolved global styles.
	 * @param array|null $settings      Merged global settings, or null for the site's own.
	 *
	 * @return array<string,string>
	 */
	public static function get_properties( array $global_styles, ?array $settings = null ): array {
		return Newspack_UI_Palette::get_properties( self::get_palette( $global_styles, $settings ) );
	}

	/**
	 * The ':root' rule for the Theme style.
	 *
	 * @param array      $global_styles Resolved global styles.
	 * @param array|null $settings      Merged global settings, or null for the site's own.
	 *
	 * @return string The rule, or an empty string when there is nothing to print.
	 */
	public static function get_css( array $global_styles, ?array $settings = null ): string {
		$declarations = '';
		foreach ( self::get_properties( $global_styles, $settings ) as $name => $value ) {
			$type  = self::property_type( (string) $name );
			$value = '' === $type ? '' : self::sanitize_value( $value, $type );
			if ( '' === $value ) {
				continue;
			}
			$declarations .= $name . ':' . $value . ';';
		}

		return '' === $declarations ? '' : ':root{' . $declarations . '}';
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
	 * @param string $type  One of 'color', 'font-family', 'length', 'line-height', 'weight', 'letter-spacing'.
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
		if ( '' === $value ) {
			return '';
		}

		if ( 'font-family' !== $type && 1 === preg_match( '/["\']/', $value ) ) {
			return '';
		}

		switch ( $type ) {
			case 'color':
				return 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) || self::is_var( $value, 'color' ) ? $value : '';
			case 'font-family':
				return self::is_var( $value, 'font-family' ) || 1 === preg_match( self::FONT_FAMILY_PATTERN, $value ) ? $value : '';
			case 'length':
				return self::is_length( $value ) ? $value : '';
			case 'line-height':
				return 1 === preg_match( '/^\d+(\.\d+)?$/', $value ) || self::is_length( $value ) ? $value : '';
			case 'weight':
				return 1 === preg_match( '/^[1-9]00$|^(normal|bold)$/', $value ) ? $value : '';
			case 'letter-spacing':
				return '0' === $value || self::is_length( $value ) ? $value : '';
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
	private static function dig( array $data, array $path ) {
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
		$parts = explode( '|', $value );
		if ( 3 === count( $parts ) && 'var:preset' === $parts[0] && isset( self::PRESET_TYPES[ $parts[1] ], $presets[ $parts[1] ][ $parts[2] ] ) ) {
			$preset = $presets[ $parts[1] ][ $parts[2] ];
			if ( 'font-size' === $parts[1] && function_exists( 'wp_get_typography_font_size_value' ) ) {
				$size = \wp_get_typography_font_size_value( $preset, $settings );
				return is_string( $size ) || is_int( $size ) || is_float( $size ) ? (string) $size : $value;
			}
			$leaf = $preset[ self::PRESET_TYPES[ $parts[1] ][1] ] ?? null;
			return is_string( $leaf ) ? $leaf : $value;
		}
		if ( count( $parts ) >= 2 && 'var:custom' === $parts[0] ) {
			$node = $settings['custom'] ?? null;
			foreach ( array_slice( $parts, 1 ) as $segment ) {
				$camel = lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', $segment ) ) ) );
				if ( ! is_array( $node ) ) {
					return $value;
				}
				$node = array_key_exists( $segment, $node ) ? $node[ $segment ] : ( $node[ $camel ] ?? null );
			}
			if ( is_string( $node ) || is_int( $node ) || is_float( $node ) ) {
				return (string) $node;
			}
		}

		return $value;
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
