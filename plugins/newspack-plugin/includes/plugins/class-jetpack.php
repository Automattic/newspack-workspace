<?php
/**
 * Jetpack integration class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Jetpack {

	/**
	 * Modules scripts handles.
	 *
	 * @var string[]
	 */
	private static $scripts_handles = [
		'jp-tracks',              // Tracks analytics library.
		'jetpack-instant-search', // Jetpack Instant Search.
		'jetpack-search-widget',  // Jetpack Search widget.
	];

	/**
	 * Default modules
	 *
	 * @var string[]
	 */
	public static $default_active_modules = [
		/**
		 * Assets CDN
		 *
		 * @link https://jetpack.com/support/site-accelerator/
		 */
		'photon-cdn',
		/**
		 * Image CDN
		 *
		 * @link https://jetpack.com/support/site-accelerator/
		 */
		'photon',
		/**
		 * Contact Form
		 *
		 * @link https://jetpack.com/support/contact-form/
		 */
		'contact-form',
		/**
		 * Brute Force Protection.
		 *
		 * @link https://jetpack.com/support/protect/
		 */
		'protect',
		/**
		 * JSON API
		 *
		 * @link https://jetpack.com/support/json-api/
		 */
		'json-api',
		/**
		 * Notifications
		 *
		 * @link https://jetpack.com/support/notifications/
		 */
		'notes',
		/**
		 * Stats
		 *
		 * @link https://jetpack.com/support/jetpack-stats/
		 */
		'stats',
		/**
		 * Site Verification
		 *
		 * @link https://jetpack.com/support/site-verification-tools/
		 */
		'verification-tools',
		/**
		 * Carousel
		 *
		 * @link https://jetpack.com/support/carousel/
		 */
		'carousel',
		/**
		 * Copy Post
		 *
		 * @link https://jetpack.com/support/copy-post/
		 */
		'copy-post',
		/**
		 * Extra Sidebar Widgets
		 *
		 * @link https://jetpack.com/support/extra-sidebar-widgets/
		 */
		'widgets',
		/**
		 * Gravatar Hovercards
		 *
		 * @link https://jetpack.com/support/gravatar-hovercards
		 */
		'gravatar-hovercards',
		/**
		 * Social
		 *
		 * @link https://jetpack.com/support/jetpack-social/
		 */
		'publicize',
		/**
		 * Related Posts
		 *
		 * @link https://jetpack.com/support/related-posts/
		 */
		'related-posts',
		/**
		 * Sharing
		 *
		 * @link https://jetpack.com/support/sharing/
		 */
		'sharedaddy',
		/**
		 * Sitemaps
		 *
		 * @link https://jetpack.com/support/sitemaps
		 */
		'sitemaps',
		/**
		 * Tiled Gallery
		 *
		 * @link https://jetpack.com/support/jetpack-blocks/tiled-galleries/
		 */
		'tiled-gallery',
		/**
		 * Widget Visibility
		 *
		 * @link https://jetpack.com/support/widget-visibility/
		 */
		'widget-visibility',
	];

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'jetpack_async_scripts' ], 20 );
		add_filter( 'newspack_amp_plus_sanitized', [ __CLASS__, 'jetpack_modules_amp_plus' ], 10, 2 );
		add_action( 'wp_head', [ __CLASS__, 'fix_instant_search_sidebar_display' ], 10 );
		add_filter( 'jetpack_lazy_images_skip_image_with_attributes', [ __CLASS__, 'skip_lazy_loading_on_feeds' ], 10 );
		add_filter( 'wp_calculate_image_srcset', [ __CLASS__, 'filter_srcset_array' ], 100, 5 );

		// Disables Google Analytics.
		add_filter( 'jetpack_active_modules', [ __CLASS__, 'remove_google_analytics_from_active' ], 10, 2 );
		add_filter( 'jetpack_get_available_modules', [ __CLASS__, 'remove_google_analytics_from_available' ] );

		// Disables Subscriptions (Newsletter). Jetpack 15.9 auto-enables this; Newspack publishers use newspack-newsletters.
		add_filter( 'jetpack_active_modules', [ __CLASS__, 'remove_subscriptions_from_active' ], 10, 2 );
		add_filter( 'jetpack_get_available_modules', [ __CLASS__, 'remove_subscriptions_from_available' ] );

		// Set Jetpack default modules on Newspack setup.
		add_action( 'add_option_newspack_setup_complete', [ __CLASS__, 'set_default_modules' ], 10, 2 );
		add_action( 'update_option_newspack_setup_complete', [ __CLASS__, 'set_default_modules' ], 10, 2 );

		// Modify the related posts timeframe.
		add_filter( 'jetpack_relatedposts_filter_date_range', [ __CLASS__, 'restrict_age_of_related_posts' ] );

		// Disable Jetpack Image Studio as late as possible so dequeues cannot be overridden.
		add_action( 'admin_print_scripts', [ __CLASS__, 'disable_image_studio' ], 999 );
	}

	/**
	 * Filters an array of image `srcset` values, adding Photon urls for additional sizes.
	 *
	 * @param array  $sources       An array of image urls and widths.
	 * @param int[]  $size_array    The size array for srcset.
	 * @param string $image_src     The 'src' of the image.
	 * @param array  $image_meta    The image meta.
	 * @param int    $attachment_id The image attachment ID.
	 *
	 * @return array An array of Photon image urls and widths.
	 */
	public static function filter_srcset_array( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! class_exists( 'Jetpack' ) || ! \Jetpack::is_module_active( 'photon' ) ) {
			return $sources;
		}
		if ( ! function_exists( 'jetpack_photon_url' ) ) {
			return $sources;
		}

		/**
		 * Filter the additional sizes to add to the srcset.
		 *
		 * @param array $additional_sizes An array of additional sizes to add to the srcset.
		 */
		$additional_sizes = apply_filters( 'newspack_photon_srcset_additional_sizes', [ 370, 400 ] );

		foreach ( $additional_sizes as $w ) {
			if ( isset( $sources[ $w ] ) ) {
				continue;
			}
			$sources[ $w ] = [
				'url'        => \jetpack_photon_url( $image_src, [ 'w' => $w ] ),
				'descriptor' => 'w',
				'value'      => $w,
			];
		}

		return $sources;
	}

	/**
	 * Skip image lazy-loading on RSS feeds.
	 *
	 * @param bool $skip_lazy_loading Whether to skip lazy-loading.
	 * @return @bool Whether to skip lazy-loading.
	 */
	public static function skip_lazy_loading_on_feeds( $skip_lazy_loading ) {
		if ( is_feed() ) {
			return true;
		}
		return $skip_lazy_loading;
	}

	/**
	 * Whether Jetpack modules scripts should be rendered in AMP Plus.
	 *
	 * @return @bool Whether to render scripts.
	 */
	private static function should_amp_plus_modules() {
		/**
		 * Enables Jetpack module scripts on AMP pages when AMP Plus is active.
		 * Includes sharing buttons, related posts, and other Jetpack features.
		 * Requires NEWSPACK_AMP_PLUS_ENABLED to also be set.
		 *
		 * @constant NEWSPACK_AMP_PLUS_JETPACK_MODULES
		 * @type     bool
		 * @default  Jetpack modules AMP Plus handling disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_AMP_PLUS_JETPACK_MODULES', true );
		 */
		if ( defined( 'NEWSPACK_AMP_PLUS_JETPACK_MODULES' ) ) {
			return true === NEWSPACK_AMP_PLUS_JETPACK_MODULES;
		}
		return false;
	}

	/**
	 * Make Jetpack scripts async.
	 */
	public static function jetpack_async_scripts() {
		foreach ( self::$scripts_handles as $handle ) {
			wp_script_add_data( $handle, 'async', true );
		}
	}

	/**
	 * Allow Jetpack modules scripts to be loaded in AMP Plus mode.
	 *
	 * @param bool|null $is_sanitized If null, the error will be handled. If false, rejected.
	 * @param object    $error        The AMP sanitisation error.
	 *
	 * @return bool Whether the error should be rejected.
	 */
	public static function jetpack_modules_amp_plus( $is_sanitized, $error ) {
		if ( ! self::should_amp_plus_modules() ) {
			return $is_sanitized;
		}
		if ( AMP_Enhancements::is_script_attribute_matching_strings( self::$scripts_handles, $error ) ) {
			$is_sanitized = false;
		}

		// Match inline scripts by script text since they don't have IDs.
		if ( AMP_Enhancements::is_script_body_matching_strings(
			[
				'jetpackSearchModuleSorting',  // Jetpack Search module sorting.
				'JetpackInstantSearchOptions', // Jetpack Instant Search options.
			],
			$error
		) ) {
			$is_sanitized = false;
		}
		return $is_sanitized;
	}

	/**
	 * Fix Instant Search Sidebar Display for AMP Plus
	 */
	public static function fix_instant_search_sidebar_display() {
		if ( ! self::should_amp_plus_modules() ) {
			return;
		}
		?>
		<style>
			.jetpack-instant-search__widget-area {
				display: block !important;
			}
		</style>
		<?php
	}

	/**
	 * Disables Google Analytics module. Users will not be able to activate it.
	 *
	 * @param array $modules Array with modules slugs.
	 * @return array
	 */
	public static function remove_google_analytics_from_active( $modules ) {
		return array_diff( $modules, array( 'google-analytics' ) );
	}

	/**
	 * Remove Google Analytics from available modules
	 *
	 * @param array $modules The array of available modules.
	 * @return array
	 */
	public static function remove_google_analytics_from_available( $modules ) {
		if ( isset( $modules['google-analytics'] ) ) {
			unset( $modules['google-analytics'] );
		}
		return $modules;
	}

	/**
	 * Filters out the Subscriptions (Newsletter) module from Jetpack's active-modules list at read time.
	 * The module reports as inactive even if its slug is present in the jetpack_active_modules option.
	 *
	 * @param array $modules Array of module slugs.
	 * @return array
	 */
	public static function remove_subscriptions_from_active( $modules ) {
		return array_diff( $modules, array( 'subscriptions' ) );
	}

	/**
	 * Remove Subscriptions (Newsletter) from available modules.
	 *
	 * @param array $modules The array of available modules.
	 * @return array
	 */
	public static function remove_subscriptions_from_available( $modules ) {
		if ( isset( $modules['subscriptions'] ) ) {
			unset( $modules['subscriptions'] );
		}
		return $modules;
	}

	/**
	 * Set Jetpack Default Modules on newspack setup complete.
	 *
	 * @param string $old_val_or_opt_name If adding will be opt name. If updating will be old value.
	 * @param string $new_val Value correlated to update/add.
	 * @return void
	 */
	public static function set_default_modules( string $old_val_or_opt_name, string $new_val ) {
		if ( $new_val === '1' ) {
			update_option( 'jetpack_active_modules', static::$default_active_modules );
		}
	}

	/**
	 * Restrict the age of related content shown by Jetpack Related Posts.
	 *
	 * @param array $date_range Array of start and end dates.
	 * @return array Filtered array of start/end dates.
	 */
	public static function restrict_age_of_related_posts( $date_range ) {
		$related_posts_max_age = get_option( Wizards\Newspack\Recirculation_Section::RELATED_POSTS_OPTION );
		if ( is_numeric( $related_posts_max_age ) && 0 < $related_posts_max_age ) {
			$date_range['from'] = strtotime( '-' . $related_posts_max_age . ' months' );
			$date_range['to']   = time();
		}

		return $date_range;
	}

	/**
	 * Disable Jetpack Image Studio scripts and styles.
	 *
	 * Image Studio's full-screen AI editor replaces the Media Library attachment
	 * view, hiding custom fields like photo credits. Dequeuing the assets using
	 * the current handles has been tested with Jetpack 15.7+ (handles: image-studio / image-studio-style).
	 */
	public static function disable_image_studio() {
		wp_dequeue_script( 'image-studio' );
		wp_dequeue_style( 'image-studio-style' );
	}
}
Jetpack::init();
