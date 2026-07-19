<?php
/**
 * Perfmatters integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Perfmatters {
	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'option_perfmatters_options', [ __CLASS__, 'set_defaults' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
		add_filter( 'perfmatters_lazyload_youtube_thumbnail_resolution', [ __CLASS__, 'maybe_serve_high_res_youtube_thumbs' ] );
		add_filter( 'perfmatters_rucss_excluded_stylesheets', [ __CLASS__, 'add_rucss_excluded_stylesheets' ] );
		add_filter( 'perfmatters_delay_js', [ __CLASS__, 'should_delay_js' ] );
		add_filter( 'perfmatters_disable_woocommerce_scripts', [ __CLASS__, 'maybe_keep_woocommerce_assets' ] );
	}

	/**
	 * Scripts to delay spec.
	 */
	private static function scripts_to_delay() {
		$scripts_to_delay = [
			// Newspack.
			'newspack-popups',
			'newspack-blocks',
			'newspack-newsletters',
			'newspack-sponsors',
			'newspack-listings',
			'newspack-theme',
			'window.newspack',
			// WordPress.
			'videopress',
			'related-posts',
			'jp-search.js',
			'jetpack-comment',
			'photon.min.js',
			'comment-reply',
			'stats.wp.com',
			// Google Analytics.
			"ga( '",
			"ga('",
			'google-analytics.com/analytics.js',
			// Google Tag Manager.
			'/gtm.js',
			'/gtag/js',
			'gtag(',
			'/gtm-',
			'/gtm.',
			// Facebook.
			'fbevents.js',
			'fbq(',
			'/busting/facebook-tracking/',
			// Twitter.
			'ads-twitter.com',
			// Plugins.
			'mailchimp-for-woocommerce',
			'mailchimp-for-wp',
			// Third-party services.
			'disqus',
			'recaptcha',
			'twitter.com',
			// Advertising.
			'googletag.pubads',
			'adsbygoogle.js',
			'ai_insert_code',
			'doubleclick.net',
		];

		// Only delay newspack-plugin if reader activation is not enabled. Because there
		// are buttons that do things, and they should do those things on the first click.
		if ( ! Reader_Activation::is_enabled() ) {
			$scripts_to_delay[] = 'newspack-plugin';
		}

		return $scripts_to_delay;
	}

	/**
	 * Stylesheets to exclude from the "Unused CSS" feature.
	 */
	private static function unused_css_excluded_stylesheets() {
		return [
			'plugins/newspack-blocks', // Newspack Blocks.
			'plugins/newspack-newsletters', // Newspack Newsletters.
			'plugins/newspack-plugin', // Newspack main plugin.
			'plugins/newspack-popups', // Newspack Campaigns.
			'modules/sharedaddy', // Jetpack's share buttons.
			'_inc/social-logos', // Jetpack's social logos CSS.
			'plugins/jetpack/css/jetpack.css', // Jetpack's main CSS.
			'plugins/jetpack/_inc/blocks/swiper.css', // Jetpack's Swiper CSS.
			'plugins/the-events-calendar', // The Events Calendar.
			'plugins/events-calendar-pro', // The Events Calendar Pro.
			'/themes/newspack-', // Any Newspack theme stylesheet.
			'cache/perfmatters', // Perfmatters' cache.
			'wp-includes',
		];
	}

	/**
	 * Selectors to exclude from the "Unused CSS" feature.
	 */
	private static function unused_css_excluded_selectors() {
		return [
			'body',
		];
	}

	/**
	 * URLs to preconnect to.
	 *
	 * @param array $existing_urls Existing URLs to filter out.
	 */
	private static function preconnect_urls( $existing_urls = [] ) {
		return array_filter(
			[
				[
					'url'         => 'https://i0.wp.com',
					'crossorigin' => false,
				],
			],
			function( $url ) use ( $existing_urls ) {
				return ! in_array( $url['url'], $existing_urls );
			}
		);
	}

	/**
	 * Get Newspack default options for Perfmatters.
	 *
	 * @param array $options Initial options. Optional.
	 *
	 * @return array Newspack default options.
	 */
	private static function get_defaults( $options = [] ) {
		// Basic options.
		$options['disable_emojis']              = true;
		$options['disable_dashicons']           = true;
		$options['disable_woocommerce_scripts'] = true;
		// "The cart fragments feature and or AJAX request in WooCommerce is used to update the cart
		// total without refreshing the page."
		// https://perfmatters.io/docs/disable-woocommerce-cart-fragments-ajax/
		$options['disable_woocommerce_cart_fragmentation'] = true;

		// JS deferral.
		if ( ! isset( $options['assets'] ) ) {
			$options['assets'] = [];
		}
		$defer_js_exclusions           = [
			'wp-includes',
			'jwplayer.com', // This platform won't work if the JS is deferred.
			'adsrvr.org', // This platform won't work if the JS is deferred.
		];
		$options['assets']['defer_js'] = true;
		if ( isset( $options['assets']['js_exclusions'] ) && is_array( $options['assets']['js_exclusions'] ) ) {
			$options['assets']['js_exclusions'] = array_unique(
				array_merge(
					$options['assets']['js_exclusions'],
					$defer_js_exclusions
				)
			);
		} else {
			$options['assets']['js_exclusions'] = $defer_js_exclusions;
		}
		$options['assets']['defer_jquery'] = true;

		// JS delay.
		$options['assets']['delay_js'] = true;
		if ( isset( $options['assets']['delay_js_inclusions'] ) && is_array( $options['assets']['delay_js_inclusions'] ) ) {
			$options['assets']['delay_js_inclusions'] = array_unique(
				array_merge(
					$options['assets']['delay_js_inclusions'],
					self::scripts_to_delay()
				)
			);
		} else {
			$options['assets']['delay_js_inclusions'] = self::scripts_to_delay();
		}
		$options['assets']['delay_timeout'] = true;
		$options['assets']['fastclick']     = true;

		// Unused CSS.
		$options['assets']['remove_unused_css'] = true;
		if ( isset( $options['assets']['rucss_excluded_stylesheets'] ) && is_array( $options['assets']['rucss_excluded_stylesheets'] ) ) {
			$options['assets']['rucss_excluded_stylesheets'] = array_unique(
				array_merge(
					$options['assets']['rucss_excluded_stylesheets'],
					self::unused_css_excluded_stylesheets()
				)
			);
		} else {
			$options['assets']['rucss_excluded_stylesheets'] = self::unused_css_excluded_stylesheets();
		}
		if ( isset( $options['assets']['rucss_excluded_selectors'] ) && is_array( $options['assets']['rucss_excluded_selectors'] ) ) {
			$options['assets']['rucss_excluded_selectors'] = array_unique(
				array_merge(
					$options['assets']['rucss_excluded_selectors'],
					self::unused_css_excluded_selectors()
				)
			);
		} else {
			$options['assets']['rucss_excluded_selectors'] = self::unused_css_excluded_selectors();
		}

		// Preload.
		if ( ! isset( $options['preload'] ) ) {
			$options['preload'] = [];
		}
		$options['preload']['critical_images'] = '2';
		if ( isset( $options['preload']['preconnect'] ) && is_array( $options['preload']['preconnect'] ) ) {
			$options['preload']['preconnect'] = array_merge(
				$options['preload']['preconnect'],
				self::preconnect_urls( array_column( $options['preload']['preconnect'], 'url' ) )
			);
		} else {
			$options['preload']['preconnect'] = self::preconnect_urls();
		}

		// Lazyload.
		if ( ! isset( $options['lazyload'] ) ) {
			$options['lazyload'] = [];
		}
		$options['lazyload']['lazy_loading']               = false;
		$options['lazyload']['lazy_loading_iframes']       = false;
		$options['lazyload']['youtube_preview_thumbnails'] = false;
		$options['lazyload']['image_dimensions']           = true;

		// Add our customizations to the front of the array to avoid confusion when editing the setting in the UI.
		$lazy_loading_exclusions = isset( $options['lazyload']['lazy_loading_exclusions'] ) && is_array( $options['lazyload']['lazy_loading_exclusions'] ) ? $options['lazyload']['lazy_loading_exclusions'] : [];
		$options['lazyload']['lazy_loading_exclusions'] = array_unique(
			array_merge(
				[
					'attachment-woocommerce_thumbnail', // If WC product images are within a pagination, the pages loaded after pageload will not have images handled otherwise.
				],
				$lazy_loading_exclusions
			)
		);
		$parent_exclusions = isset( $options['lazyload']['lazy_loading_parent_exclusions'] ) && is_array( $options['lazyload']['lazy_loading_parent_exclusions'] ) ? $options['lazyload']['lazy_loading_parent_exclusions'] : [];
		$options['lazyload']['lazy_loading_parent_exclusions'] = array_unique(
			array_merge(
				[ 'wp-block-jetpack-image-compare' ],
				$parent_exclusions
			)
		);

		// Fonts.
		if ( ! isset( $options['fonts'] ) ) {
			$options['fonts'] = [];
		}
		$options['fonts']['disable_google_fonts'] = false;
		$options['fonts']['display_swap']         = true;
		$options['fonts']['local_google_fonts']   = true;

		return $options;
	}

	/**
	 * Set default options for Perfmatters.
	 * Overwrites existing options unless the NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS constant is set.
	 *
	 * @param array $options Perfmatters options.
	 *
	 * @return array Newspack default options.
	 */
	public static function set_defaults( $options = [] ) {
		$defaults = self::get_defaults( $options );

		// Ensure our defaults remain the default, but can be overwritten.
		if ( self::should_ignore_defaults() ) {
			// Ensure all keys from $defaults are present in $options.
			// The $options will not contain keys set to false, so these would be otherwise overwritten by
			// the array_merge call.
			foreach ( array_keys( $defaults ) as $key ) {
				$options[ $key ] = $options[ $key ] ?? false;
			}
			return array_merge( $defaults, $options );
		}

		return $defaults;
	}

	/**
	 * Should defaults be ignored and not applied?
	 */
	private static function should_ignore_defaults() {
		/**
		 * Prevents Newspack from applying default Perfmatters settings.
		 * Use if you want full manual control over Perfmatters configuration.
		 *
		 * @constant NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS
		 * @type     bool
		 * @default  Newspack applies Perfmatters defaults
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS', true );
		 */
		return defined( 'NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS' ) && NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS;
	}

	/**
	 * Add an admin notice.
	 */
	public static function admin_notice() {
		if (
			'settings_page_perfmatters' !== get_current_screen()->id
			|| self::should_ignore_defaults()
		) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
		. __( 'Newspack plugin is overriding Perfmatters settings. You can use the <code>NEWSPACK_IGNORE_PERFMATTERS_DEFAULTS</code> flag to disable that behavior.', 'newspack' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. '</p></div>';
	}

	/**
	 * Serve high resolution YouTube thumbnails if a constant is set.
	 *
	 * @param string $resolution Resolution.
	 */
	public static function maybe_serve_high_res_youtube_thumbs( $resolution ) {
		if ( self::should_ignore_defaults() ) {
			return $resolution;
		}
		/**
		 * Enables high-resolution YouTube video thumbnails in Perfmatters
		 * lazy load. May increase page weight slightly.
		 *
		 * @constant NEWSPACK_PERFMATTERS_USE_HIGH_RES_YOUTUBE_IMAGES
		 * @type     bool
		 * @default  Standard resolution YouTube thumbnails
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_PERFMATTERS_USE_HIGH_RES_YOUTUBE_IMAGES', true );
		 */
		if ( ! defined( 'NEWSPACK_PERFMATTERS_USE_HIGH_RES_YOUTUBE_IMAGES' ) || ! NEWSPACK_PERFMATTERS_USE_HIGH_RES_YOUTUBE_IMAGES ) {
			return $resolution;
		}

		// Use standard-res thumbnails on mobile devices.
		if ( ( function_exists( 'jetpack_is_mobile' ) && \jetpack_is_mobile() ) || \wp_is_mobile() ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_is_mobile_wp_is_mobile
			return $resolution;
		}

		// Use high-res thumbnails on desktop devices.
		return 'maxresdefault';
	}

	/**
	 * Use the Perfmatters filter to always exclude Newspack stylesheets from the "Unused CSS" feature,
	 * regardless of the settings value.
	 *
	 * This is a backup solution, in a edge case where a user overrides their settings.
	 *
	 * @param array $stylesheet_exclusions Existing stylesheet exclusions.
	 */
	public static function add_rucss_excluded_stylesheets( $stylesheet_exclusions ) {
		if ( self::should_ignore_defaults() ) {
			return $stylesheet_exclusions;
		}
		return array_unique( array_merge( $stylesheet_exclusions, self::unused_css_excluded_stylesheets() ) );
	}

	/**
	 * Whether to delay JS scripts.
	 *
	 * @param bool $delay_js Existing delay JS value.
	 *
	 * @return bool Whether to delay JS.
	 */
	public static function should_delay_js( $delay_js ) {
		// Don't delay JS on lite site requests.
		if ( Lite_Site::is_lite_site_request() ) {
			return false;
		}
		return $delay_js;
	}

	/**
	 * Veto Perfmatters' "Disable WooCommerce Scripts" strip on requests that
	 * actually render WooCommerce content, so block/shortcode styles aren't lost
	 * (NPPM-193). Keeps the global default `disable_woocommerce_scripts => true`
	 * intact, so the perf win stands on every other request.
	 *
	 * @param bool $disable Whether Perfmatters should disable WC scripts/styles.
	 *
	 * @return bool
	 */
	public static function maybe_keep_woocommerce_assets( $disable ) {
		if ( self::should_ignore_defaults() ) {
			return $disable;
		}
		if ( WooCommerce_Content_Detector::current_request_has_woocommerce_content() ) {
			return false;
		}
		return $disable;
	}
}
Perfmatters::init();
