<?php
/**
 * Contextual Prompt block default styles.
 *
 * Publisher-set defaults for the Contextual Prompt block, edited in the
 * Contextual Prompts wizard on classic themes (block themes use Global Styles
 * directly). Stored as a block-supports-shaped object, rendered to CSS by the
 * style engine, and delivered at :root :where() specificity AFTER the block's
 * theme.json default design so it overrides the default while any per-block
 * style still wins. Publisher-written custom CSS rides along in an option of its
 * own, scoped to the block by core's custom-css processing.
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contextual Prompt styles class.
 */
final class Newspack_Popups_Contextual_Prompt_Styles {
	const OPTION_NAME = 'newspack_popups_contextual_prompt_styles';

	/**
	 * Free-form CSS has its own option: it cannot travel through the style
	 * engine's block-supports shape, and mixing the two would weaken a
	 * deliberately strict allowlist.
	 */
	const CUSTOM_CSS_OPTION_NAME = 'newspack_popups_contextual_prompt_custom_css';

	/**
	 * The block class both the style engine output and the custom CSS are scoped to.
	 */
	const SELECTOR = '.wp-block-newspack-popups-contextual-prompt';

	/**
	 * Leaf values: CSS-safe fragments only. Permits hex/named colors, units,
	 * var:preset|…|… refs and var() lookups; rejects anything that could close
	 * a declaration or rule, and function shapes (`url(…)`, `expression(…)`)
	 * that could smuggle a scheme — anywhere in the value, not just at its
	 * start, so nesting one inside another set of parentheses does not hide it.
	 */
	const VALUE_PATTERN = '/^(?!.*(?i:url|expression)\s*\()[a-zA-Z0-9 #%().,\/|:_-]+\z/';

	/**
	 * Object-schema nodes that also accept a string leaf, as dot paths into the
	 * schema. `border.radius` is either a shorthand or a per-corner object;
	 * everywhere else a string would emit a shorthand declaration nobody
	 * allowlisted (`spacing.padding: '10px'` becoming `padding`), so it is
	 * dropped.
	 */
	const SHORTHAND_PATHS = [ 'border.radius' ];

	/**
	 * Color shapes the wizard can read for its contrast check: a hex value or a
	 * preset reference.
	 */
	const WIZARD_COLOR_PATTERN = '/^(?:#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|var:preset\|color\|[a-zA-Z0-9_-]+)\z/';

	/**
	 * An `rgb()`/`rgba()` color with integer channels, the one other shape
	 * theme.json data hands back for a color. Alpha is matched only to be dropped.
	 */
	const RGB_COLOR_PATTERN = '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,[^)]*)?\)\z/i';

	/**
	 * The CSS initial text color, the last resort when the site sets no global
	 * text color at all.
	 */
	const INITIAL_TEXT_COLOR = '#000000';

	/**
	 * The classic Newspack theme's template. Its style variations are child
	 * themes, so they all report this one.
	 */
	const NEWSPACK_THEME_TEMPLATE = 'newspack-theme';

	/**
	 * The body text color the classic Newspack theme renders with. It lives in
	 * the theme's stylesheet, which the global styles data cannot see.
	 */
	const NEWSPACK_THEME_TEXT_COLOR = '#111111';

	/**
	 * Register hooks. Classic themes only: on block themes Global Styles owns
	 * the block's styles and this class must stay inert.
	 */
	public static function init() {
		if ( wp_is_block_theme() ) {
			return;
		}
		// The rollout flag gates the call to this method; the admin opt-in is an
		// option, so it is read here — the same point the render-time block strip
		// reads it. With the opt-in withdrawn no prompt markup renders, so there is
		// nothing for this CSS to style.
		if ( ! Newspack_Popups_Settings::is_ai_copy_assistant_enabled() ) {
			return;
		}
		// Same hook and priority as core's wp_enqueue_global_styles on classic
		// themes, registered later so it runs after: the inline CSS then lands
		// behind the block's default design in the same handle.
		add_action( 'wp_footer', [ __CLASS__, 'enqueue_front_end_styles' ], 1 );
		add_filter( 'block_editor_settings_all', [ __CLASS__, 'filter_block_editor_settings' ] );
	}

	/**
	 * The saved style overrides.
	 *
	 * @return array
	 */
	public static function get_styles() {
		$styles = get_option( self::OPTION_NAME, [] );
		return is_array( $styles ) ? $styles : [];
	}

	/**
	 * Sanitize style overrides, distinguishing an intentional reset from a
	 * malformed payload: a non-empty payload that sanitizes to nothing must not
	 * pass as a reset, or saving it would silently erase valid saved styles.
	 *
	 * @param array $styles Block-supports-shaped style object.
	 * @return array|WP_Error Sanitized style object, or WP_Error when a non-empty
	 *                        payload holds nothing valid.
	 */
	public static function validate( $styles ) {
		$styles    = (array) $styles;
		$sanitized = self::sanitize( $styles );
		if ( empty( $sanitized ) && ! empty( $styles ) ) {
			return new WP_Error(
				'newspack_popups_invalid_styles',
				esc_html__( 'The style overrides contain no valid styles.', 'newspack-popups' ),
				[ 'status' => 400 ]
			);
		}
		return $sanitized;
	}

	/**
	 * Sanitize and persist style overrides. An explicitly empty payload removes
	 * the option; a non-empty payload sanitizing to nothing is rejected.
	 *
	 * @param array $styles Block-supports-shaped style object.
	 * @return true|WP_Error True on save, WP_Error for a malformed payload.
	 */
	public static function save_styles( $styles ) {
		$sanitized = self::validate( $styles );
		if ( is_wp_error( $sanitized ) ) {
			return $sanitized;
		}
		if ( empty( $sanitized ) ) {
			delete_option( self::OPTION_NAME );
			return true;
		}
		update_option( self::OPTION_NAME, $sanitized );
		return true;
	}

	/**
	 * Allowlist filter for the stored shape. Anything not explicitly allowed is
	 * dropped; any leaf failing the value pattern drops its whole branch.
	 *
	 * @param array $styles Raw style object.
	 * @return array Sanitized style object.
	 */
	public static function sanitize( $styles ) {
		$side_schema = [
			'color' => true,
			'width' => true,
			'style' => true,
		];
		$schema      = [
			'color'      => [
				'background' => true,
				'text'       => true,
			],
			'typography' => [ 'fontSize' => true ],
			'spacing'    => [
				'padding' => [
					'top'    => true,
					'right'  => true,
					'bottom' => true,
					'left'   => true,
				],
			],
			'border'     => array_merge(
				$side_schema,
				[
					'radius' => [
						'topLeft'     => true,
						'topRight'    => true,
						'bottomLeft'  => true,
						'bottomRight' => true,
					],
					'top'    => $side_schema,
					'right'  => $side_schema,
					'bottom' => $side_schema,
					'left'   => $side_schema,
				]
			),
		];

		return self::sanitize_node( $styles, $schema );
	}

	/**
	 * Recursively apply a schema node: keys must exist in the schema; leaves
	 * must be pattern-safe strings. A schema of `true` accepts a string leaf; a
	 * schema array accepts a matching sub-object, plus a string leaf only at the
	 * paths in SHORTHAND_PATHS.
	 *
	 * @param mixed  $node   Incoming value.
	 * @param mixed  $schema Schema node.
	 * @param string $path   Dot path to $node, for the shorthand allowlist.
	 * @return array Sanitized node (possibly empty).
	 */
	private static function sanitize_node( $node, $schema, $path = '' ) {
		$clean = [];
		if ( ! is_array( $node ) ) {
			return $clean;
		}
		foreach ( $node as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			$key_path = '' === $path ? (string) $key : $path . '.' . $key;
			if ( is_string( $value ) ) {
				$accepts_string = true === $schema[ $key ] || in_array( $key_path, self::SHORTHAND_PATHS, true );
				if ( $accepts_string && preg_match( self::VALUE_PATTERN, $value ) ) {
					$clean[ $key ] = $value;
				}
				continue;
			}
			if ( is_array( $schema[ $key ] ) && is_array( $value ) ) {
				$sub = self::sanitize_node( $value, $schema[ $key ], $key_path );
				if ( ! empty( $sub ) ) {
					$clean[ $key ] = $sub;
				}
			}
		}
		return $clean;
	}

	/**
	 * Whether the current user may write custom CSS. `edit_css` is core's own gate
	 * for the same feature, mapped alongside `unfiltered_html`.
	 *
	 * @return bool
	 */
	public static function can_edit_css() {
		return current_user_can( 'edit_css' );
	}

	/**
	 * The saved custom CSS.
	 *
	 * @return string
	 */
	public static function get_custom_css() {
		$css = get_option( self::CUSTOM_CSS_OPTION_NAME, '' );
		return is_string( $css ) ? $css : '';
	}

	/**
	 * Validate custom CSS: it must not close the STYLE element it renders in, and
	 * its braces, comments and strings must all balance. An unclosed one would
	 * break every style appended to the shared global-styles handle after it.
	 *
	 * @param string $css CSS to validate.
	 * @return true|WP_Error True when the CSS is safe to store, WP_Error otherwise.
	 */
	public static function validate_custom_css( $css ) {
		$length = strlen( $css );
		for (
			$at = strcspn( $css, '<' );
			$at < $length;
			$at += strcspn( $css, '<', ++$at )
		) {
			$remaining_strlen = $length - $at;
			// Styles are concatenated, so a trailing prefix of a closing tag is
			// rejected too: the next one could complete it.
			$possible_style_close_tag = 0 === substr_compare( $css, '</style', $at, min( 7, $remaining_strlen ), true );
			if ( ! $possible_style_close_tag ) {
				continue;
			}
			if ( 8 > $remaining_strlen ) {
				return new WP_Error(
					'newspack_popups_invalid_custom_css',
					sprintf(
						/* translators: %s: the end of the submitted CSS. */
						__( 'The CSS must not end in "%s".', 'newspack-popups' ),
						substr( $css, $at )
					),
					[ 'status' => 400 ]
				);
			}
			if ( 1 === strspn( $css, " \t\f\r\n/>", $at + 7, 1 ) ) {
				return new WP_Error(
					'newspack_popups_invalid_custom_css',
					sprintf(
						/* translators: %s: the offending part of the submitted CSS. */
						__( 'The CSS must not contain "%s".', 'newspack-popups' ),
						substr( $css, $at, 8 )
					),
					[ 'status' => 400 ]
				);
			}
		}

		return self::scan_css_structure( $css );
	}

	/**
	 * Scan the CSS once, left to right, balancing braces, parentheses, comments
	 * and quoted strings together. Strings and comments are tracked so their
	 * contents do not count as structure: `content: "{"` leaves the brace count
	 * alone. A bare newline (or carriage return or form feed) inside a string ends
	 * it in the browser, so it fails here as an unterminated string.
	 *
	 * Known limitation: there is no url() state, so `/*` inside an unquoted
	 * `url()` reads as a comment opener and is rejected. Quoting the URL avoids it.
	 *
	 * @param string $css CSS to scan.
	 * @return true|WP_Error True when comments, strings, braces and parentheses
	 *                       all close, WP_Error otherwise.
	 */
	private static function scan_css_structure( $css ) {
		$length      = strlen( $css );
		$depth       = 0;
		$paren_depth = 0;
		$quote       = '';
		$in_comment  = false;

		for ( $at = 0; $at < $length; $at++ ) {
			$char = $css[ $at ];

			if ( $in_comment ) {
				if ( '*' === $char && $at + 1 < $length && '/' === $css[ $at + 1 ] ) {
					$in_comment = false;
					++$at;
				}
				continue;
			}

			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					// A CRLF line continuation is one escaped unit, so both its bytes
					// are consumed here rather than the LF reading as a bare newline.
					if ( $at + 2 < $length && "\r" === $css[ $at + 1 ] && "\n" === $css[ $at + 2 ] ) {
						$at += 2;
					} else {
						++$at;
					}
				} elseif ( $quote === $char ) {
					$quote = '';
				} elseif ( "\n" === $char || "\r" === $char || "\f" === $char ) {
					return self::unterminated_string_error();
				}
				continue;
			}

			if ( '/' === $char && $at + 1 < $length && '*' === $css[ $at + 1 ] ) {
				$in_comment = true;
				++$at;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 > $depth ) {
					return new WP_Error(
						'newspack_popups_invalid_custom_css',
						__( 'The CSS contains an unbalanced brace.', 'newspack-popups' ),
						[ 'status' => 400 ]
					);
				}
			} elseif ( '(' === $char ) {
				++$paren_depth;
			} elseif ( ')' === $char && 0 < $paren_depth ) {
				// Clamped at zero so a stray closer cannot cancel out a later unclosed
				// opener and hide it from the check below.
				--$paren_depth;
			}
		}

		if ( $in_comment ) {
			return new WP_Error(
				'newspack_popups_invalid_custom_css',
				__( 'The CSS contains an unclosed comment.', 'newspack-popups' ),
				[ 'status' => 400 ]
			);
		}
		if ( '' !== $quote ) {
			return self::unterminated_string_error();
		}
		if ( 0 !== $depth ) {
			return new WP_Error(
				'newspack_popups_invalid_custom_css',
				__( 'The CSS contains an unbalanced brace.', 'newspack-popups' ),
				[ 'status' => 400 ]
			);
		}
		if ( 0 !== $paren_depth ) {
			return new WP_Error(
				'newspack_popups_invalid_custom_css',
				__( 'The CSS contains an unbalanced parenthesis.', 'newspack-popups' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * The "unterminated string" error.
	 *
	 * @return WP_Error
	 */
	private static function unterminated_string_error() {
		return new WP_Error(
			'newspack_popups_invalid_custom_css',
			__( 'The CSS contains an unterminated string.', 'newspack-popups' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Validate and persist custom CSS. An empty value removes the option.
	 *
	 * @param string $css Additional CSS.
	 * @return true|WP_Error True on save, WP_Error for CSS that fails validation.
	 */
	public static function save_custom_css( $css ) {
		$css   = is_string( $css ) ? $css : '';
		$valid = self::validate_custom_css( $css );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( '' === trim( $css ) ) {
			delete_option( self::CUSTOM_CSS_OPTION_NAME );
			return true;
		}
		update_option( self::CUSTOM_CSS_OPTION_NAME, $css );
		return true;
	}

	/**
	 * The overrides as a single CSS rule followed by the scoped custom CSS, or an
	 * empty string.
	 *
	 * @return string
	 */
	public static function get_css() {
		$css    = '';
		$styles = self::get_styles();
		if ( ! empty( $styles ) ) {
			$result = wp_style_engine_get_styles(
				$styles,
				[ 'selector' => ':root :where(' . self::SELECTOR . ')' ]
			);
			$css    = isset( $result['css'] ) ? $result['css'] : '';
		}
		// Wraps bare declarations in :root :where( selector ) and substitutes `&`.
		return $css . WP_Theme_JSON::process_blocks_custom_css( self::get_custom_css(), self::SELECTOR );
	}

	/**
	 * The block's effective default styles (theme.json cascade, presets
	 * resolved), for display in the wizard. Never includes this class's own
	 * overrides: they live in an option, not in theme.json data.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		$defaults = wp_get_global_styles(
			[ 'blocks', Newspack_Popups_Contextual_Prompt_Block::BLOCK_NAME ],
			[ 'transforms' => [ 'resolve-variables' ] ]
		);
		$defaults = is_array( $defaults ) ? $defaults : [];
		if ( ! isset( $defaults['color'] ) || ! is_array( $defaults['color'] ) ) {
			$defaults['color'] = [];
		}
		// The block's default design carries no text color, so a background chosen
		// in the wizard would have nothing to be checked against. Stand in the
		// color the prompt actually renders with, when that is one the wizard can
		// read.
		if ( empty( $defaults['color']['text'] ) ) {
			$inherited = self::get_inherited_text_color();
			if ( null !== $inherited ) {
				$defaults['color']['text'] = $inherited;
			}
		}
		return $defaults;
	}

	/**
	 * The text color a prompt inherits from the site, in a shape the wizard can
	 * read: a hex value, a preset reference, or an `rgb()` color as hex. The
	 * resolve-variables transform hands back a concrete color for a preset
	 * reference, whichever origin defines the preset; a `var()` lookup only
	 * survives it when the slug is in no palette.
	 *
	 * A color in none of those shapes hands back nothing rather than a stand-in:
	 * the wizard would check a chosen background against the wrong color, and a
	 * contrast warning pointing the wrong way is worse than no warning. Only a site
	 * with no global text color at all gets the assumed default.
	 *
	 * @return string|null Hex value or preset reference, null when unreadable.
	 */
	private static function get_inherited_text_color() {
		// A missing path hands back the whole styles tree, hence the string check.
		$text = wp_get_global_styles( [ 'color', 'text' ], [ 'transforms' => [ 'resolve-variables' ] ] );
		$text = is_string( $text ) ? trim( $text ) : '';
		if ( '' === $text ) {
			return self::get_assumed_text_color();
		}
		if ( preg_match( self::WIZARD_COLOR_PATTERN, $text ) ) {
			return $text;
		}
		return self::rgb_to_hex( $text );
	}

	/**
	 * The text color to assume when the site declares no global one. A classic
	 * theme states its body color in CSS, out of the global styles data's reach,
	 * so the Newspack theme's is named here and other classic themes supply
	 * theirs through the filter.
	 *
	 * @return string Hex value.
	 */
	private static function get_assumed_text_color() {
		$color = self::NEWSPACK_THEME_TEMPLATE === get_template() ? self::NEWSPACK_THEME_TEXT_COLOR : self::INITIAL_TEXT_COLOR;

		/**
		 * Filters the text color a Contextual Prompt is assumed to inherit when the
		 * site declares no global text color. Read by the wizard's contrast check,
		 * so it must be a hex value.
		 *
		 * @param string $color Hex color value.
		 */
		return apply_filters( 'newspack_popups_contextual_prompt_inherited_text_color', $color );
	}

	/**
	 * An `rgb()`/`rgba()` color as a hex string, alpha dropped: the wizard reads
	 * hex, and a contrast ratio has no use for alpha. Only the comma-separated
	 * integer form is handled — the percentage and space-separated CSS Color 4
	 * forms are not what theme.json data carries.
	 *
	 * @param string $value CSS color value.
	 * @return string|null Hex value, null when this is not such a color.
	 */
	private static function rgb_to_hex( $value ) {
		if ( ! preg_match( self::RGB_COLOR_PATTERN, $value, $matches ) ) {
			return null;
		}
		$hex = '#';
		foreach ( array_slice( $matches, 1, 3 ) as $channel ) {
			$channel = (int) $channel;
			if ( 255 < $channel ) {
				return null;
			}
			$hex .= str_pad( dechex( $channel ), 2, '0', STR_PAD_LEFT );
		}
		return $hex;
	}

	/**
	 * Front end: append the CSS to the global-styles handle so it prints after
	 * the block's default design. Falls back to a standalone handle when core
	 * has not registered global-styles, or has already printed it — inline CSS
	 * added to a printed handle is silently dropped.
	 */
	public static function enqueue_front_end_styles() {
		$css = self::get_css();
		if ( '' === $css ) {
			return;
		}
		if ( wp_style_is( 'global-styles', 'enqueued' ) && ! wp_style_is( 'global-styles', 'done' ) ) {
			wp_add_inline_style( 'global-styles', $css );
			return;
		}
		$handle = 'newspack-popups-contextual-prompt-styles';
		wp_register_style( $handle, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline styles only, no source file to version.
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Editor: append the CSS to the canvas styles, after the entries core added
	 * from theme.json, so order decides at equal specificity.
	 *
	 * @param array $settings Block editor settings.
	 * @return array
	 */
	public static function filter_block_editor_settings( $settings ) {
		$css = self::get_css();
		if ( '' === $css ) {
			return $settings;
		}
		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = [];
		}
		$settings['styles'][] = [ 'css' => $css ];
		return $settings;
	}
}
