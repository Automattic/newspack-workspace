<?php
/**
 * Google Site Kit integration class.
 *
 * @package Newspack
 */

namespace Newspack;

use Google\Site_Kit\Context;
use Google\Site_Kit\Modules\Analytics_4\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class GoogleSiteKit {
	const GA4_SETUP_DONE_OPTION_NAME = 'newspack_analytics_has_set_up_ga4';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'setup_sitekit_ga4' ] );
		add_action( 'wp_footer', [ __CLASS__, 'insert_ga4_analytics' ] );
		add_filter( 'option_googlesitekit_analytics_settings', [ __CLASS__, 'filter_ga_settings' ] );
		add_filter( 'option_googlesitekit_analytics-4_settings', [ __CLASS__, 'filter_ga_settings' ] );
		add_filter( 'googlesitekit_gtag_opt', [ __CLASS__, 'add_ga_custom_parameters' ] );
	}

	/**
	 * Filter GA settings.
	 *
	 * @param array $googlesitekit_analytics_settings GA settings.
	 */
	public static function filter_ga_settings( $googlesitekit_analytics_settings ) {
		if ( ! is_array( $googlesitekit_analytics_settings ) || ! isset( $googlesitekit_analytics_settings['trackingDisabled'] ) || ! is_array( $googlesitekit_analytics_settings['trackingDisabled'] ) ) {
			return $googlesitekit_analytics_settings;
		}
		if ( in_array( 'loggedinUsers', $googlesitekit_analytics_settings['trackingDisabled'] ) ) {
			$googlesitekit_analytics_settings['trackingDisabled'] = [ 'contentCreators' ];
		}
		return $googlesitekit_analytics_settings;
	}

	/**
	 * Add GA4 analytics pageview reporting to AMP pages.
	 */
	public static function insert_ga4_analytics() {
		if ( ! function_exists( 'is_amp_endpoint' ) || ! is_amp_endpoint() ) {
			return;
		}
		$sitekit_ga4_settings = self::get_sitekit_ga4_settings();
		if ( false === $sitekit_ga4_settings || ! $sitekit_ga4_settings['useSnippet'] || ! isset( $sitekit_ga4_settings['measurementID'] ) ) {
			return;
		}
		$ga4_measurement_id = $sitekit_ga4_settings['measurementID'];
		// See https://github.com/analytics-debugger/google-analytics-4-for-amp.
		$config_path = Newspack::plugin_url() . '/includes/raw_assets/ga4.json';

		?>
			<amp-analytics type="googleanalytics" config="<?php echo esc_attr( $config_path ); ?>" data-credentials="include">
				<script type="application/json">
					{
						"vars": {
							"GA4_MEASUREMENT_ID": "<?php echo esc_attr( $ga4_measurement_id ); ?>",
							"DEFAULT_PAGEVIEW_ENABLED": true,
							"GOOGLE_CONSENT_ENABLED": false
						}
					}
				</script>
			</amp-analytics>
		<?php
	}

	/**
	 * Get whether Site Kit is active.
	 *
	 * @return bool Whether Site Kit is active.
	 */
	public static function is_active() {
		return class_exists( 'Google\Site_Kit\Core\Modules\Module' );
	}

	/**
	 * Get whether the current user is connected.
	 *
	 * @return bool Whether the user is connected to Google through Site Kit.
	 */
	private static function is_user_connected() {
		global $wpdb;

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return ! empty( get_user_meta( $user_id, $wpdb->prefix . 'googlesitekit_site_verified_meta', true ) );
	}

	/**
	 * Get the name of the option under which Site Kit's GA4 settings are stored.
	 */
	private static function get_sitekit_ga4_settings_option_name() {
		if ( class_exists( 'Google\Site_Kit\Modules\Analytics_4\Settings' ) ) {
			return Settings::OPTION;
		}
		return false;
	}

	/**
	 * Get Site Kit's GA4 settings.
	 */
	public static function get_sitekit_ga4_settings() {
		$option_name = self::get_sitekit_ga4_settings_option_name();
		if ( false === $option_name ) {
			return false;
		}
		return get_option( $option_name, false );
	}

	/**
	 * Fetch data for the GA account data and set up GA4.
	 */
	public static function setup_sitekit_ga4() {
		if ( ! self::is_active() ) {
			return;
		}
		require_once NEWSPACK_ABSPATH . 'includes/plugins/google-site-kit/class-googlesitekitanalytics.php';

		if ( ! self::is_user_connected() ) {
			return;
		}
		if ( get_option( self::GA4_SETUP_DONE_OPTION_NAME, false ) ) {
			return;
		}

		$sitekit_ga4_settings = self::get_sitekit_ga4_settings();
		if ( false !== $sitekit_ga4_settings && $sitekit_ga4_settings['useSnippet'] && isset( $sitekit_ga4_settings['measurementID'] ) ) {
			return;
		}

		if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) ) {
			return;
		}

		$sitekit_ga_settings = get_option( Settings::OPTION, false );
		if ( false === $sitekit_ga_settings || ! isset( $sitekit_ga_settings['accountID'] ) ) {
			return;
		}

		$account_id = $sitekit_ga_settings['accountID'];

		try {
			$newspack_ga  = new GoogleSiteKitAnalytics( new Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE ) );
			$ga4_settings = $newspack_ga->get_ga4_settings( $account_id );
			if ( false === $ga4_settings ) {
				return;
			}
			$ga4_settings['ownerID']    = get_current_user_id();
			$ga4_settings['useSnippet'] = true;

			$sitekit_ga4_option_name = self::get_sitekit_ga4_settings_option_name();
			Logger::log( 'Updating Site Kit GA4 settings option.' );
			update_option( self::GA4_SETUP_DONE_OPTION_NAME, true, true );
			update_option( $sitekit_ga4_option_name, $ga4_settings, true );
		} catch ( \Throwable $e ) {
			Logger::error( 'Failed updating Site Kit GA4 settings option: ' . $e->getMessage() );
		}
	}

	/**
	 * Extracts the Session ID from the _ga_{container} cookie
	 *
	 * If the cookie is not found, it will be created
	 *
	 * @return ?string
	 */
	private static function extract_sid_from_cookies() {
		foreach ( $_COOKIE as $key => $value ) { //phpcs:ignore
			if ( strpos( $key, '_ga_' ) === 0 && strpos( $value, 'GS1.' ) === 0 ) {
				$cookie_pieces = explode( '.', $value );
				if ( ! empty( $cookie_pieces[2] ) ) {
					return $cookie_pieces[2];
				}
			}
		}
	}

	/**
	 * Get custom parameters for a GA configuration or event body.
	 *
	 * @return array
	 */
	public static function get_custom_event_parameters() {
		$params = [
			'logged_in' => is_user_logged_in() ? 'yes' : 'no',
		];

		$categories = [];

		// Single post params.
		if ( is_singular() ) {
			// Post ID.
			$params['post_id'] = get_the_ID();
			$categories        = get_the_category();

			// Get current post author name.
			$author_name = '';
			if ( function_exists( 'get_coauthors' ) ) {
				$author_name = implode(
					', ',
					array_map(
						function( $author ) {
							return $author->display_name;
						},
						get_coauthors()
					)
				);
			} else {
				$post = get_post();
				if ( null !== $post && is_numeric( $post->post_author ) ) {
					// For some reason, get_the_author() does not work here.
					$author_user = get_user_by( 'ID', $post->post_author );
					if ( $author_user ) {
						$author_name = $author_user->display_name;
					}
				}
			}
			if ( ! empty( $author_name ) ) {
				$params['author'] = $author_name;
			}
		}

		// Get current post or archive categories.
		$category_names = array_map(
			function( $category ) {
				return $category->name;
			},
			is_category() ? [ get_queried_object() ] : $categories
		);
		if ( ! empty( $category_names ) ) {
			$params['categories'] = implode( ', ', $category_names );
		}

		$current_user = wp_get_current_user();
		$is_logged_in = 0 < $current_user->ID;
		$params['is_reader'] = $is_logged_in && Reader_Activation::is_user_reader( $current_user ) ? 'yes' : 'no';
		if ( ! empty( $current_user->user_email ) ) {
			$params['email_hash'] = md5( $current_user->user_email );
		}

		$reader_data = method_exists( 'Newspack\Reader_Data', 'get_data' ) ? Reader_Data::get_data( $current_user->ID ) : [];

		// If the reader is signed up for any newsletters.
		$params['is_newsletter_subscriber'] = empty( $reader_data['is_newsletter_subscriber'] ) ? 'no' : 'yes';
		// If reader has donated.
		$params['is_donor'] = empty( $reader_data['is_donor'] ) ? 'no' : 'yes';
		// If reader has any currently active non-donation subscriptions.
		$params['is_subscriber'] = empty( $reader_data['active_subscriptions'] ) ? 'no' : 'yes';

		// Content access groups: anonymized identifiers for the user's active group
		// subscriptions and matching institutions. See get_user_group_labels() for
		// why we send IDs to GA4 rather than the human-readable names.
		if ( Content_Gate::is_newspack_feature_enabled() ) {
			$group_labels    = self::get_user_group_labels( $current_user );
			$params['group'] = empty( $group_labels ) ? 'none' : implode( ', ', $group_labels );
		}

		/**
		 * Filters the custom parameters passed to GA4.
		 *
		 * @param array $params Custom parameters sent to GA4.
		 */
		return apply_filters( 'newspack_ga4_custom_parameters', $params );
	}

	/**
	 * Build the GA4 `group` parameter value for a user.
	 *
	 * The value is a sorted, comma-delimited list of anonymized identifiers for
	 * active group subscriptions the user owns or is a member of, plus institutions
	 * whose rules the user matches via any means.
	 *
	 * We emit anonymized IDs (`Group {sub_id}`, `Institution {inst_id}`) rather than
	 * publisher-facing display names because the unnamed-group fallback in
	 * `Group_Subscription_Settings` synthesizes a name from the owner's billing full
	 * name — sending that to GA4 would leak PII for every member of an unnamed group.
	 * The ESP path keeps the human-readable names; only the GA4 surface is anonymized.
	 *
	 * Both lookups are delegated to per-request-memoized helpers in `Group_Subscription`
	 * and `Institution`, so repeat calls within the same request are cheap. Memoization
	 * is deliberately request-scoped because the institution branch is per-visitor.
	 *
	 * @param \WP_User $user The user to inspect.
	 * @return string[] Sorted, deduplicated anonymized labels.
	 */
	private static function get_user_group_labels( $user ) {
		if ( ! $user || ! $user->ID ) {
			return [];
		}
		// Match the framing of the surrounding params (`is_reader`, `is_subscriber`):
		// only attribute groups to actual readers, not admins/editors.
		if ( ! Reader_Activation::is_user_reader( $user ) ) {
			return [];
		}
		$user_id = (int) $user->ID;

		$labels = [];
		foreach ( Group_Subscription::get_group_ids_for_user( $user_id ) as $sub_id ) {
			$labels[] = 'Group ' . $sub_id;
		}
		foreach ( Institution::get_matching_ids_for_user( $user_id ) as $inst_id ) {
			$labels[] = 'Institution ' . $inst_id;
		}
		sort( $labels, SORT_NATURAL | SORT_FLAG_CASE );
		return $labels;
	}

	/**
	 * Filter the GA config to add custom parameters.
	 *
	 * @param array $gtag_opt gtag config options.
	 */
	public static function add_ga_custom_parameters( $gtag_opt ) {
		// Set transport type to 'beacon' to allow async requests to complete after a new page is loaded.
		$gtag_opt['transport_type'] = 'beacon';

		/**
		 * Disables custom Google Analytics parameters on the frontend.
		 * Custom tracking parameters are enabled by default.
		 *
		 * @constant NEWSPACK_GA_DISABLE_CUSTOM_FE_PARAMS
		 * @type     bool
		 * @default  Custom frontend GA parameters enabled
		 *
		 * @example define( 'NEWSPACK_GA_DISABLE_CUSTOM_FE_PARAMS', true );
		 */
		if ( defined( 'NEWSPACK_GA_DISABLE_CUSTOM_FE_PARAMS' ) && NEWSPACK_GA_DISABLE_CUSTOM_FE_PARAMS ) {
			return $gtag_opt;
		}
		$custom_params = self::get_custom_event_parameters();
		return array_merge( $custom_params, $gtag_opt );
	}
}
GoogleSiteKit::init();
