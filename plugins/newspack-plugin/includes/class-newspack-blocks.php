<?php
/**
 * Newspack blocks functionality
 *
 * @package Newspack_Blocks
 */

/**
 * Newspack blocks functionality
 */
class Newspack_Blocks {

	/**
	 * Blocks from newspack-blocks, by their directory in src/blocks/.
	 */
	const BLOCKS = [
		'author-list',
		'author-profile',
		'checkout-button',
		'donate',
		'iframe',
		'video-playlist',
	];

	/**
	 * Script handles.
	 */
	const SCRIPT_HANDLES = [
		'modal-checkout'       => 'newspack-blocks-donate-modal-checkout',
		'modal-checkout-block' => 'newspack-blocks-donate-modal-checkout-block',
		'frequency-based'      => 'newspack-blocks-donate-frequency-based',
		'tiers-based'          => 'newspack-blocks-donate-tiers-based',
	];

	/**
	 * Per-request memoization for self::asset_version().
	 *
	 * @var array<string, string>
	 */
	private static $asset_version_cache = [];

	/**
	 * Add hooks and filters.
	 */
	public static function init() {
		add_action( 'jetpack_register_gutenberg_extensions', [ __CLASS__, 'disable_jetpack_donate' ], 99 );
		add_filter( 'the_content', [ __CLASS__, 'hide_post_content_when_iframe_block_is_fullscreen' ] );
		add_filter( 'newspack_popups_assess_has_disabled_popups', [ __CLASS__, 'disable_prompts_on_fullscreen_iframe_page' ] );
		add_filter( 'body_class', [ __CLASS__, 'add_body_classes' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'add_body_classes' ] );
	}

	/**
	 * Hide the post content when it contains an iframe block that is set to fullscreen mode.
	 *
	 * Excerpts are left alone: they are built from the same filter, and swapping the text
	 * for the iframe would leave the excerpt empty.
	 *
	 * @param string $content post content from the_content hook.
	 * @return string the post content.
	 */
	public static function hide_post_content_when_iframe_block_is_fullscreen( $content ) {
		if ( doing_filter( 'get_the_excerpt' ) || ! has_block( 'newspack-blocks/iframe' ) ) {
			return $content;
		}

		$block = self::get_fullscreen_iframe_block( get_post() );
		if ( $block ) {
			// we don't need the post content since the iframe will be fullscreen.
			$content = render_block( $block );
		}

		return $content;
	}

	/**
	 * Get a post's top-level fullscreen Iframe block, if it has one.
	 *
	 * @param WP_Post|null $post Post to check.
	 * @return array|null The block, or null.
	 */
	private static function get_fullscreen_iframe_block( $post ) {
		static $found = [];

		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		if ( array_key_exists( $post->ID, $found ) ) {
			return $found[ $post->ID ];
		}

		$found[ $post->ID ] = null;
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			// A block with no usable source renders nothing, so it must not take over the page.
			if ( 'newspack-blocks/iframe' === $block['blockName'] && ! empty( $block['attrs']['isFullScreen'] )
				&& '' !== newspack_blocks_get_iframe_src( $block['attrs']['src'] ?? '' ) ) {
				$found[ $post->ID ] = $block;
				break;
			}
		}

		return $found[ $post->ID ];
	}

	/**
	 * Whether this request is the page of a post taken over by a fullscreen Iframe block.
	 *
	 * Decided from the queried post rather than while its content renders, so the answer is
	 * the same for the whole request: the body class is printed before the loop runs, and a
	 * fullscreen post rendered inside another post's page must not affect that page.
	 *
	 * This says the post is built to take over the page, not that the iframe is on screen:
	 * a content gate can swap the block for its teaser, on the server or in the browser once
	 * a metered reader runs out, and neither is known when the body class prints. The
	 * stylesheet hides the rest of the page only while the block's iframe is present, which
	 * is what keeps the gate visible to that reader.
	 *
	 * @return bool
	 */
	private static function is_fullscreen_iframe_page() {
		return is_singular() && null !== self::get_fullscreen_iframe_block( get_queried_object() );
	}

	/**
	 * Suppress Newspack prompts on the page of a fullscreen Iframe post, which the iframe covers.
	 *
	 * @param bool $disabled Whether prompts are already disabled.
	 * @return bool
	 */
	public static function disable_prompts_on_fullscreen_iframe_page( $disabled ) {
		return $disabled || self::is_fullscreen_iframe_page();
	}

	/**
	 * Body class.
	 *
	 * @param string|array $classes Array or string of body class names.
	 * @return string|array Modified array or string of body class names.
	 */
	public static function add_body_classes( $classes ) {
		if ( is_array( $classes ) && self::is_fullscreen_iframe_page() ) {
			$classes[] = 'newspack-post-with-fullscreen-iframe';
		}

		if ( wp_is_block_theme() ) {
			// Handle string (admin) vs array (frontend) cases.
			if ( is_string( $classes ) ) {
				$classes .= ' is-block-theme ';
			} else {
				$classes[] = 'is-block-theme';
			}
		}

		return $classes;
	}

	/**
	 * Gather dependencies and paths needed for script enqueuing.
	 *
	 * @param string $script_path Path to the script relative to plugin root.
	 *
	 * @return array Associative array including dependency array, version, and web path to the script. Returns false if script doesn't exist.
	 */
	public static function script_enqueue_helper( $script_path ) {
		$local_path = NEWSPACK_BLOCKS__PLUGIN_DIR . $script_path;
		if ( ! file_exists( $local_path ) ) {
			return false;
		}

		$path_info   = pathinfo( $local_path );
		$asset_path  = $path_info['dirname'] . '/' . $path_info['filename'] . '.asset.php';
		$script_data = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => [ 'wp-a11y', 'wp-escape-html', 'wp-i18n', 'wp-polyfill' ],
				'version'      => filemtime( $local_path ),
			);

		$script_data['script_path'] = plugins_url( $script_path, NEWSPACK_BLOCKS__PLUGIN_FILE );
		return $script_data;
	}

	/**
	 * Resolve the cache-busting version for a built dist asset.
	 *
	 * Reads the content hash the build writes to `dist/<name>.asset.php`, so an
	 * asset's URL changes when its file changes, and only then. The plugin
	 * version can't do that: it stays the same when a build is reinstalled
	 * without a version bump, so browsers keep the cached file. The hash covers
	 * every file in the entry, so an entry's `.css` can use the same name as
	 * its `.js`.
	 *
	 * A trimmed copy of `Newspack::asset_version()` in newspack-plugin, without
	 * its cache reset or logging. This plugin must work without newspack-plugin,
	 * so it can't call that one. newspack-ads, newspack-blocks, newspack-listings
	 * and newspack-newsletters each carry this copy; a change to the name check
	 * belongs in all of them and in the original.
	 *
	 * @param string $name Asset path relative to `dist/`, without the extension.
	 *                     Examples: 'modal', 'donate/view'.
	 * @return string The content hash, or NEWSPACK_BLOCKS__VERSION when the
	 *                `.asset.php` file is missing or malformed.
	 */
	public static function asset_version( string $name ): string {
		if ( isset( self::$asset_version_cache[ $name ] ) ) {
			return self::$asset_version_cache[ $name ];
		}
		$version = NEWSPACK_BLOCKS__VERSION;
		// Accept only entry-shaped names, so a caller can't include a file
		// outside dist/. The second pattern rejects `..` segments, which the
		// first pattern's character set would otherwise let through.
		if (
			preg_match( '#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$#', $name )
			&& ! preg_match( '#(^|/)\.\.(/|$)#', $name )
		) {
			/**
			 * Filters the directory asset_version() reads `.asset.php` files from.
			 * Tests point this at a temp dir instead of writing into dist/.
			 *
			 * @param string $dir  Absolute path with a trailing slash.
			 * @param string $name Asset name being resolved.
			 */
			$dist_dir = apply_filters( 'newspack_blocks_asset_dist_dir', NEWSPACK_BLOCKS__PLUGIN_DIR . 'dist/', $name );
			$path     = $dist_dir . $name . '.asset.php';
			$asset    = file_exists( $path ) ? include $path : null;
			if ( is_array( $asset ) && ! empty( $asset['version'] ) && is_string( $asset['version'] ) ) {
				$version = $asset['version'];
			}
		}
		self::$asset_version_cache[ $name ] = $version;
		return $version;
	}

	/**
	 * Enqueue placeholder blocks assets.
	 */
	public static function enqueue_placeholder_blocks_assets() {
		if ( ! is_admin() ) {
			// In non-editor environment, do nothing.
			return;
		}
		$script_data = self::script_enqueue_helper( NEWSPACK_BLOCKS__BLOCKS_DIRECTORY . 'placeholder_blocks.js' );
		if ( $script_data ) {
			wp_enqueue_script(
				'newspack-blocks-placeholder-blocks',
				$script_data['script_path'],
				$script_data['dependencies'],
				$script_data['version'],
				true
			);
			wp_set_script_translations(
				'newspack-blocks-placeholder-blocks',
				'newspack-plugin',
				NEWSPACK_ABSPATH . 'languages'
			);
		}
	}

	/**
	 * Check if the Name Your Price extension is available.
	 *
	 * @return bool True if available, false if not.
	 */
	public static function can_use_name_your_price() {
		// If the donation platform is RevEngine, the Donate block should behave as if Name Your Price is available.
		if ( method_exists( 'Newspack\Donations', 'is_platform_nrh' ) && \Newspack\Donations::is_platform_nrh() ) {
			return true;
		}
		return class_exists( 'WC_Name_Your_Price_Helpers' );
	}

	/**
	 * Enqueue block scripts and styles for editor.
	 */
	public static function enqueue_block_assets() {
		if ( ! is_admin() ) {
			// In non-editor environment, do nothing.
			return;
		}
		$script_data = static::script_enqueue_helper( NEWSPACK_BLOCKS__BLOCKS_DIRECTORY . 'editor.js' );
		if ( $script_data ) {
			wp_enqueue_script(
				'newspack-blocks-editor',
				$script_data['script_path'],
				$script_data['dependencies'],
				$script_data['version'],
				true
			);

			$localized_data = [
				'assets_path'                => plugins_url( '/src/assets', NEWSPACK_BLOCKS__PLUGIN_FILE ),
				'iframe_accepted_file_mimes' => WP_REST_Newspack_Iframe_Controller::iframe_accepted_file_mimes(),
				'iframe_can_upload_archives' => WP_REST_Newspack_Iframe_Controller::can_upload_archives(),
				'supports_recaptcha'         => class_exists( 'Newspack\Recaptcha' ),
				'has_recaptcha'              => class_exists( 'Newspack\Recaptcha' ) && \Newspack\Recaptcha::can_use_captcha(),
				'recaptcha_url'              => admin_url( 'admin.php?page=newspack-settings' ),
				'can_use_name_your_price'    => self::can_use_name_your_price(),
				'coupons_enabled'            => function_exists( 'wc_coupons_enabled' ) && \wc_coupons_enabled(),
				'tier_amounts_template'      => self::get_formatted_amount(),
				'currency'                   => function_exists( 'get_woocommerce_currency' ) ? \get_woocommerce_currency() : 'USD',
			];

			if ( class_exists( 'WP_REST_Newspack_Author_List_Controller' ) ) {
				$localized_data['can_use_cap']    = class_exists( 'CoAuthors_Guest_Authors' );
				$localized_data['editable_roles'] = Newspack_Blocks\get_authors_roles();
			}

			if ( class_exists( '\Newspack\Authors_Custom_Fields' ) ) {
				$localized_data['author_custom_fields'] = \Newspack\Authors_Custom_Fields::get_custom_fields();
			}

			wp_localize_script(
				'newspack-blocks-editor',
				'newspack_blocks_data',
				$localized_data
			);

			wp_set_script_translations(
				'newspack-blocks-editor',
				'newspack-plugin',
				NEWSPACK_ABSPATH . 'languages'
			);
		}

		$editor_style = plugins_url( NEWSPACK_BLOCKS__BLOCKS_DIRECTORY . 'editor.css', NEWSPACK_BLOCKS__PLUGIN_FILE );
		$handle = 'newspack-blocks-editor';
		wp_enqueue_style(
			$handle,
			$editor_style,
			array(),
			self::asset_version( 'editor' )
		);
		wp_style_add_data( $handle, 'rtl', 'replace' );
	}

	/**
	 * Enqueue block scripts and styles for view.
	 */
	public static function manage_view_scripts() {
		$src_directory  = NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/blocks/';
		$dist_directory = NEWSPACK_BLOCKS__PLUGIN_DIR . 'dist/';
		// src/blocks/ also holds newspack-plugin's own blocks, so only walk these.
		foreach ( self::BLOCKS as $type ) {

			/* If view.php is found, include it and use for block rendering. */
			$view_php_path = $src_directory . $type . '/view.php';

			if ( file_exists( $view_php_path ) ) {
				include_once $view_php_path;
				continue;
			}

			// Skip remaining logic in admin - only needed for frontend asset loading.
			if ( is_admin() ) {
				continue;
			}

			/* If view.php is missing but view Javascript file is found, do generic view asset loading. */
			$view_js_path = $dist_directory . $type . '/view.js';
			if ( file_exists( $view_js_path ) ) {
				register_block_type(
					"newspack-blocks/{$type}",
					array(
						'render_callback' => function( $attributes, $content ) use ( $type ) {
							self::enqueue_view_assets( $type );
							return $content;
						},
					)
				);
			}
		}
	}

	/**
	 * Enqueue block styles stylesheet.
	 */
	public static function enqueue_block_styles_assets() {
		$style_path = NEWSPACK_BLOCKS__BLOCKS_DIRECTORY . 'block_styles.css';
		if ( file_exists( NEWSPACK_BLOCKS__PLUGIN_DIR . $style_path ) ) {
			$handle = 'newspack-blocks-block-styles-stylesheet';
			wp_enqueue_style(
				$handle,
				plugins_url( $style_path, NEWSPACK_BLOCKS__PLUGIN_FILE ),
				array(),
				self::asset_version( 'block_styles' )
			);
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
	}

	/**
	 * Enqueue view scripts and styles for a single block.
	 *
	 * @param string      $type     The block's type.
	 * @param string|null $strategy Optional. Script loading strategy to apply to the
	 *                              view script ('defer' or 'async'). First write wins:
	 *                              ignored if a strategy is already set on the handle.
	 *                              Default null (no strategy).
	 */
	public static function enqueue_view_assets( $type, $strategy = null ) {
		$style_path = apply_filters(
			'newspack_blocks_enqueue_view_assets',
			NEWSPACK_BLOCKS__BLOCKS_DIRECTORY . $type . '/view.css',
			$type,
			is_rtl()
		);

		if ( file_exists( NEWSPACK_BLOCKS__PLUGIN_DIR . $style_path ) ) {
			$handle = "newspack-blocks-{$type}";
			wp_enqueue_style(
				$handle,
				plugins_url( $style_path, NEWSPACK_BLOCKS__PLUGIN_FILE ),
				array(),
				self::asset_version( $type . '/view' )
			);
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
		$script_data = static::script_enqueue_helper( NEWSPACK_BLOCKS__BLOCKS_DIRECTORY . $type . '/view.js' );
		if ( $script_data ) {
			$handle = "newspack-blocks-{$type}";
			wp_enqueue_script(
				$handle,
				$script_data['script_path'],
				$script_data['dependencies'],
				$script_data['version'],
				true
			);
			if ( $strategy && ! wp_scripts()->get_data( $handle, 'strategy' ) ) {
				wp_script_add_data( $handle, 'strategy', $strategy );
			}
		}
	}

	/**
	 * Utility to assemble the class for a server-side rendered block.
	 *
	 * @param string $type The block type.
	 * @param array  $attributes Block attributes.
	 * @param array  $extra Additional classes to be added to the class list.
	 *
	 * @return string Class list separated by spaces.
	 */
	public static function block_classes( $type, $attributes = array(), $extra = array() ) {
		$classes = [ "wp-block-newspack-blocks-{$type}" ];

		if ( ! empty( $attributes['align'] ) ) {
			$classes[] = 'align' . $attributes['align'];
		}
		if ( ! empty( $attributes['hideControls'] ) ) {
			$classes[] = 'hide-controls';
		}
		if ( isset( $attributes['className'] ) ) {
			array_push( $classes, $attributes['className'] );
		}
		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$classes = array_merge( $classes, $extra );
		}

		return implode( ' ', array_filter( $classes, 'strlen' ) );
	}

	/**
	 * Utility to assemble the styles for a server-side rendered block.
	 *
	 * @param array $attributes Block attributes.
	 * @param array $extra      Additional styles to be added to the style list.
	 *
	 * @return string style list.
	 */
	public static function block_styles( $attributes = [], $extra = [] ) {
		$styles = [];
		if ( isset( $attributes['style'] ) && is_array( $attributes['style'] ) ) {
			$engine_styles = wp_style_engine_get_styles( $attributes['style'], [ 'context' => 'block-supports' ] );
			if ( isset( $engine_styles['css'] ) ) {
				$styles[] = $engine_styles['css'];
			}
		}

		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$styles = array_merge( $styles, $extra );
		}

		return implode( '', $styles );
	}

	/**
	 * Closure for excerpt filtering that can be added and removed.
	 *
	 * @var Closure
	 */
	public static $newspack_blocks_excerpt_closure = null;

	/**
	 * Closure for excerpt length filtering that can be added and removed.
	 *
	 * @var Closure
	 * @deprecated
	 */
	public static $newspack_blocks_excerpt_length_closure = null;

	/**
	 * Sanitize SVG markup for front-end display.
	 *
	 * @param string $svg SVG markup to sanitize.
	 * @return string Sanitized markup.
	 */
	public static function sanitize_svg( $svg = '' ) {
		$allowed_html = [
			'svg'  => [
				'xmlns'       => [],
				'fill'        => [],
				'viewbox'     => [],
				'role'        => [],
				'aria-hidden' => [],
				'focusable'   => [],
				'height'      => [],
				'width'       => [],
			],
			'path' => [
				'd'    => [],
				'fill' => [],
			],
		];

		return wp_kses( $svg, $allowed_html );
	}

	/**
	 * Disable Jetpack's donate block when using Newspack donations.
	 */
	public static function disable_jetpack_donate() {
		// Do nothing if Jetpack's blocks or Newspack aren't being used.
		if ( ! class_exists( 'Jetpack_Gutenberg' ) || ! class_exists( 'Newspack' ) ) {
			return;
		}

		// Allow Jetpack donations if Newspack donations isn't set up.
		$donate_settings = Newspack\Donations::get_donation_settings();
		if ( is_wp_error( $donate_settings ) ) {
			return;
		}

		// Tell Jetpack to mark the donations feature as unavailable.
		Jetpack_Gutenberg::set_extension_unavailable(
			'jetpack/donations',
			esc_html__( 'Jetpack donations is disabled in favour of Newspack donations.', 'newspack-plugin' )
		);
	}

	/**
	 * Loads a template with given data in scope.
	 *
	 * @param string $template Name of the template to be included.
	 * @param array  $data     Data to be passed into the template to be included.
	 * @param string $path     (Optional) Path to the folder containing the template.
	 * @return string
	 */
	public static function template_include( $template, $data = [], $path = NEWSPACK_BLOCKS__PLUGIN_DIR . 'src/templates/' ) {
		if ( ! strpos( $template, '.php' ) ) {
			$template = $template . '.php';
		}
		$path .= $template;
		if ( ! is_file( $path ) ) {
			return '';
		}
		ob_start();
		include $path;
		$contents = ob_get_contents();
		ob_end_clean();
		return $contents;
	}

	/**
	 * Pick either black or white text, whichever reads better on the given background.
	 *
	 * Scores pure black and pure white as text against the background and returns
	 * whichever produces the greater APCA lightness contrast (Lc); ties fall to
	 * black. The constants are the SA98G set from apca-w3 0.1.9.
	 *
	 * Keep in sync with getColorForContrast() in src/blocks/donate/utils.ts.
	 *
	 * @param string $hex Hexadecimal background color (#RGB, #RRGGBB or #RRGGBBAA, with or without #).
	 * @return string Either 'black' or 'white' (literal CSS color keywords).
	 */
	public static function get_color_for_contrast( $hex ) {
		$background_y = self::get_apca_luminance( $hex );
		$black_lc     = self::get_apca_contrast( $background_y, self::get_apca_luminance( '#000000' ) );
		$white_lc     = self::get_apca_contrast( $background_y, self::get_apca_luminance( '#ffffff' ) );

		return abs( $white_lc ) > abs( $black_lc ) ? 'white' : 'black';
	}

	/**
	 * Compute the soft-clamped APCA screen luminance (Y) of a hex color.
	 *
	 * Accepts #RGB, #RRGGBB and #RRGGBBAA (the alpha pair is stripped), with or
	 * without the leading #, case-insensitively. Unparseable input is treated as
	 * white (luminance 1.0) so callers fall back to black text.
	 *
	 * @param string $hex Hexadecimal color.
	 * @return float Soft-clamped luminance in the 0..1 range.
	 */
	private static function get_apca_luminance( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		} elseif ( 8 === strlen( $hex ) ) {
			// Drop the alpha pair from #RRGGBBAA.
			$hex = substr( $hex, 0, 6 );
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return 1.0;
		}

		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;

		$y = 0.2126729 * pow( $r, 2.4 ) + 0.7151522 * pow( $g, 2.4 ) + 0.0721750 * pow( $b, 2.4 );

		// APCA soft-clamp of near-black luminance.
		if ( $y <= 0.022 ) {
			$y += pow( 0.022 - $y, 1.414 );
		}

		return $y;
	}

	/**
	 * Compute the APCA lightness contrast (Lc) of text on a background.
	 *
	 * Positive values are dark text on a lighter background; negative values are
	 * light text on a darker background. Both luminances must already be
	 * soft-clamped.
	 *
	 * @param float $background_y Soft-clamped background luminance.
	 * @param float $text_y       Soft-clamped text luminance.
	 * @return float The Lc value.
	 */
	private static function get_apca_contrast( $background_y, $text_y ) {
		if ( abs( $background_y - $text_y ) < 0.0005 ) {
			return 0.0;
		}

		if ( $background_y > $text_y ) {
			$sapc = ( pow( $background_y, 0.56 ) - pow( $text_y, 0.57 ) ) * 1.14;
			return $sapc < 0.1 ? 0.0 : ( $sapc - 0.027 ) * 100;
		}

		$sapc = ( pow( $background_y, 0.65 ) - pow( $text_y, 0.62 ) ) * 1.14;
		return $sapc > -0.1 ? 0.0 : ( $sapc + 0.027 ) * 100;
	}

	/**
	 * Get an array of allowed HTML attributes for sanitizing image markup.
	 * For use with wp_kses: https://developer.wordpress.org/reference/functions/wp_kses/
	 *
	 * @return array
	 */
	public static function get_sanitized_image_attributes() {
		return [
			'img'      => [
				'alt'      => true,
				'class'    => true,
				'data-*'   => true,
				'decoding' => true,
				'height'   => true,
				'loading'  => true,
				'sizes'    => true,
				'src'      => true,
				'srcset'   => true,
				'width'    => true,
			],
			'noscript' => [],
			'a'        => [
				'href' => true,
			],
		];
	}

	/**
	 * Get a formatted HTML string containing amount and frequency of a donation.
	 *
	 * @param float  $amount Amount.
	 * @param string $frequency Frequency.
	 * @param bool   $hide_once_label Whether to hide the "once" label.
	 *
	 * @return string
	 */
	public static function get_formatted_amount( $amount = null, $frequency = null, $hide_once_label = false ) {
		if ( ! function_exists( 'wc_price' ) || ( method_exists( 'Newspack\Donations', 'is_platform_wc' ) && ! \Newspack\Donations::is_platform_wc() ) ) {
			if ( empty( $amount ) ) {
				return false;
			}

			// Translators: %s is the %s is the frequency.
			$frequency_string = 'once' === $frequency ? $frequency : sprintf( __( 'per %s', 'newspack-plugin' ), $frequency );
			$formatter        = new NumberFormatter( \get_locale(), NumberFormatter::CURRENCY );
			$formatted_price  = '<span class="price-amount">' . $formatter->formatCurrency( $amount, 'USD' ) . '</span> <span class="tier-frequency">' . $frequency_string . '</span>';
			return str_replace( '.00', '', $formatted_price );
		}

		$wc_formatted_amount = '';
		if ( null === $amount && null === $frequency ) {
			$currency_symbol     = function_exists( 'get_woocommerce_currency_symbol' ) ? \get_woocommerce_currency_symbol() : '&#36;';
			$wc_formatted_amount = '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">' . $currency_symbol . '</span>AMOUNT_PLACEHOLDER</bdi></span> FREQUENCY_PLACEHOLDER';
		} else {
			// If it's a float but with no decimal value, treat it as an int.
			if ( is_float( $amount ) && floor( $amount ) == $amount ) {
				$amount = (int) $amount;
			}
			// Format the amount with currency symbol and separators.
			$amount_string = \wc_price(
				$amount,
				[ 'decimals' => is_int( $amount ) ? 0 : 2 ]
			);

			if ( ! function_exists( 'wcs_price_string' ) ) {
				return $amount_string;
			}
			$price_args          = [
				'recurring_amount'    => $amount_string,
				'subscription_period' => 'once' === $frequency ? 'day' : $frequency,
			];
			$wc_formatted_amount = \wcs_price_string( $price_args );

			if ( 'once' === $frequency ) {
				$once_label          = $hide_once_label ? '' : __( ' once', 'newspack-plugin' );
				$wc_formatted_amount = preg_replace( '/ \/ ?.*/', $once_label, $wc_formatted_amount );
			}
			$wc_formatted_amount = str_replace( ' / ', __( ' per ', 'newspack-plugin' ), $wc_formatted_amount );
		}

		return '<span class="wpbnbd__tiers__amount__value">' . $wc_formatted_amount . '</span>';
	}
}
Newspack_Blocks::init();
