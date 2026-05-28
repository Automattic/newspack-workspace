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
	 * The name of the cookie used to bypass cache and allow server side IP checking.
	 */
	const COOKIE_NAME = 'wp_nocache_ip';

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

		if ( $institution_id ) {
			$institutions = \Newspack\Institution::get_cached_institutions();
			if ( isset( $institutions[ $institution_id ] ) ) {
				$user_id  = get_current_user_id();
				$valid    = \Newspack\Institution::user_matches_institution( $user_id, $institutions[ $institution_id ], true );
				$inst_name = get_the_title( $institution_id );
			}
		} else {
			/** This filter is documented in handle_redirect(). */
			$result = apply_filters( 'newspack_content_gate_check_ip', false );
			$valid  = (bool) $result;
			if ( is_int( $result ) ) {
				$inst_name = get_the_title( $result );
			}
		}

		if ( $valid ) {
			setcookie( self::COOKIE_NAME, '1', time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN ); // phpcs:ignore
		}

		$data = [ 'valid' => $valid ];
		if ( $inst_name ) {
			$data['institution'] = $inst_name;
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
	 * Returns one entry per institution that has at least one valid IPv4 or
	 * CIDR range. Malformed entries are dropped silently. Email-domain and
	 * reader-data rules are not exposed.
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
					'description' => __( 'Validated IPv4 addresses or CIDR blocks granting access.', 'newspack-plugin' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'readonly'    => true,
				],
			],
		];
	}

	/**
	 * Handle the institutional access check.
	 *
	 * For `?institutional-access=1` or `?institutional-access` on any URL:
	 * performs the IP check server-side, then redirects back to the same URL
	 * with a result parameter.
	 *
	 * For the dedicated `/institutional-access` endpoint: renders a loading page
	 * that performs the check via the REST API and redirects on completion.
	 */
	public static function handle_redirect() {
		if ( ! get_query_var( self::ENDPOINT ) && ! isset( $_GET[ self::ENDPOINT ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Never cache this page.
		if ( function_exists( 'batcache_cancel' ) ) {
			batcache_cancel();
		}
		nocache_headers();

		// Check if this is the dedicated endpoint or a query param on a regular URL.
		$slug = get_query_var( self::ENDPOINT . '-slug' );
		$request_path = wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$is_dedicated = $slug || (bool) preg_match( '#^/?' . preg_quote( self::ENDPOINT, '#' ) . '/?$#', trim( $request_path, '/' ) );

		if ( $is_dedicated ) {
			$institution_id = null;
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

		// Query param on a regular URL: server-side check and redirect.
		/**
		 * Filter whether the current IP is valid for content gate access.
		 *
		 * @param bool|int $valid_ip Whether the IP is valid, or institution post ID. Default false.
		 */
		$result = apply_filters( 'newspack_content_gate_check_ip', false );

		if ( $result ) {
			setcookie( self::COOKIE_NAME, '1', time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN ); // phpcs:ignore
		}

		$redirect_url = self::get_redirect_url();
		$redirect_url = add_query_arg( self::RESULT_PARAM, $result ? 'success' : 'failure', $redirect_url );
		if ( is_int( $result ) ) {
			$redirect_url = add_query_arg( 'institution', rawurlencode( get_the_title( $result ) ), $redirect_url );
		}
		wp_safe_redirect( $redirect_url );
		exit;
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
					'type'     => 'success',
					'autohide' => true,
				]
			);
		} elseif ( 'failure' === $result ) {
			Newspack_UI::add_notice(
				__( "We couldn't verify your location. Make sure you're on your organization's network and try again.", 'newspack-plugin' ),
				[
					'type'     => 'warning',
					'autohide' => false,
				]
			);
		}
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
	 *
	 * @return string The redirect URL.
	 */
	private static function get_dedicated_redirect_url() {
		$home = home_url( '/' );

		if ( ! empty( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
			$url = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
			if ( wp_validate_redirect( $url, $home ) !== $home || $url === $home ) {
				return $url;
			}
		}

		$referer = wp_get_referer();
		if ( $referer && wp_validate_redirect( $referer, $home ) !== $home ) {
			return $referer;
		}

		return $home;
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
		$rest_url     = rest_url( NEWSPACK_API_NAMESPACE . self::REST_ROUTE );
		if ( $institution_id ) {
			$rest_url = add_query_arg( 'institution_id', $institution_id, $rest_url );
		}
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

				var controller = new AbortController();
				var timer = setTimeout( function() {
					controller.abort();
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
							if ( data.institution ) {
								url.searchParams.set( 'institution', data.institution );
							}
							location.href = url.toString();
						}, 1500 );
					} else {
						showError(
							<?php echo wp_json_encode( __( "We couldn't verify your location.", 'newspack-plugin' ) ); ?>,
							<?php echo wp_json_encode( __( "Make sure you're on your organization's network and try again.", 'newspack-plugin' ) ); ?>
						);
					}
				} )
				.catch( function() {
					clearTimeout( timer );
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
	 * Parse a comma-separated list of IPs and CIDR blocks.
	 *
	 * Trims whitespace (around tokens and around the `/` separator), drops
	 * empty tokens, and discards anything that isn't a valid IPv4 address or
	 * CIDR block (`<ipv4>/<0-32>`). Returned CIDR entries are emitted in their
	 * trimmed form.
	 *
	 * @param string $raw Comma-separated list (e.g. `"192.168.1.0/24,10.0.0.5"`).
	 *
	 * @return string[] Validated entries.
	 */
	private static function parse_ip_ranges( $raw ) {
		if ( empty( $raw ) ) {
			return [];
		}
		$tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$valid  = [];
		foreach ( $tokens as $token ) {
			if ( strpos( $token, '/' ) !== false ) {
				list( $subnet, $bits ) = explode( '/', $token, 2 );
				$subnet = trim( $subnet );
				$bits   = trim( $bits );
				if ( ! ctype_digit( $bits ) ) {
					continue;
				}
				if ( (int) $bits > 32 || ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					continue;
				}
				$valid[] = $subnet . '/' . $bits;
			} elseif ( filter_var( $token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$valid[] = $token;
			}
		}
		return array_values( $valid );
	}

	/**
	 * Check if an IP address matches any of the given ranges.
	 *
	 * @param string $ip     The IP address to check.
	 * @param string $ranges Comma-separated list of IPs and/or CIDR blocks.
	 *
	 * @return bool Whether the IP matches any range.
	 */
	public static function ip_matches_ranges( $ip, $ranges ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}
		$ip_long = ip2long( $ip );

		foreach ( self::parse_ip_ranges( $ranges ) as $range ) {
			if ( strpos( $range, '/' ) !== false ) {
				list( $subnet, $bits ) = explode( '/', $range, 2 );
				$subnet_long = ip2long( $subnet );
				$mask        = -1 << ( 32 - (int) $bits );
				if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
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
	 * Whether the IP-access bypass cookie was sent on the current request.
	 *
	 * The cookie is set after a successful institutional-access verification
	 * (any of an institution's rules matching — IP range, email domain, or
	 * reader data) and signals that downstream IP-rule checks may safely
	 * run server-side without breaking the page cache. Centralizes the
	 * `phpcs:ignore` for the restricted `$_COOKIE` read so callers don't
	 * each carry their own annotation.
	 *
	 * @return bool True if the cookie is present on this request.
	 */
	public static function is_cookie_set() {
		return isset( $_COOKIE[ self::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}
}
IP_Access_Rule::init();
