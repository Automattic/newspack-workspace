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
	];

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
		$styles = \wp_get_global_styles( [], [ 'transforms' => [ 'resolve-variables' ] ] );

		return self::resolve_references( is_array( $styles ) ? $styles : [], self::get_settings() );
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
			$accent = self::flatten_presets( self::dig( $settings, [ 'color', 'palette' ] ) )['accent'] ?? '';
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
			if ( 1 !== preg_match( '/^--newspack-ui-[a-z0-9-]+$/', (string) $name ) || ! self::is_printable( $value ) ) {
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
			$presets[ $type ] = self::flatten_presets( self::dig( $settings, $definition[0] ), $definition[1] );
		}

		return self::walk( $styles, $presets, $settings );
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
		$value = trim( $value );
		if ( '' === $value || ! self::is_printable( $value ) ) {
			return '';
		}

		switch ( $type ) {
			case 'color':
				return 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) || self::is_var( $value ) ? $value : '';
			case 'font-family':
				return self::is_var( $value ) || 1 === preg_match( '/^[A-Za-z0-9 ,"\'._-]+$/', $value ) ? $value : '';
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
	 * Flatten the origin-keyed presets of one type to slug => value, custom over theme over default.
	 *
	 * @param mixed  $presets Presets keyed by origin, or a flat list of presets.
	 * @param string $key     The key holding the value; 'color' when omitted.
	 *
	 * @return array<string,string>
	 */
	private static function flatten_presets( $presets, string $key = 'color' ): array {
		if ( ! is_array( $presets ) ) {
			return [];
		}
		$origins = isset( $presets[0] ) ? [ $presets ] : [ $presets['default'] ?? [], $presets['theme'] ?? [], $presets['custom'] ?? [] ];

		$flat = [];
		foreach ( $origins as $origin ) {
			foreach ( is_array( $origin ) ? $origin : [] as $preset ) {
				if ( is_array( $preset ) && is_string( $preset['slug'] ?? null ) && is_string( $preset[ $key ] ?? null ) ) {
					$flat[ $preset['slug'] ] = $preset[ $key ];
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
		if ( 3 === count( $parts ) && 'var:preset' === $parts[0] && isset( $presets[ $parts[1] ][ $parts[2] ] ) ) {
			return $presets[ $parts[1] ][ $parts[2] ];
		}
		if ( 3 === count( $parts ) && 'var:custom' === $parts[0] ) {
			$leaf = self::dig( $settings, [ 'custom', $parts[1], $parts[2] ] );
			if ( is_string( $leaf ) || is_int( $leaf ) || is_float( $leaf ) ) {
				return (string) $leaf;
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
		return is_string( $value ) && 1 !== preg_match( '/[;{}<>\\\\]|\/\*/', $value ) && self::balanced( $value );
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
	 * Whether a value is a well-formed var() reference.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function is_var( string $value ): bool {
		return 1 === preg_match( '/^var\(\s*--[A-Za-z0-9_-]+\s*(,[^;{}]*)?\)$/', $value ) && self::balanced( $value );
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
