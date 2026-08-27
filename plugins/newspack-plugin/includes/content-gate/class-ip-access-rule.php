<?php
/**
 * Content Gate IP Access Rule.
 *
 * @package Newspack
 */

namespace Newspack\Content_Gate;

use Newspack\Newspack_UI;

/**
 * IP Access Rule class.
 */
class IP_Access_Rule {

	/**
	 * Cookie used to trigger a cache-exempt render for IP-based access checking.
	 *
	 * The `wp` 2-char prefix causes Batcache's advanced-cache.php to skip
	 * page cache for requests carrying this cookie (rule: any cookie whose
	 * name starts with `wp` is exempted, except the small allowlist that
	 * defaults to `wordpress_test_cookie`). The cookie is a cache-skip
	 * signal only — the actual IP-match check runs server-side on the
	 * resulting uncached request, so a forged cookie just produces an
	 * uncached render where the check rejects the visitor.
	 *
	 * NOT renamed in this PR: unlike the newsletter cookies (which are new),
	 * this cookie may already be set on production sites; renaming would
	 * invalidate in-flight cookies for current visitors. See miguelpeixe
	 * review on PR #136.
	 *
	 * See: https://github.com/Automattic/batcache/blob/master/advanced-cache.php
	 */
	const COOKIE_NAME = 'wp_nocache_ip';

	/**
	 * Lifetime of the IP-access bypass cookie.
	 *
	 * This is the effective re-validation interval for anonymous institutional
	 * visitors: without the cookie, gated pages are served from the page cache
	 * and the per-request IP check cannot run, so once it expires the visitor
	 * is walled out until they re-visit /institutional-access. Keep it
	 * long-lived — the cookie grants nothing by itself (the visitor's IP is
	 * re-checked server-side on every uncached request), so a long lifetime
	 * carries no access risk.
	 */
	const COOKIE_EXPIRATION = YEAR_IN_SECONDS;

	/**
	 * The endpoint for institutional access.
	 */
	const ENDPOINT = 'institutional-access';

	/**
	 * The query parameter name for the IP check result.
	 */
	const RESULT_PARAM = 'institutional-access-result';

	/**
	 * The REST API route for the IP check.
	 */
	const REST_ROUTE = '/institutional-access/check';

	/**
	 * The REST API route for the institutional IP allowlist.
	 */
	const REST_ROUTE_IP_ALLOWLIST = '/institutional-access/ip-allowlist';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'add_rewrite_rule' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_route' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_redirect' ] );
		// After third-party tag registration: Site Kit modules register their
		// GA4/GTM snippet printing on template_redirect at priority 10, and the
		// landing page renders and exits — at the default priority the
		// publisher's analytics tag is never registered for it and the page
		// sends no pageview. Only the rendering branch runs late; the
		// query-param redirect in handle_redirect() emits no HTML and stays at
		// the default priority so other redirect handlers can't pre-empt it.
		add_action( 'template_redirect', [ __CLASS__, 'handle_landing_page' ], 100 );
		add_action( 'template_redirect', [ __CLASS__, 'handle_result_notice' ] );
	}

	/**
	 * Register the rewrite rule for the institutional access endpoint.
	 */
	public static function add_rewrite_rule() {
		// Match /institutional-access/<slug>/ for institution-specific pages.
		add_rewrite_rule(
			'^' . self::ENDPOINT . '/([^/]+)/?$',
			'index.php?' . self::ENDPOINT . '=1&' . self::ENDPOINT . '-slug=$matches[1]',
			'top'
		);
		// Match /institutional-access/ for the generic page.
		add_rewrite_rule( '^' . self::ENDPOINT . '/?$', 'index.php?' . self::ENDPOINT . '=1', 'top' );
		add_rewrite_tag( '%' . self::ENDPOINT . '%', '1' );
		add_rewrite_tag( '%' . self::ENDPOINT . '-slug%', '([^/]+)' );

		$option_key = 'newspack_ip_access_rule_flushed_v2';
		if ( ! get_option( $option_key ) ) {
			flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
			update_option( $option_key, true );
		}
	}

	/**
	 * Register the REST API routes for IP checking.
	 */
	public static function register_rest_route() {
		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'check_ip_rest' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'institution_id' => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'check_external_ip_rest' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
					'args'                => [
						'ip' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		\register_rest_route(
			NEWSPACK_API_NAMESPACE,
			self::REST_ROUTE_IP_ALLOWLIST,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'get_ip_allowlist_rest' ],
					'permission_callback' => [ __CLASS__, 'api_permissions_check' ],
				],
				'schema' => [ __CLASS__, 'get_ip_allowlist_schema' ],
			]
		);
	}

	/**
	 * REST API callback: check the visitor's IP and set the cookie if valid.
	 *
	 * When `institution_id` is provided, checks against that specific institution
	 * using all its rules (IP, email domain, reader data). Otherwise, checks
	 * against all institutions via the `newspack_content_gate_check_ip` filter.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function check_ip_rest( $request ) {
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		nocache_headers();

		$institution_id = $request->get_param( 'institution_id' );
		$valid          = false;
		$inst_name      = '';
		$matched_id     = 0;

		if ( $institution_id ) {
			$institutions = \Newspack\Institution::get_cached_institutions();
			if ( isset( $institutions[ $institution_id ] ) ) {
				$user_id  = get_current_user_id();
				$valid    = \Newspack\Institution::user_matches_institution( $user_id, $institutions[ $institution_id ], true );
				$inst_name = get_the_title( $institution_id );
				$matched_id = (int) $institution_id;
			}
		} else {
			/** This filter is documented in handle_redirect(). */
			$result = apply_filters( 'newspack_content_gate_check_ip', false );
			$valid  = (bool) $result;
			if ( is_int( $result ) ) {
				$inst_name  = get_the_title( $result );
				$matched_id = $result;
			}
		}

		if ( $valid ) {
			self::set_cookie();
		}

		$data = [ 'valid' => $valid ];
		// Only disclose the institution name to a visitor who actually matched it;
		// otherwise an unauthenticated caller could enumerate every institution's
		// name by iterating institution_id. The ID gets the same treatment for
		// symmetry, and lets the destination page label its GA4 event.
		if ( $valid && $inst_name ) {
			$data['institution'] = $inst_name;
		}
		if ( $valid && $matched_id ) {
			$data['institution_id'] = $matched_id;
		}

		return new \WP_REST_Response( $data );
	}

	/**
	 * REST API callback for external IP queries via POST.
	 *
	 * Accepts a JSON body with an `ip` field and checks it against all
	 * institutional IP ranges. Designed for server-to-server calls from
	 * external platforms.
	 *
	 * Example request:
	 *
	 *     POST /wp-json/newspack/v1/institutional-access/check
	 *     Content-Type: application/json
	 *
	 *     {"ip": "127.0.0.1"}
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function check_external_ip_rest( $request ) {
		$ip = $request->get_param( 'ip' );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				'Only IPv4 addresses are supported.',
				[ 'status' => 400 ]
			);
		}

		$override = fn() => $ip;
		add_filter( 'newspack_visitor_ip', $override );

		/** This filter is documented in self::handle_redirect(). */
		$result = apply_filters( 'newspack_content_gate_check_ip', false );

		remove_filter( 'newspack_visitor_ip', $override );

		return new \WP_REST_Response( [ 'show_paywall' => ! (bool) $result ] );
	}

	/**
	 * Permission check for admin-gated REST routes in this class.
	 *
	 * @return bool
	 */
	public static function api_permissions_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * REST API callback for the institutional IP allowlist.
	 *
	 * Returns one entry per institution that has at least one valid IPv4
	 * address, CIDR block, or dash range. Malformed entries are dropped
	 * silently. Email-domain and reader-data rules are not exposed.
	 *
	 * `ip_ranges` is a plain `string[]` carrying three notations with no
	 * discriminator field: `10.0.0.5`, `10.0.0.0/24` and `10.0.0.1-10.0.0.9`.
	 * A consumer that only understands the first two ignores dash entries,
	 * which fails closed (the reader sees the gate). A site running such a
	 * consumer can rewrite or drop entries through the
	 * `newspack_content_gate_ip_allowlist` filter below — e.g. expanding dash
	 * ranges into CIDR blocks — without restricting what admins may type.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_ip_allowlist_rest() {
		$cached = \Newspack\Institution::get_cached_institutions();
		ksort( $cached );
		$institutions = [];
		foreach ( $cached as $post_id => $rules ) {
			$ip_ranges = self::parse_ip_ranges( $rules['ip_range'] );
			if ( empty( $ip_ranges ) ) {
				continue;
			}
			$institutions[] = [
				'id'        => (int) $post_id,
				'name'      => get_the_title( $post_id ),
				'ip_ranges' => $ip_ranges,
			];
		}

		/**
		 * Filter the institutional IP allowlist response.
		 *
		 * @param array[] $institutions List of entries: `[ 'id' => int, 'name' => string, 'ip_ranges' => string[] ]`.
		 */
		$institutions = apply_filters( 'newspack_content_gate_ip_allowlist', $institutions );

		return new \WP_REST_Response( $institutions );
	}

	/**
	 * Schema for a single IP allowlist entry.
	 *
	 * @return array
	 */
	public static function get_ip_allowlist_schema() {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'institutional-ip-allowlist-entry',
			'type'       => 'object',
			'properties' => [
				'id'        => [
					'description' => __( 'Institution post ID.', 'newspack-plugin' ),
					'type'        => 'integer',
					'readonly'    => true,
				],
				'name'      => [
					'description' => __( 'Institution name.', 'newspack-plugin' ),
					'type'        => 'string',
					'readonly'    => true,
				],
				'ip_ranges' => [
					'description' => __( 'Validated IPv4 addresses, CIDR blocks, or dash ranges granting access.', 'newspack-plugin' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'readonly'    => true,
				],
			],
		];
	}

	/**
	 * Handle the query-param institutional access check.
	 *
	 * For `?institutional-access=1` or `?institutional-access` on any URL:
	 * performs the IP check server-side, then redirects back to the same URL
	 * with a result parameter. The dedicated `/institutional-access` endpoint
	 * is handled by handle_landing_page() instead.
	 */
	public static function handle_redirect() {
		if ( ! get_query_var( self::ENDPOINT ) && ! isset( $_GET[ self::ENDPOINT ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( self::is_landing_page_request() ) {
			return;
		}

		// Never cache this response.
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		nocache_headers();

		/**
		 * Filter whether the current IP is valid for content gate access.
		 *
		 * @param bool|int $valid_ip Whether the IP is valid, or institution post ID. Default false.
		 */
		$result = apply_filters( 'newspack_content_gate_check_ip', false );

		if ( $result ) {
			self::set_cookie();
		}

		wp_safe_redirect( self::get_result_redirect_url( $result ) );
		exit;
	}

	/**
	 * Render the loading page on the dedicated `/institutional-access` endpoint.
	 *
	 * Hooked late on template_redirect (see init()) so third-party analytics
	 * tags are registered before the page renders and exits.
	 */
	public static function handle_landing_page() {
		if ( ! self::is_landing_page_request() ) {
			return;
		}

		// Never cache this page.
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		nocache_headers();

		$institution_id = null;
		$slug           = get_query_var( self::ENDPOINT . '-slug' );
		if ( $slug ) {
			$posts = get_posts(
				[
					'post_type'      => \Newspack\Institution::POST_TYPE,
					'name'           => sanitize_title( $slug ),
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
				]
			);
			$institution_id = ! empty( $posts ) ? $posts[0] : null;
		}
		self::render_loading_page( $institution_id );
		exit;
	}

	/**
	 * Whether the current request is for the dedicated landing page — the
	 * rendered loading page — as opposed to the query-param flow on a regular
	 * URL, which redirects without rendering anything. Public because the
	 * Perfmatters integration vetoes JS delay on this request.
	 *
	 * @return bool
	 */
	public static function is_landing_page_request() {
		// On the dedicated endpoint the query var comes from the rewrite rule;
		// the query-param flow sets it through $_GET (which WP mirrors into the
		// query var). Query var without the GET param therefore identifies the
		// rendered landing page at any install depth, with no path parsing —
		// a path check would misclassify subdirectory installs.
		return (bool) get_query_var( self::ENDPOINT ) && ! isset( $_GET[ self::ENDPOINT ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Build the query-param flow's post-check redirect URL: the current URL
	 * without the endpoint param, carrying the result and, on a match, the
	 * institution's name (for the snackbar) and ID (for the GA4 event).
	 *
	 * @param bool|int $result IP check result: institution post ID on a match, false otherwise.
	 *
	 * @return string The redirect URL.
	 */
	private static function get_result_redirect_url( $result ) {
		$redirect_url = self::get_redirect_url();
		if ( is_int( $result ) ) {
			$redirect_url = add_query_arg(
				[
					'institution'    => rawurlencode( get_the_title( $result ) ),
					'institution-id' => $result,
				],
				$redirect_url
			);
		} else {
			// get_redirect_url() carries over the current query string, so a
			// failed re-check must not inherit a previous success's institution
			// params — the destination page would label its not_verified event
			// with an institution the visitor did not match.
			$redirect_url = remove_query_arg( [ 'institution', 'institution-id' ], $redirect_url );
		}
		return add_query_arg( self::RESULT_PARAM, $result ? 'success' : 'failure', $redirect_url );
	}

	/**
	 * Display a snackbar notice based on the IP check result parameter.
	 */
	public static function handle_result_notice() {
		if ( empty( $_GET[ self::RESULT_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Prevent this response from being cached so other users don't see the snackbar.
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		nocache_headers();

		$result = sanitize_text_field( wp_unslash( $_GET[ self::RESULT_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'success' === $result ) {
			$institution = ! empty( $_GET['institution'] ) ? sanitize_text_field( wp_unslash( $_GET['institution'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message     = $institution
				/* translators: %s: institution name */
				? sprintf( __( 'Connected to %s.', 'newspack-plugin' ), '<strong>' . esc_html( $institution ) . '</strong>' )
				: __( 'Connected to your organization.', 'newspack-plugin' );
			Newspack_UI::add_notice(
				$message,
				[
					'autohide' => true,
				]
			);
		} elseif ( 'failure' === $result ) {
			Newspack_UI::add_notice(
				__( "We couldn't verify your location. Make sure you're on your organization's network and try again.", 'newspack-plugin' ),
				[
					'type'     => 'error',
					'autohide' => false,
				]
			);
		}

		if ( in_array( $result, [ 'success', 'failure' ], true ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'print_result_event' ] );
		}
	}

	/**
	 * Print the GA4 event for a redirect-borne IP-check outcome.
	 *
	 * Fires np_institutional_access on the page the check redirected back to —
	 * for both the query-param flow and the dedicated landing page's success
	 * path. The landing page never fires `connected` itself, so a success is
	 * counted exactly once. gtag is polled for because Perfmatters' JS delay
	 * releases analytics scripts only on first user interaction, which typically
	 * happens after this script runs.
	 */
	public static function print_result_event() {
		$result = isset( $_GET[ self::RESULT_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::RESULT_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $result, [ 'success', 'failure' ], true ) ) {
			return;
		}

		// Both legitimate flows set the bypass cookie before redirecting here,
		// so a success param without it is a shared or hand-crafted URL — it
		// must not inject a `connected` event into the publisher's data.
		if ( 'success' === $result && ! self::is_cookie_set() ) {
			return;
		}

		$payload = [
			'action' => 'success' === $result ? 'connected' : 'not_verified',
		];
		// The event param uses the same anonymized identifier as the GA4 `group`
		// dimension, not the institution's display name (which the URL still
		// carries for the snackbar).
		$institution_id = isset( $_GET['institution-id'] ) ? absint( $_GET['institution-id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $institution_id ) {
			$payload['institution'] = 'Institution ' . $institution_id;
		}
		?>
		<script>
		( function() {
			var payload = <?php echo wp_json_encode( $payload ); ?>;
			// Strip the result params so a reload, back-button return, or shared
			// URL doesn't fire another event (or re-exempt the page from cache).
			if ( window.history && window.history.replaceState && window.URL ) {
				var url = new URL( window.location.href );
				[ <?php echo wp_json_encode( self::RESULT_PARAM ); ?>, 'institution', 'institution-id' ].forEach( function( param ) {
					url.searchParams.delete( param );
				} );
				window.history.replaceState( window.history.state, '', url.toString() );
			}
			var tries = 0;
			( function send() {
				if ( 'function' === typeof window.gtag ) {
					window.gtag( 'event', 'np_institutional_access', payload );
					return;
				}
				if ( tries++ < 60 ) {
					setTimeout( send, 500 );
				}
			} )();
		} )();
		</script>
		<?php
	}

	/**
	 * Get the URL to redirect to after the IP check (for query param usage).
	 *
	 * Rebuilds the current URL without the institutional-access parameter.
	 *
	 * @return string The redirect URL.
	 */
	private static function get_redirect_url() {
		$request_path = wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$url          = home_url( $request_path );

		// Rebuild query string without the institutional-access param.
		$query = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $query[ self::ENDPOINT ] );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		return $url;
	}

	/**
	 * Get the URL to redirect to from the dedicated endpoint.
	 *
	 * Checks redirect_to param, then Referer header, then falls back to homepage.
	 * Returned host-relative (see get_check_url()) so the loading page's
	 * client-side redirect resolves against the document (proxy) origin. Under a
	 * rewriting proxy (e.g. a library EZproxy) an absolute URL would send the
	 * just-verified reader back to the canonical host — off the proxy, without
	 * the proxied IP — and re-lock the content. See NPPD-2039.
	 *
	 * @return string Host-relative redirect URL (path and query, without scheme or host).
	 */
	private static function get_dedicated_redirect_url() {
		$home = home_url( '/' );

		if ( ! empty( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
			$url = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
			if ( wp_validate_redirect( $url, $home ) !== $home || $url === $home ) {
				return wp_make_link_relative( $url );
			}
		}

		$referer = wp_get_referer();
		if ( $referer && wp_validate_redirect( $referer, $home ) !== $home ) {
			return wp_make_link_relative( $referer );
		}

		return wp_make_link_relative( $home );
	}

	/**
	 * Build the host-relative URL for the institutional-access check endpoint.
	 *
	 * The loading page's fetch must resolve against the document origin rather
	 * than an absolute canonical host. When the page is served through a
	 * rewriting reverse proxy (e.g. a library EZproxy), that origin is the
	 * proxy host, so a host-relative URL stays proxied and the origin sees the
	 * proxy's whitelisted IP. An absolute URL is left unrewritten inside the
	 * inline script, so the browser fetches it directly from the reader's real
	 * IP — bypassing the proxy and defeating institutional IP access. See NPPD-2039.
	 *
	 * @param int|null $institution_id Optional. Institution post ID to scope the check.
	 *
	 * @return string Host-relative REST URL (path and query, without scheme or host).
	 */
	private static function get_check_url( $institution_id = null ) {
		$url = rest_url( NEWSPACK_API_NAMESPACE . self::REST_ROUTE );
		if ( $institution_id ) {
			$url = add_query_arg( 'institution_id', $institution_id, $url );
		}
		return wp_make_link_relative( $url );
	}

	/**
	 * Render the loading page for access verification.
	 *
	 * Outputs a standalone HTML page with a loading spinner that performs
	 * the IP check via the REST API and redirects on completion.
	 *
	 * When an institution ID is provided, the page is personalized with
	 * the institution's name and featured image.
	 *
	 * @param int|null $institution_id Optional. Institution post ID for personalized check.
	 */
	public static function render_loading_page( $institution_id = null ) {
		$redirect_url = self::get_dedicated_redirect_url();
		$rest_url     = self::get_check_url( $institution_id );
		$result_param = self::RESULT_PARAM;
		$site_name    = get_bloginfo( 'name' );
		$timeout_ms   = 10000;

		// Institution personalization.
		$inst_name  = $institution_id ? get_the_title( $institution_id ) : '';
		$inst_image = $institution_id ? get_the_post_thumbnail_url( $institution_id, 'large' ) : '';
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex, nofollow">
			<title><?php echo esc_html( $inst_name ? $inst_name . ' — ' . $site_name : $site_name ); ?> — <?php esc_html_e( 'Verifying access', 'newspack-plugin' ); ?></title>
			<?php wp_head(); ?>
			<style>
				#ip-check #ip-check-actions { display: none; }
				.newspack-ui__ip-check--error .newspack-ui__spinner > span { display: none; }
				#ip-check.newspack-ui__ip-check--error #ip-check-actions { display: flex; }
				.newspack-ui__ip-check__image { max-width: 256px; max-height: 192px; object-fit: contain; }
			</style>
		</head>
		<body>
			<div class="newspack-ui" id="ip-check">
				<div class="newspack-ui__spinner">
					<?php if ( $inst_image ) : ?>
						<img class="newspack-ui__ip-check__image" src="<?php echo esc_url( $inst_image ); ?>" alt="<?php echo esc_attr( $inst_name ); ?>">
					<?php endif; ?>
					<span></span>
					<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--align-center newspack-ui__font--s">
						<p id="ip-check-message">
							<?php
							if ( $inst_name ) {
								/* translators: %s: institution name */
								printf( esc_html__( 'Verifying your access to %s…', 'newspack-plugin' ), '<strong>' . esc_html( $inst_name ) . '</strong>' );
							} else {
								esc_html_e( 'Verifying your access…', 'newspack-plugin' );
							}
							?>
						</p>
						<p class="newspack-ui__font--normal newspack-ui__color--neutral-60" id="ip-check-detail"><?php esc_html_e( "You'll be redirected in a few seconds.", 'newspack-plugin' ); ?></p>
					</div>
					<div class="newspack-ui__stack newspack-ui__stack--justify-center" id="ip-check-actions">
						<button class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--small" onclick="location.reload()"><?php esc_html_e( 'Try again', 'newspack-plugin' ); ?></button>
						<a class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small" href="<?php echo esc_url( $redirect_url ); ?>"><?php esc_html_e( 'Continue to site', 'newspack-plugin' ); ?></a>
					</div>
				</div>
			</div>
			<script>
			(function() {
				var container = document.getElementById( 'ip-check' );
				var messageEl = document.getElementById( 'ip-check-message' );
				var detailEl  = document.getElementById( 'ip-check-detail' );
				var redirectUrl = <?php echo wp_json_encode( $redirect_url ); ?>;
				var resultParam = <?php echo wp_json_encode( $result_param ); ?>;
				var institutionLabel = <?php echo wp_json_encode( $institution_id ? 'Institution ' . $institution_id : '' ); ?>;

				// Only the outcomes that never leave this page are reported here;
				// a success redirects, and the destination page fires `connected`.
				// gtag is polled for so a tag that loads after the outcome (JS
				// delay, slow network) still records it; the page stays open on
				// every outcome reported here, so late delivery is fine.
				function sendEvent( action ) {
					var payload = { action: action };
					if ( institutionLabel ) {
						payload.institution = institutionLabel;
					}
					var tries = 0;
					( function send() {
						if ( 'function' === typeof window.gtag ) {
							window.gtag( 'event', 'np_institutional_access', payload );
							return;
						}
						if ( tries++ < 60 ) {
							setTimeout( send, 500 );
						}
					} )();
				}

				var controller = new AbortController();
				var timer = setTimeout( function() {
					controller.abort();
					sendEvent( "timeout" );
					showError(
						<?php echo wp_json_encode( __( 'Verification timed out.', 'newspack-plugin' ) ); ?>,
						<?php echo wp_json_encode( __( 'Please check your connection and try again.', 'newspack-plugin' ) ); ?>
					);
				}, <?php echo (int) $timeout_ms; ?> );

				var minDelay = new Promise( function( resolve ) { setTimeout( resolve, 1000 ); } );

				Promise.all( [
					fetch( <?php echo wp_json_encode( $rest_url ); ?>, {
						credentials: 'same-origin',
						signal: controller.signal
					} ).then( function( response ) { return response.json(); } ),
					minDelay
				] )
				.then( function( results ) { var data = results[0];
					clearTimeout( timer );
					if ( data.valid ) {
						messageEl.textContent = data.institution
							? <?php echo wp_json_encode( __( 'Connected to ', 'newspack-plugin' ) ); ?> + data.institution + '.'
							: <?php echo wp_json_encode( __( 'Connected to your organization.', 'newspack-plugin' ) ); ?>;
						detailEl.textContent = <?php echo wp_json_encode( __( 'Redirecting…', 'newspack-plugin' ) ); ?>;
						setTimeout( function() {
							var url = new URL( redirectUrl, location.origin );
							url.searchParams.set( resultParam, 'success' );
							// redirectUrl comes from redirect_to/referer, so never
							// let institution params ride through from it — only
							// the REST response labels this verification.
							url.searchParams.delete( 'institution' );
							url.searchParams.delete( 'institution-id' );
							if ( data.institution ) {
								url.searchParams.set( 'institution', data.institution );
							}
							if ( data.institution_id ) {
								url.searchParams.set( 'institution-id', data.institution_id );
							}
							location.href = url.toString();
						}, 1500 );
					} else {
						sendEvent( "not_verified" );
						showError(
							<?php echo wp_json_encode( __( "We couldn't verify your location.", 'newspack-plugin' ) ); ?>,
							<?php echo wp_json_encode( __( "Make sure you're on your organization's network and try again.", 'newspack-plugin' ) ); ?>
						);
					}
				} )
				.catch( function( err ) {
					clearTimeout( timer );
					// An abort is the timeout firing: its event and message are
					// already handled above, and the generic copy must not
					// overwrite the timeout copy the visitor is reading.
					if ( err && 'AbortError' === err.name ) {
						return;
					}
					sendEvent( "error" );
					showError(
						<?php echo wp_json_encode( __( 'Verification failed.', 'newspack-plugin' ) ); ?>,
						<?php echo wp_json_encode( __( 'An error occurred. Please try again.', 'newspack-plugin' ) ); ?>
					);
				} );

				function showError( message, detail ) {
					container.classList.add( 'newspack-ui__ip-check--error' );
					messageEl.textContent = message;
					detailEl.textContent = detail;
				}
			})();
			</script>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Convert an IPv4 address to its unsigned 32-bit value.
	 *
	 * `ip2long()` returns a signed int, so on a 32-bit PHP build every address
	 * above 127.255.255.255 comes back negative and a straddling range like
	 * `10.0.0.0-200.0.0.0` would read as reversed. Formatting with `%u` yields
	 * the unsigned value on every platform.
	 *
	 * @param string $ip Validated IPv4 address.
	 *
	 * @return float Unsigned 32-bit value.
	 */
	private static function ip_to_unsigned( $ip ) {
		return (float) sprintf( '%u', ip2long( $ip ) );
	}

	/**
	 * Classify and normalize a single allowlist entry.
	 *
	 * The one place that decides what an institution IP entry is and how it is
	 * spelled. `/` is checked before `-`, so a token carrying both (e.g.
	 * `10.0.0.0/24-10.0.0.5`) reads as a malformed CIDR and is rejected rather
	 * than as a dash range. CIDR mask bits are canonicalized to their numeric
	 * value so a leading-zero spelling like `/00` cannot evade a downstream
	 * string-shape check while still matching numerically.
	 *
	 * @param string $entry A single entry (whitespace tolerated around the token
	 *                      and its `/` or `-` separator).
	 *
	 * @return array{type: string, value: string} `type` is 'ip', 'cidr',
	 *         'range', or 'invalid'; `value` is the normalized entry, or '' when
	 *         invalid.
	 */
	private static function parse_entry( $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry ) {
			return [
				'type'  => 'invalid',
				'value' => '',
			];
		}
		if ( false !== strpos( $entry, '/' ) ) {
			list( $subnet, $bits ) = explode( '/', $entry, 2 );
			$subnet                = trim( $subnet );
			$bits                  = trim( $bits );
			if ( ctype_digit( $bits ) && (int) $bits <= 32 && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return [
					'type'  => 'cidr',
					'value' => $subnet . '/' . (int) $bits,
				];
			}
			return [
				'type'  => 'invalid',
				'value' => '',
			];
		}
		if ( false !== strpos( $entry, '-' ) ) {
			list( $start, $end ) = explode( '-', $entry, 2 );
			$start               = trim( $start );
			$end                 = trim( $end );
			if (
				filter_var( $start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
				&& filter_var( $end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
				// A reversed range (end < start) is most likely a typo; treat it as invalid rather than silently swapping the bounds.
				&& self::ip_to_unsigned( $start ) <= self::ip_to_unsigned( $end )
			) {
				return [
					'type'  => 'range',
					'value' => $start . '-' . $end,
				];
			}
			return [
				'type'  => 'invalid',
				'value' => '',
			];
		}
		if ( filter_var( $entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return [
				'type'  => 'ip',
				'value' => $entry,
			];
		}
		return [
			'type'  => 'invalid',
			'value' => '',
		];
	}

	/**
	 * Classify a single allowlist entry.
	 *
	 * The public classifier the migration CLI delegates to, so the wizard, the
	 * runtime access check, and the migration cannot drift on what an entry is.
	 *
	 * @param string $entry A single entry.
	 *
	 * @return string One of 'ip', 'cidr', 'range', or 'invalid'.
	 */
	public static function classify_entry( $entry ) {
		return self::parse_entry( $entry )['type'];
	}

	/**
	 * Number of IPv4 addresses a single entry grants access to.
	 *
	 * Lets a caller judge breadth uniformly across all three shapes — a `/16`
	 * CIDR and the equivalent dash range report the same size. Returns a float
	 * because the whole IPv4 space (2**32) overflows a 32-bit int.
	 *
	 * @param string $entry A single entry.
	 *
	 * @return float Address count, or 0.0 for an invalid entry.
	 */
	public static function get_entry_size( $entry ) {
		$parsed = self::parse_entry( $entry );
		switch ( $parsed['type'] ) {
			case 'ip':
				return 1.0;
			case 'cidr':
				list( , $bits ) = explode( '/', $parsed['value'], 2 );
				return 2.0 ** ( 32 - (int) $bits );
			case 'range':
				list( $start, $end ) = explode( '-', $parsed['value'], 2 );
				return self::ip_to_unsigned( $end ) - self::ip_to_unsigned( $start ) + 1;
			default:
				return 0.0;
		}
	}

	/**
	 * Split and validate a raw allowlist value into valid and invalid entries.
	 *
	 * Splits on commas and newlines — accepting both the wizard's comma-separated
	 * string and an option map's array or multiline value — then classifies each
	 * entry. The canonical validator the migration CLI delegates to, so a value
	 * accepted at the runtime check is accepted at migration and vice versa.
	 *
	 * @param string|array $raw Raw allowlist value.
	 *
	 * @return array{valid: string[], invalid: string[]} Valid entries in their
	 *         normalized form; invalid entries in their trimmed original form.
	 */
	public static function normalize_ip_ranges( $raw ) {
		$tokens = [];
		foreach ( ( is_array( $raw ) ? $raw : [ $raw ] ) as $chunk ) {
			$tokens = array_merge( $tokens, preg_split( '/[,\n\r]+/', (string) $chunk ) );
		}
		$valid   = [];
		$invalid = [];
		foreach ( $tokens as $token ) {
			if ( '' === trim( $token ) ) {
				continue;
			}
			$parsed = self::parse_entry( $token );
			if ( 'invalid' === $parsed['type'] ) {
				$invalid[] = trim( $token );
			} else {
				$valid[] = $parsed['value'];
			}
		}
		return [
			'valid'   => array_values( $valid ),
			'invalid' => array_values( $invalid ),
		];
	}

	/**
	 * Parse a comma-separated list of IPs, CIDR blocks, and dash ranges.
	 *
	 * Trims whitespace (around tokens and around the `/` and `-` separators),
	 * drops empty tokens, and discards anything that isn't a valid IPv4
	 * address, CIDR block (`<ipv4>/<0-32>`), or dash range
	 * (`<ipv4>-<ipv4>` with start <= end). Entries are emitted in their
	 * trimmed form.
	 *
	 * A token carrying both separators (e.g. `10.0.0.0/24-10.0.0.5`) is read as
	 * a CIDR block and dropped: the `/` branch is checked first.
	 *
	 * @param string $raw Comma-separated list (e.g. `"192.168.1.0/24,10.0.0.5,203.0.113.0-203.0.113.255"`).
	 *
	 * @return string[] Validated entries.
	 */
	private static function parse_ip_ranges( $raw ) {
		if ( empty( $raw ) ) {
			return [];
		}
		return self::normalize_ip_ranges( $raw )['valid'];
	}

	/**
	 * Check if an IP address matches any of the given ranges.
	 *
	 * @param string $ip     The IP address to check.
	 * @param string $ranges Comma-separated list of IPs, CIDR blocks, and/or dash ranges.
	 *
	 * @return bool Whether the IP matches any range.
	 */
	public static function ip_matches_ranges( $ip, $ranges ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}
		$ip_long     = ip2long( $ip );
		$ip_unsigned = self::ip_to_unsigned( $ip );

		foreach ( self::parse_ip_ranges( $ranges ) as $range ) {
			if ( strpos( $range, '/' ) !== false ) {
				list( $subnet, $bits ) = explode( '/', $range, 2 );
				$subnet_long = ip2long( $subnet );
				$mask        = -1 << ( 32 - (int) $bits );
				if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
					return true;
				}
			} elseif ( strpos( $range, '-' ) !== false ) {
				list( $start, $end ) = explode( '-', $range, 2 );
				if ( self::ip_to_unsigned( $start ) <= $ip_unsigned && $ip_unsigned <= self::ip_to_unsigned( $end ) ) {
					return true;
				}
			} elseif ( $ip_long === ip2long( $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the visitor's IP address.
	 *
	 * By default only REMOTE_ADDR is trusted, because proxy headers like
	 * X-Forwarded-For and X-Real-IP can be set by the client and used to
	 * spoof an allowed IP for institutional access.
	 *
	 * To trust proxy headers (when the site sits behind a known reverse
	 * proxy), use the `newspack_trusted_proxy_headers` filter:
	 *
	 *     add_filter( 'newspack_trusted_proxy_headers', function () {
	 *         return [ 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ];
	 *     } );
	 *
	 * For full control over IP resolution use `newspack_visitor_ip`.
	 *
	 * @return string The visitor's IP address.
	 */
	public static function get_visitor_ip() {
		/**
		 * Filter the list of trusted proxy headers checked before REMOTE_ADDR.
		 *
		 * Return an array of `$_SERVER` keys (e.g. `HTTP_X_FORWARDED_FOR`,
		 * `HTTP_X_REAL_IP`) that your reverse-proxy infrastructure is known
		 * to set reliably. An empty array (the default) means only
		 * REMOTE_ADDR is used.
		 *
		 * @param string[] $headers Trusted header keys. Default empty array.
		 */
		$trusted_headers = apply_filters( 'newspack_trusted_proxy_headers', [] );

		// Always end with REMOTE_ADDR as the final fallback.
		$headers = array_merge( (array) $trusted_headers, [ 'REMOTE_ADDR' ] );

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0];
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					/**
					 * Filter the resolved visitor IP address.
					 *
					 * @param string $ip     Resolved IP address.
					 * @param string $header The $_SERVER key it was read from.
					 */
					return apply_filters( 'newspack_visitor_ip', $ip, $header );
				}
			}
		}
		return '';
	}

	/**
	 * Whether the IP-access bypass cookie is present on the current request.
	 *
	 * The cookie is a cache-skip signal set after a successful institutional-access
	 * verification. Its presence tells downstream code to run the IP check
	 * server-side rather than serving a cached response. The actual access
	 * decision is made by IP_Access_Rule::ip_matches_ranges(), not by this cookie.
	 *
	 * @return bool True if the bypass cookie is present on this request.
	 */
	public static function is_cookie_set() {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence-only check; the cookie is a cache-skip signal, not an auth grant.
		return ! empty( $_COOKIE[ self::COOKIE_NAME ] );
	}

	/**
	 * Set the IP-access bypass cookie.
	 *
	 * The cookie value is a simple sentinel ('1'). It signals that the visitor
	 * has previously passed the IP check and subsequent requests should skip
	 * the page cache so the IP check can run server-side.
	 */
	private static function set_cookie() {
		$expiry = time() + self::COOKIE_EXPIRATION;
		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie(
				self::COOKIE_NAME,
				'1',
				[
					'expires'  => $expiry,
					'path'     => COOKIEPATH,
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
		$_COOKIE[ self::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}
}
IP_Access_Rule::init();
