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
	 * Seed identifying the share-token HMAC. Global (not post-scoped) so the gate can verify a
	 * token at `plugins_loaded`, before the main query resolves which post is being requested.
	 *
	 * @var string
	 */
	const SHARE_TOKEN_ACTION = 'newspack_share';

	/**
	 * Query arg carrying the share token on a restored share URL.
	 *
	 * @var string
	 */
	const SHARE_TOKEN_QUERY_ARG = '_newspack_share_token';

	/**
	 * Original `?share=` queries blanked by obfuscate_share_query(), keyed by the sharing-source
	 * object's spl_object_id(). add_obfuscation_data_attribute() reads and clears each entry to
	 * rebuild the data attributes. Both filters receive the same source object, so this carries
	 * the query between them even on Jetpack's block Sharing Buttons, whose data-attributes filter
	 * runs in a separate method that never receives the query in its args.
	 *
	 * @var array<int,string>
	 */
	private static $blanked_queries = [];

	/**
	 * Whether at least one share button has been obfuscated during this request. Gates the
	 * restore script: it is printed only when there is something to restore, which is the right
	 * signal on the block theme, where the classic sharedaddy module may be inactive.
	 *
	 * @var bool
	 */
	private static $did_obfuscate = false;

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

		// Hide social share links from bots by deferring the un-cacheable share URL until real user interaction.
		add_filter( 'jetpack_sharing_display_query', [ __CLASS__, 'obfuscate_share_query' ], 10, 4 );
		add_filter( 'jetpack_sharing_data_attributes', [ __CLASS__, 'add_obfuscation_data_attribute' ], 10, 4 );
		add_action( 'wp_footer', [ __CLASS__, 'print_share_obfuscation_script' ] );

		// Reject fabricated `?share=` requests as early as possible, before WordPress resolves
		// the query or loads the theme. `plugins_loaded` is the earliest hook where the token
		// signing functions are available (pluggable functions are loaded just before it fires).
		add_action( 'plugins_loaded', [ __CLASS__, 'gate_share_request' ] );

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
	 * Whether the social share-link bot obfuscation is enabled.
	 *
	 * Jetpack's "official" share buttons (X, Facebook, LinkedIn, Reddit, Print, Email…)
	 * point at the post's own permalink with a `?share=<service>` query. Requesting that
	 * URL is un-cacheable by construction: it carries a query string and returns a dynamic
	 * redirect, so it boots full WordPress every time. Bots crawling the links in bulk turn
	 * that into real origin load. When enabled, the share query is deferred out of the
	 * rendered markup and rebuilt on genuine user interaction, so crawlers never see a
	 * fetchable `?share=` URL and hit the cacheable permalink instead.
	 *
	 * @return bool
	 */
	private static function is_share_obfuscation_enabled() {
		/**
		 * Filters whether Jetpack social share links are obfuscated to discourage bot traffic.
		 *
		 * @param bool $enabled Whether the obfuscation is enabled. Default true.
		 */
		return (bool) apply_filters( 'newspack_jetpack_obfuscate_share_links', true );
	}

	/**
	 * Whether a Jetpack share-link query is one of the un-cacheable, origin round-trip
	 * "official" services (e.g. `share=twitter`) as opposed to a direct off-site link.
	 *
	 * @param mixed $query The query string passed to Sharing_Source::get_link().
	 * @return bool
	 */
	private static function is_share_roundtrip_query( $query ) {
		return is_string( $query ) && 0 === strpos( $query, 'share=' );
	}

	/**
	 * Blank the `?share=` query on the rendered share-button href.
	 *
	 * With the query removed, the visible href is the bare (cacheable) post permalink,
	 * so a crawler following it gets a cache hit rather than the un-cacheable share
	 * handler. The real query is stashed against the source object so
	 * add_obfuscation_data_attribute() can hand it to the client script.
	 *
	 * @param string       $query  The sharing service URL query parameter.
	 * @param object       $source Sharing service instance; the key the stashed query is filed under.
	 * @param string|false $id     Sharing ID. Unused.
	 * @param array        $args   Array of sharing service options. Unused.
	 * @return string The (possibly blanked) query.
	 */
	public static function obfuscate_share_query( $query, $source = null, $id = false, $args = [] ) {
		if ( self::is_share_obfuscation_enabled() && self::is_share_roundtrip_query( $query ) ) {
			if ( is_object( $source ) ) {
				self::$blanked_queries[ spl_object_id( $source ) ] = $query;
			}
			return '';
		}
		return $query;
	}

	/**
	 * Stash the original `?share=` query in a data attribute so the client script can
	 * rebuild the real share URL on genuine user interaction.
	 *
	 * The query comes from the stash obfuscate_share_query() filed against this same source
	 * object, not from $args: Jetpack's block Sharing Buttons fire this filter from a separate
	 * method whose args never include the query. The value written is the raw query token
	 * (e.g. `share=twitter`), not a URL, so no fetchable `?share=` URL string is left in the DOM
	 * for URL-scraping bots to follow.
	 *
	 * @param array  $data_attributes Attributes supplied from the sharing source. Keys are
	 *                                rendered with a `data-` prefix.
	 * @param object $source          Sharing service instance; the key the blanked query is filed under.
	 * @param string $id              Sharing ID. Unused.
	 * @param array  $args            Array of sharing service options. Unused.
	 * @return array The (possibly augmented) data attributes.
	 */
	public static function add_obfuscation_data_attribute( $data_attributes, $source = null, $id = false, $args = [] ) {
		$data_attributes = (array) $data_attributes;
		if ( ! is_object( $source ) ) {
			return $data_attributes;
		}
		$source_id = spl_object_id( $source );
		$query     = self::$blanked_queries[ $source_id ] ?? '';
		unset( self::$blanked_queries[ $source_id ] );
		if ( self::is_share_obfuscation_enabled() && self::is_share_roundtrip_query( $query ) ) {
			$data_attributes['share-query'] = $query;
			// Sign the restored request so the server-side gate can tell a real, JS-restored
			// share URL from one a crawler fabricated by appending `?share=…` to a permalink.
			$data_attributes['share-token'] = self::share_token();
			self::$did_obfuscate            = true;
		}
		// The email button's mailto: href is left intact, but Jetpack pings an on-site tracking
		// URL (`?share=email`) via XHR on click. Sign that URL so the ping clears the gate; an
		// unsigned, fabricated `?share=email` request is still turned away.
		if ( self::is_share_obfuscation_enabled() && isset( $data_attributes['email-share-track-url'] ) ) {
			$data_attributes['email-share-track-url'] = add_query_arg(
				self::SHARE_TOKEN_QUERY_ARG,
				self::share_token(),
				$data_attributes['email-share-track-url']
			);
		}
		return $data_attributes;
	}

	/**
	 * A rotating, signed token proving a share URL came from our own markup rather than being
	 * fabricated by a crawler.
	 *
	 * Deliberately not a WordPress nonce. Nonces are session-scoped and live only 12-24h, but
	 * this token is baked into page-cacheable HTML that Batcache can serve for up to a day, so a
	 * nonce would expire while the cached page is still live and reject genuine share clicks. The
	 * token instead rotates on a daily bucket and is accepted for a few buckets (see
	 * is_valid_share_token()), comfortably outlasting the cache. It carries no CSRF duty: sharing
	 * is not a state-changing authenticated action. And since the token sits in the cached markup
	 * any HTML-parsing bot can read it anyway, so its lifetime does not weaken the deterrent,
	 * which only ever stopped crawlers that fabricate `?share=` without parsing the page.
	 *
	 * @param int $bucket_offset How many daily buckets back to compute the token for. 0 is current.
	 * @return string
	 */
	public static function share_token( $bucket_offset = 0 ) {
		$bucket = (int) floor( time() / DAY_IN_SECONDS ) - (int) $bucket_offset;
		return wp_hash( self::SHARE_TOKEN_ACTION . '|' . $bucket, 'nonce' );
	}

	/**
	 * Whether a token matches the current or a recent daily bucket. The window (current plus the
	 * previous two buckets, so two-to-three days) is wider than the 24h maximum page-cache TTL,
	 * so a share click on a day-old cached page still verifies, with margin for clock skew.
	 *
	 * @param string $token The token from the request.
	 * @return bool
	 */
	public static function is_valid_share_token( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		for ( $offset = 0; $offset <= 2; $offset++ ) {
			if ( hash_equals( self::share_token( $offset ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current request is an un-cacheable `?share=` round-trip that lacks a valid
	 * Newspack share token, and so should be turned away before it reaches Jetpack's handler.
	 *
	 * Reads only request globals, so it can run at `plugins_loaded` without the main query. A
	 * genuine share click carries the token that add_obfuscation_data_attribute() minted and the
	 * client script appended on restore; a crawler that fabricated the URL does not. Non-front-end
	 * contexts and sites where Jetpack sharing is off are left alone, since their `?share=` (if
	 * any) is not ours to intercept.
	 *
	 * @return bool
	 */
	public static function should_block_share_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only detecting a share request; the token is verified below.
		if ( ! isset( $_GET['share'] ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		// Jetpack's presence (not the classic sharedaddy module) is the signal: its block Sharing
		// Buttons produce and process `?share=` round-trips without that module active.
		if ( ! self::is_share_obfuscation_enabled() || ! class_exists( 'Jetpack' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The share token below is our own signed verification, not a WordPress nonce.
		$token = isset( $_GET[ self::SHARE_TOKEN_QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SHARE_TOKEN_QUERY_ARG ] ) ) : '';
		return ! self::is_valid_share_token( $token );
	}

	/**
	 * The URL a blocked share request is redirected to: the current request stripped of the
	 * share args, i.e. the bare, page-cacheable permalink.
	 *
	 * @return string
	 */
	public static function get_share_redirect_url() {
		return remove_query_arg( [ 'share', 'nb', self::SHARE_TOKEN_QUERY_ARG ] );
	}

	/**
	 * Turn away fabricated `?share=` requests with a redirect to the cacheable permalink.
	 *
	 * Hooked early (`plugins_loaded`) so a crawler that guessed the `?share=` pattern is
	 * bounced before WordPress resolves the query or renders the theme, rather than booting
	 * Jetpack's un-cacheable share pipeline. Genuine clicks carry a valid token and pass through.
	 *
	 * @return void
	 */
	public static function gate_share_request() {
		if ( ! self::should_block_share_request() ) {
			return;
		}
		wp_safe_redirect( self::get_share_redirect_url() );
		exit;
	}

	/**
	 * Print the small progressive-enhancement script that restores the real share URL.
	 *
	 * On the first genuine interaction (hover, focus or touch) with a share link, the
	 * original `?share=…` query is appended back onto its permalink href. From that point
	 * the anchor behaves exactly as Jetpack renders it, so native navigation, popup
	 * handlers and share-stat counting are untouched. Bots that neither run this script nor
	 * dispatch interaction events only ever see the bare, cacheable permalink. The token is
	 * appended so the restored URL clears the server-side gate, and `&nb=1` mirrors what
	 * Jetpack's own sharing.js appends for real user clicks.
	 */
	public static function print_share_obfuscation_script() {
		// Print only when a button was actually blanked this request, so the script accompanies
		// obfuscated links whether they came from the classic module or the block Sharing Buttons.
		if ( ! self::$did_obfuscate ) {
			return;
		}
		$token_arg = self::SHARE_TOKEN_QUERY_ARG;
		wp_print_inline_script_tag(
			<<<JS
( function () {
	function restore( event ) {
		var anchor = event.target && event.target.closest ? event.target.closest( 'a[data-share-query]' ) : null;
		if ( ! anchor ) {
			return;
		}
		var query = anchor.getAttribute( 'data-share-query' );
		if ( ! query ) {
			return;
		}
		var token = anchor.getAttribute( 'data-share-token' );
		var href = anchor.getAttribute( 'href' ) || '';
		var url = href + ( href.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
		if ( token ) {
			url += '&{$token_arg}=' + encodeURIComponent( token );
		}
		url += ( url.indexOf( 'nb=' ) === -1 ? '&nb=1' : '' );
		anchor.setAttribute( 'href', url );
		anchor.removeAttribute( 'data-share-query' );
		anchor.removeAttribute( 'data-share-token' );
	}
	[ 'pointerover', 'focusin', 'touchstart' ].forEach( function ( type ) {
		document.addEventListener( type, restore, true );
	} );
} )();
JS
		);
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
