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
	 * Request-scoped memo of access source resolutions, keyed by post ID and
	 * user ID.
	 *
	 * The parameters are built at least twice per request — once for Site
	 * Kit's gtag config and once for the dataLayer mirror — and resolving
	 * the source re-runs every access rule on the post's gates. The rules
	 * themselves are not uniformly memoized (notably
	 * WooCommerce_Connection::get_active_subscriptions_for_user()), so without
	 * this a logged-in reader on a subscription gate would pay several full
	 * subscription loads at wp_head.
	 *
	 * Request-scoped on purpose, and keyed by user as well as post: a single
	 * CLI process may resolve for many readers.
	 *
	 * @var array<string,string>
	 */
	private static $access_source_memo = [];

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'setup_sitekit_ga4' ] );
		add_action( 'wp_footer', [ __CLASS__, 'insert_ga4_analytics' ] );
		add_filter( 'option_googlesitekit_analytics_settings', [ __CLASS__, 'filter_ga_settings' ] );
		add_filter( 'option_googlesitekit_analytics-4_settings', [ __CLASS__, 'filter_ga_settings' ] );
		add_filter( 'googlesitekit_gtag_opt', [ __CLASS__, 'add_ga_custom_parameters' ] );
		// Priority 1 so the values are in the dataLayer before Site Kit prints the
		// Tag Manager container snippet (registered on wp_head at the default priority).
		add_action( 'wp_head', [ __CLASS__, 'print_data_layer_params' ], 1 );
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
	 * Whether Newspack should turn on Site Kit's GA4 gtag snippet during GA4 setup.
	 *
	 * Newspack enables the gtag snippet so its reader custom dimensions ride along with the
	 * GA4 page_view. On a site that already tags GA4 through a Google Tag Manager container,
	 * the gtag is a second GA4 page_view feed (duplicate counting); reader params also reach
	 * GTM through the dataLayer (see print_data_layer_params), so the gtag is not required to
	 * deliver them there.
	 *
	 * Defaults to true (enable the snippet). WordPress cannot see whether a placed GTM
	 * container actually carries a GA4 tag, so leaving the gtag off by default would remove
	 * GA4 entirely from any site whose GTM does not carry it. Newspack Manager, which observes
	 * the real GA4 beacons, hooks the filter below to return false once it has confirmed a
	 * container is independently sending GA4 for the property.
	 *
	 * @param string $measurement_id The GA4 measurement ID being set up, if known.
	 * @return bool Whether to enable the GA4 gtag snippet.
	 */
	public static function should_force_ga4_snippet( string $measurement_id = '' ): bool {
		/**
		 * Filters whether Newspack turns on Site Kit's GA4 gtag snippet during GA4 setup.
		 *
		 * Return false to leave the gtag snippet off. Do so only when GA4 is known to be
		 * tagged through another source (e.g. a GTM container that carries GA4), otherwise
		 * the site will have no GA4 tag at all.
		 *
		 * @param bool   $force_snippet  Whether to enable the gtag snippet. Default true.
		 * @param string $measurement_id The GA4 measurement ID being set up, if known.
		 */
		return (bool) apply_filters( 'newspack_googlesitekit_force_ga4_snippet', true, $measurement_id );
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
			$ga4_settings['useSnippet'] = self::should_force_ga4_snippet( $ga4_settings['measurementID'] ?? '' );

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
	 * If you add, rename, or remove a key here, update the companion GTM template
	 * (Data Layer Variables + docs) at includes/plugins/google-site-kit/gtm-template/
	 * so GTM-tagged sites keep reading the same params.
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
		// Gating rather than the flag alone: with Audience Management off nothing is
		// access-controlled, so the dimension would report memberships that grant
		// nothing — and computing it costs a group-subscription lookup plus an IP-based
		// institution match on every pageview, including for anonymous readers.
		if ( Content_Gate::is_gating_active() ) {
			$group_labels    = self::get_user_group_labels( $current_user );
			$params['group'] = empty( $group_labels ) ? 'none' : implode( ', ', $group_labels );

			$access_source = self::get_request_access_source();
			if ( '' !== $access_source ) {
				$params['access_source'] = $access_source;
			}
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
		$labels = [];
		// Non-logged-in users can still match institutions via IP-based access rules.
		foreach ( Institution::get_matching_ids_for_user( $user->ID ?? 0 ) as $inst_id ) {
			$labels[] = 'Institution ' . $inst_id;
		}
		if ( ! $user || ! $user->ID ) {
			return $labels;
		}
		// Match the framing of the surrounding params (`is_reader`, `is_subscriber`):
		// only attribute groups to actual readers, not admins/editors.
		if ( ! Reader_Activation::is_user_reader( $user ) ) {
			return $labels;
		}
		$user_id = (int) $user->ID;
		foreach ( Group_Subscription::get_group_ids_for_user( $user_id ) as $sub_id ) {
			$labels[] = 'Group ' . $sub_id;
		}
		sort( $labels, SORT_NATURAL | SORT_FLAG_CASE );
		return $labels;
	}

	/**
	 * How the current reader got into the post being viewed.
	 *
	 * Answers the restriction outcome first and attributes second. Whether the
	 * reader is blocked is decided by Content_Gate::is_post_restricted(), the
	 * same filter the rendering path enforces — restriction is AND across every
	 * gate on the post, so a gate the reader passes says nothing about a second
	 * gate that still blocks them. Only once the reader is known to be through
	 * do the passing rules get mapped to a source label; a blocked reader is
	 * reported as blocked no matter what any individual gate would have granted.
	 *
	 * The two halves are scoped differently, on purpose. *Attribution* looks
	 * only at rules on gates with custom access active, mirroring how the ESP
	 * scopes the Content Access fields: reader account state is already
	 * reported by `is_reader` and `logged_in`, and naming a regwall pass as an
	 * access source would mean reimplementing verification logic that lives in
	 * Content_Restriction_Control. The *blocked* outcome reflects the whole
	 * restriction path, so `gated` and `metering_eligible` can originate from a
	 * registration wall or a Woo Memberships plan on a post that also carries a
	 * custom-access gate. That is the reader's experience either way — the post
	 * has a custom-access gate on it and they did not get in.
	 *
	 * Every call here is free of side effects. In particular it must never
	 * reach Metering::is_logged_in_metering_allowed(), which records a metered
	 * view as it answers. The `newspack_is_post_restricted` filter consulted
	 * here is a different hook from the `newspack_content_gate_restrict_post`
	 * one Metering registers against, and every callback on it is read-only.
	 *
	 * Memoized per post and reader for the life of the request; see
	 * $access_source_memo.
	 *
	 * @return string A vocabulary value, or '' to omit the parameter.
	 */
	public static function get_request_access_source() {
		if ( ! is_singular() ) {
			return 'no_custom_access_gate';
		}

		$post_id  = get_the_ID();
		$user_id  = get_current_user_id();
		$memo_key = $post_id . ':' . $user_id;
		if ( isset( self::$access_source_memo[ $memo_key ] ) ) {
			return self::$access_source_memo[ $memo_key ];
		}

		// A post the publisher exempted is never restricted, whatever its gates
		// say, so there is no gating to report and no point evaluating rules.
		// Checked here rather than left to is_post_restricted() below because
		// the gate walk in between would otherwise report an exempt post as
		// having a gate that applies to the reader.
		if ( $post_id && get_post_meta( $post_id, Content_Restriction_Control::IS_EXEMPT_META_KEY, true ) ) {
			return self::memo_access_source( $memo_key, 'no_custom_access_gate' );
		}

		$gates      = [];
		$unreadable = false;
		foreach ( (array) Content_Restriction_Control::get_post_gates( $post_id ) as $gate ) {
			if ( is_wp_error( $gate ) ) {
				$unreadable = true;
				continue;
			}
			if ( ! empty( $gate['custom_access']['active'] ) ) {
				$gates[] = $gate;
			}
		}
		if ( empty( $gates ) ) {
			// A gate we could not read is not the same as no gate. Omit the
			// parameter rather than assert a state that was never computed;
			// GA4's (not set) is the honest answer for "we don't know".
			// Not memoized: an unreadable gate is a transient condition, and
			// caching '' would freeze it for the rest of the request.
			return $unreadable ? '' : self::memo_access_source( $memo_key, 'no_custom_access_gate' );
		}

		// The single source of truth for "did this reader get in", and the same
		// one the rendering path enforces: AND across every gate on the post,
		// plus the verification walls and exemptions this class does not model.
		// A blocked reader is reported as blocked; no passing gate outranks it.
		if ( Content_Gate::is_post_restricted( $post_id ) ) {
			// Metering belongs to the gate that actually stopped this reader,
			// which is the one is_post_restricted() just recorded — not to any
			// gate on the post. A reader who passes a metering gate and is then
			// stopped by a hard one gets no free views, so reading the whole
			// list here would report a soft block that never happened. A
			// restriction with no recorded gate (a filter forcing the outcome)
			// falls through to the hard answer.
			$blocking_gate_id = Content_Gate::get_gate_post_id( $post_id );
			if ( $blocking_gate_id && Metering::offers_metering( $blocking_gate_id ) ) {
				return self::memo_access_source( $memo_key, 'metering_eligible' );
			}
			return self::memo_access_source( $memo_key, 'gated' );
		}

		$labels    = [];
		$has_rules = false;

		foreach ( $gates as $gate ) {
			$result = User_Gate_Access::evaluate_gate_for_user( $gate, $user_id );

			// A gate whose custom access is on but whose rule set is empty
			// restricts nobody, so it is not evidence of a gate having applied.
			if ( empty( $result['groups'] ) ) {
				continue;
			}
			$has_rules = true;

			if ( empty( $result['can_bypass'] ) ) {
				continue;
			}

			foreach ( $result['groups'] as $group ) {
				if ( empty( $group['passes'] ) ) {
					continue;
				}
				foreach ( $group['rules'] as $rule ) {
					if ( empty( $rule['passes'] ) ) {
						continue;
					}
					$labels = array_merge(
						$labels,
						Access_Attribution::get_source_labels( $rule['slug'], $rule['value'], $user_id, $result['context'] ?? [] )
					);
				}
			}
		}

		$primary = Access_Attribution::pick_primary( array_values( array_unique( $labels ) ) );
		if ( '' !== $primary ) {
			return self::memo_access_source( $memo_key, $primary );
		}
		if ( $has_rules ) {
			// The reader is through, but nothing here can say how: a rule slug
			// registered outside this vocabulary (Promoted_Fields turns every
			// access-rule ESP field into one), or a `newspack_is_post_restricted`
			// consumer that granted access without a rule passing at all. Omit
			// the parameter rather than name a source we did not observe; GA4's
			// (not set) is the honest answer. Not memoized, matching the
			// unreadable-gate case above.
			return '';
		}
		// Every gate's rule set was empty, so no gate ever applied to anybody.
		return $unreadable ? '' : self::memo_access_source( $memo_key, 'no_custom_access_gate' );
	}

	/**
	 * Store an access source resolution in the request memo and return it.
	 *
	 * @param string $memo_key Memo key, post ID and user ID.
	 * @param string $value    Resolved vocabulary value.
	 * @return string The value, unchanged.
	 */
	private static function memo_access_source( $memo_key, $value ) {
		self::$access_source_memo[ $memo_key ] = $value;
		return $value;
	}

	/**
	 * Clear the request-scoped access source memo.
	 *
	 * Used by tests and long-running CLI processes, where one PHP process can
	 * outlive the reader and post state the memo was built for.
	 *
	 * Also clears Access_Attribution's memo of the reader's owned
	 * subscriptions, which this resolver populates on its way to a product
	 * name. Clearing only this memo would re-evaluate the gates against a
	 * previous reader's subscriptions and attribute the wrong product.
	 */
	public static function reset_access_source_memo() {
		self::$access_source_memo = [];
		Access_Attribution::reset_memo();
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

	/**
	 * Push Newspack's GA4 custom parameters into the dataLayer on the front end.
	 *
	 * The `googlesitekit_gtag_opt` filter (see add_ga_custom_parameters) only reaches
	 * Site Kit's own gtag config. A publisher's Google Tag Manager container - which Site
	 * Kit can load via its Tag Manager module - fires its own GA4 tags that never see those
	 * params, so any custom dimension reported through GTM shows up as `(not set)`. Mirroring
	 * the same parameters into the dataLayer lets a publisher map them onto their GTM-managed
	 * GA4 tags as Data Layer Variables, keeping both tagging paths in sync.
	 *
	 * Hooked early on wp_head so the values are in the dataLayer before Site Kit's container
	 * snippet enqueues gtm.js. Emitted only when Site Kit is active and has a GA4 property
	 * configured, and unless custom frontend params are disabled.
	 */
	public static function print_data_layer_params() {
		if ( ! self::is_active() ) {
			return;
		}
		// Only emit the push when Site Kit has a GA4 property configured. Gate on the measurement
		// ID, not on useSnippet: a GTM-tagged site routes GA4 through its container with the gtag
		// snippet off, and still needs these params mirrored into the dataLayer.
		$sitekit_ga4_settings = self::get_sitekit_ga4_settings();
		if ( false === $sitekit_ga4_settings || empty( $sitekit_ga4_settings['measurementID'] ) ) {
			return;
		}
		// Arbitrary inline scripts are invalid on AMP pages and break AMP validation.
		if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
			return;
		}
		if ( defined( 'NEWSPACK_GA_DISABLE_CUSTOM_FE_PARAMS' ) && NEWSPACK_GA_DISABLE_CUSTOM_FE_PARAMS ) {
			return;
		}
		$script = self::get_data_layer_inline_script( self::get_data_layer_params() );
		if ( '' === $script ) {
			return;
		}
		wp_print_inline_script_tag( $script );
	}

	/**
	 * The reader/content parameters to mirror into the dataLayer for Google Tag Manager.
	 *
	 * Starts from the same set sent to Site Kit's gtag config, but drops `email_hash`:
	 * the hashed email is only needed by Site Kit's own gtag config (which still receives
	 * it), and pushing it to the dataLayer would expose it to every tag in the publisher's
	 * GTM container, including third-party ones.
	 *
	 * @return array Parameters to push to window.dataLayer.
	 */
	public static function get_data_layer_params() {
		/**
		 * Filters the Newspack parameters pushed to the dataLayer for Google Tag Manager.
		 *
		 * Mirrors the `newspack_ga4_custom_parameters` set sent to Site Kit's gtag config.
		 * Note that `email_hash` is always stripped afterwards (see below) and cannot be
		 * re-added through this filter.
		 *
		 * @param array $params Parameters pushed to window.dataLayer.
		 */
		$params = apply_filters( 'newspack_ga4_data_layer_params', self::get_custom_event_parameters() );

		// Always keep the hashed email out of the dataLayer - enforced after the filter so it
		// cannot be re-added. It is only needed by Site Kit's own gtag config (which still
		// receives it) and must not reach the third-party tags in a publisher's GTM container.
		unset( $params['email_hash'] );

		return $params;
	}

	/**
	 * Build the inline script that pushes the given parameters into the dataLayer.
	 *
	 * Extracted from print_data_layer_params() so the encoding can be unit-tested without a
	 * Site Kit runtime. Values are encoded with JSON_HEX_TAG|JSON_HEX_AMP so a parameter
	 * containing `</script>` (e.g. an author name or category) cannot break out of the tag.
	 *
	 * @param array $params Parameters to push (the GA4 custom event parameters).
	 * @return string Inline JS, or '' when there is nothing to push.
	 */
	public static function get_data_layer_inline_script( array $params ) {
		if ( empty( $params ) ) {
			return '';
		}
		return sprintf(
			'window.dataLayer = window.dataLayer || []; window.dataLayer.push( %s );',
			wp_json_encode( $params, JSON_HEX_TAG | JSON_HEX_AMP )
		);
	}
}
GoogleSiteKit::init();
