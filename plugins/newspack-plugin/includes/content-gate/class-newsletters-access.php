<?php
/**
 * Newspack Newsletters Access — bypass-cookie management and
 * Newsletters-issued access grants for the content gate.
 *
 * The first capability provided here is newsletter link bypass: when a
 * reader clicks an HMAC-signed link from a Newspack Newsletters email,
 * this class verifies the signature, sets a short-lived cookie that the
 * Atomic platform recognizes as a cache-bypass cookie, and overrides the
 * `newspack_is_post_restricted` filter for the cookie's lifetime.
 *
 * Future Newsletters-related access features can live alongside the
 * existing methods in this class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletters_Access class.
 */
class Newsletters_Access {
	/**
	 * Site-wide bypass cookie name. Set by the signed-token path. Uses the
	 * wp_nocache_* prefix so the Atomic platform skips page cache for
	 * requests carrying it. Value is '1' (presence-checked).
	 */
	const COOKIE_NAME = 'wp_nocache_nl';

	/**
	 * Single-post bypass cookie name. Set by the UTM-fallback path. Same
	 * wp_nocache_* prefix; value carries the verified post ID so the
	 * bypass scopes to that post only.
	 */
	const SINGLE_POST_COOKIE_NAME = 'wp_nocache_nl_single';

	/**
	 * Query parameter name carrying the signed token on newsletter links.
	 */
	const QUERY_PARAM = 'npnl';

	/**
	 * How long the bypass cookie remains valid after first use.
	 */
	const BYPASS_TTL = HOUR_IN_SECONDS;

	/**
	 * Maximum elapsed time, in seconds, between a newsletter's send time
	 * (from the `newsletter_sent` post meta) and the inbound request.
	 * Signatures whose underlying newsletter was sent longer ago than this
	 * are rejected even if cryptographically valid.
	 */
	const SIGNATURE_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Salt key used to derive the HMAC secret via wp_salt().
	 */
	const SALT_KEY = 'newspack_newsletters_access_link_bypass';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'newspack_newsletters_process_link', [ __CLASS__, 'append_signature_to_link' ], 20, 3 );
		add_action( 'init', [ __CLASS__, 'handle_inbound_request' ], 2, 0 );
		add_action( 'wp', [ __CLASS__, 'handle_utm_fallback_request' ], 10, 0 );
		add_filter( 'newspack_is_post_restricted', [ __CLASS__, 'filter_post_restricted' ], 20, 3 );
		add_filter( 'wc_memberships_is_post_public', [ __CLASS__, 'filter_wc_memberships_is_post_public' ], 20, 2 );
	}

	/**
	 * Filter callback: append a signed npnl param to newsletter links.
	 *
	 * Skips when the post isn't a newsletter (e.g., newsletter ads, which the
	 * Click class proxies — those carry the signature through after Task 9
	 * adds 'npnl' to the proxy's allow-list).
	 *
	 * @param string        $url          Processed URL (may already carry utm_* params).
	 * @param string        $original_url Original URL before any processing.
	 * @param \WP_Post|null $post         Newsletter post object, or null.
	 *
	 * @return string
	 */
	public static function append_signature_to_link( $url, $original_url, $post ) {
		if ( ! $post || ! self::is_newsletter_post( $post ) ) {
			return $url;
		}
		if ( ! self::is_first_party_url( $url ) ) {
			return $url;
		}
		$token = self::sign( $post->ID );
		return add_query_arg( self::QUERY_PARAM, $token, $url );
	}

	/**
	 * Whether the given URL points to this site, by host comparison.
	 *
	 * Newsletter HTML can contain links to arbitrary third-party domains
	 * (e.g., "Read more at nytimes.com" callouts). Appending the signed
	 * npnl token to those URLs would leak a replayable bypass credential
	 * into third-party logs, analytics, and Referer headers. The token is
	 * only meaningful for verification against this site's HMAC secret,
	 * so leaving external URLs unsigned costs nothing and closes the leak.
	 *
	 * Relative URLs are treated as first-party.
	 *
	 * @param string $url URL to test.
	 *
	 * @return bool
	 */
	private static function is_first_party_url( $url ) {
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $url_host ) ) {
			// Relative URL — same site by definition.
			return true;
		}
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		return strcasecmp( $url_host, (string) $site_host ) === 0;
	}

	/**
	 * Whether the given post is a newsletter (i.e., the newsletter CPT).
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return bool
	 */
	private static function is_newsletter_post( $post ) {
		return 'newspack_nl_cpt' === $post->post_type;
	}

	/**
	 * Sign a newsletter ID and return a URL-safe token.
	 *
	 * The signature is deterministic — same ID always produces the same
	 * token. The TTL is enforced at verification time against the post's
	 * `newsletter_sent` meta, not against any timestamp in the token.
	 *
	 * @param int $newsletter_id Newsletter post ID.
	 *
	 * @return string Base64url-encoded token of form "id|hmac".
	 */
	public static function sign( $newsletter_id ) {
		$payload = (string) $newsletter_id;
		$hmac    = hash_hmac( 'sha256', $payload, self::get_secret() );
		return self::base64url_encode( $payload . '|' . $hmac );
	}

	/**
	 * Verify a token and return the decoded payload, or false on failure.
	 *
	 * Returns false for: malformed input, bad signature, a newsletter that
	 * doesn't exist or was never sent (no `newsletter_sent` meta), or a
	 * newsletter whose send time is older than SIGNATURE_TTL.
	 *
	 * The post-meta lookup is reached only after the HMAC check passes, so
	 * forged or random tokens cost no DB queries.
	 *
	 * @param string $token Encoded token from the npnl query param.
	 *
	 * @return array|false ['newsletter_id' => int, 'sent_at' => int] or false.
	 */
	public static function verify( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		$decoded = self::base64url_decode( $token );
		if ( false === $decoded ) {
			return false;
		}
		$parts = explode( '|', $decoded );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		list( $id_raw, $provided_hmac ) = $parts;
		if ( ! ctype_digit( $id_raw ) ) {
			return false;
		}
		$newsletter_id = (int) $id_raw;
		$expected_hmac = hash_hmac( 'sha256', $id_raw, self::get_secret() );
		if ( ! hash_equals( $expected_hmac, $provided_hmac ) ) {
			return false;
		}
		$sent_at = get_post_meta( $newsletter_id, 'newsletter_sent', true );
		if ( empty( $sent_at ) || ! is_numeric( $sent_at ) ) {
			return false;
		}
		$sent_at = (int) $sent_at;
		if ( ( time() - $sent_at ) > self::SIGNATURE_TTL ) {
			return false;
		}
		return [
			'newsletter_id' => $newsletter_id,
			'sent_at'       => $sent_at,
		];
	}

	/**
	 * Get the HMAC secret derived from site salts.
	 *
	 * @return string
	 */
	private static function get_secret() {
		return wp_salt( self::SALT_KEY );
	}

	/**
	 * Base64url encode (no padding, '-_' instead of '+/').
	 *
	 * @param string $data Raw bytes.
	 *
	 * @return string
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode. Returns false on malformed input.
	 *
	 * @param string $data URL-safe base64 string.
	 *
	 * @return string|false
	 */
	private static function base64url_decode( $data ) {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		return $decoded;
	}

	/**
	 * Inbound request handler. Validates the npnl token, sets the bypass
	 * cookie, cancels page cache for the redirect response, and redirects
	 * to the same URL with the token stripped from the address bar.
	 *
	 * @param bool $with_side_effects When false, returns the verification
	 *                                result without setting cookies or
	 *                                redirecting. Used by tests.
	 *
	 * @return array{action: string, newsletter_id?: int}
	 */
	public static function handle_inbound_request( $with_side_effects = true ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) {
			return [ 'action' => 'skipped' ];
		}

		if ( ! self::is_verification_enabled() ) {
			return [ 'action' => 'disabled' ];
		}

		// Don't trigger for logged-in editors/admins — they bypass the gate
		// via capability checks and shouldn't burn a signature on every click.
		if ( is_user_logged_in() && current_user_can( 'edit_others_posts' ) ) {
			return [ 'action' => 'skipped' ];
		}

		$token    = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) );
		$verified = self::verify( $token );
		// phpcs:enable

		if ( false === $verified ) {
			return [ 'action' => 'invalid' ];
		}

		if ( $with_side_effects ) {
			self::set_bypass_cookie();
			if ( function_exists( 'batcache_cancel' ) ) {
				batcache_cancel();
			}
			nocache_headers();
			$clean_url = remove_query_arg( self::QUERY_PARAM );
			wp_safe_redirect( $clean_url );
			exit;
		}

		return [
			'action'        => 'verified',
			'newsletter_id' => $verified['newsletter_id'],
		];
	}

	/**
	 * Set the bypass cookie. The wp_nocache_* prefix triggers cache bypass
	 * at the platform layer; the cookie value itself is just '1'.
	 */
	private static function set_bypass_cookie() {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie,WordPress.PHP.NoSilencedErrors.Discouraged -- @ suppresses the "headers already sent" E_WARNING in unit-test environments where PHP output has been flushed; it has no effect in production where this method is called during init before any output.
		@setcookie(
			self::COOKIE_NAME,
			'1',
			[
				'expires'  => time() + self::BYPASS_TTL,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		// Make the cookie visible to the rest of this request — setcookie() only
		// queues the response header, but filters that run later in the same
		// request need to see the value via $_COOKIE.
		$_COOKIE[ self::COOKIE_NAME ] = '1'; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * UTM-fallback inbound handler. Runs on `wp` so the queried object is available.
	 *
	 * Always calls batcache_cancel() + nocache_headers() when utm_medium=email is
	 * present, to prevent the bypassed response from poisoning the shared
	 * edge-cache entry that Atomic uses for all utm-bearing requests.
	 *
	 * @param bool $with_side_effects When false, returns the verification result
	 *                                without setting cookies. Used by tests.
	 *
	 * @return array{action: string, post_id?: int}
	 */
	public static function handle_utm_fallback_request( $with_side_effects = true ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'email' !== ( $_GET['utm_medium'] ?? '' ) ) {
			return [ 'action' => 'skipped' ];
		}

		// Unconditional cache defeat for any utm_medium=email request. Must
		// happen BEFORE any other validation so cache poisoning is prevented
		// even on bypass-rejection paths.
		if ( $with_side_effects ) {
			if ( function_exists( 'batcache_cancel' ) ) {
				batcache_cancel();
			}
			nocache_headers();
		}

		// If the reader already holds the site-wide bypass cookie (from the
		// signed path), the single-post bypass would be redundant. Skip the
		// list-ID lookup and HTML scan, and don't set a second cookie.
		if ( self::is_cookie_set() ) {
			return [ 'action' => 'skipped' ];
		}

		if ( ! self::is_verification_enabled() ) {
			return [ 'action' => 'disabled' ];
		}
		if ( is_user_logged_in() && current_user_can( 'edit_others_posts' ) ) {
			return [ 'action' => 'skipped' ];
		}
		if ( ! is_singular() ) {
			return [ 'action' => 'skipped' ];
		}

		$list_id = sanitize_text_field( wp_unslash( $_GET['utm_source'] ?? '' ) );
		// phpcs:enable
		if ( empty( $list_id ) || ! self::is_valid_send_list_id( $list_id ) ) {
			return [ 'action' => 'invalid' ];
		}

		$current_post_id = (int) get_queried_object_id();
		if ( empty( $current_post_id ) ) {
			return [ 'action' => 'invalid' ];
		}

		$candidates  = self::find_recent_sent_newsletters_for_list( $list_id );
		$current_url = get_permalink( $current_post_id );
		foreach ( $candidates as $newsletter_id ) {
			$html = (string) get_post_meta( $newsletter_id, 'newspack_email_html', true );
			if ( '' !== $html && self::email_html_contains_url( $html, $current_url ) ) {
				if ( $with_side_effects ) {
					self::set_single_post_bypass_cookie( $current_post_id );
				}
				return [
					'action'  => 'verified',
					'post_id' => $current_post_id,
				];
			}
		}
		return [ 'action' => 'invalid' ];
	}

	/**
	 * Whether verification of inbound newsletter signatures/UTMs is enabled.
	 *
	 * Delegates to Content_Gate_Advanced_Settings::get_settings() so that all
	 * advanced-settings reads go through the same cached lookup.
	 *
	 * @return bool
	 */
	private static function is_verification_enabled() {
		$settings = Content_Gate_Advanced_Settings::get_settings();
		return ! empty( $settings['newsletter_link_bypass_enabled'] );
	}

	/**
	 * Whether the given send-list ID is known to Newspack Newsletters' list registry.
	 *
	 * In tests, consults the `newspack_newsletters_access_test_valid_list_ids` filter
	 * to avoid needing a configured ESP connection. In production, delegates to
	 * `Newspack\Newsletters\Subscription_List::from_remote_id()` — a non-null return
	 * value confirms the list exists in the connected ESP.
	 *
	 * @param string $list_id Send list ID from utm_source.
	 *
	 * @return bool
	 */
	private static function is_valid_send_list_id( $list_id ) {
		// Test-only escape hatch — see Task 4b unit tests.
		if ( defined( 'WP_TESTS_DOMAIN' ) ) {
			$test_ids = apply_filters( 'newspack_newsletters_access_test_valid_list_ids', [] );
			return in_array( $list_id, (array) $test_ids, true );
		}
		if ( ! class_exists( '\Newspack\Newsletters\Subscription_List' ) ) {
			return false;
		}
		// Verify against the Subscription_List registry. A non-null return value
		// confirms the list exists in the connected ESP.
		return null !== \Newspack\Newsletters\Subscription_List::from_remote_id( $list_id );
	}

	/**
	 * Find newsletter post IDs sent to the given list within the SIGNATURE_TTL window.
	 *
	 * @param string $list_id Send list ID.
	 *
	 * @return int[]
	 */
	private static function find_recent_sent_newsletters_for_list( $list_id ) {
		$cutoff = time() - self::SIGNATURE_TTL;
		return get_posts(
			[
				'post_type'              => 'newspack_nl_cpt',
				'posts_per_page'         => 50,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => [
					'relation' => 'AND',
					[
						'key'   => 'send_list_id',
						'value' => $list_id,
					],
					[
						'key'     => 'newsletter_sent',
						'value'   => $cutoff,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					],
				],
			]
		);
	}

	/**
	 * Whether the given email HTML contains the given URL, with a boundary
	 * check to prevent prefix-collision false positives (e.g., `my-article`
	 * matching `my-article-extended/`, or `?p=99` matching `?p=999`).
	 *
	 * The URL is considered "in" the HTML only if the character immediately
	 * after the match is a typical URL terminator: `/`, `?`, `&`, `#`, `"`,
	 * or `'` — the natural boundary characters at the end of an href value.
	 *
	 * @param string $html Email HTML.
	 * @param string $url  Canonical post URL (e.g., from get_permalink()).
	 *
	 * @return bool
	 */
	private static function email_html_contains_url( $html, $url ) {
		$needle = untrailingslashit( $url );
		if ( '' === $needle ) {
			return false;
		}
		foreach ( [ '/', '?', '&', '#', '"', "'" ] as $boundary ) {
			if ( false !== stripos( $html, $needle . $boundary ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the bypass cookie was sent on the current request.
	 *
	 * The cookie's wp_nocache_* prefix already excludes this request from
	 * the platform page cache, so a cookie-bearing reader will always hit
	 * PHP and see this check.
	 *
	 * @return bool
	 */
	public static function is_cookie_set() {
		return isset( $_COOKIE[ self::COOKIE_NAME ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Read the post ID from the single-post bypass cookie, or null if absent/invalid.
	 *
	 * @return int|null
	 */
	public static function get_single_post_bypass_id() {
		$raw = $_COOKIE[ self::SINGLE_POST_COOKIE_NAME ] ?? ''; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $raw ) || ! ctype_digit( $raw ) ) {
			return null;
		}
		return (int) $raw;
	}

	/**
	 * Filter: force a restricted post to read as unrestricted when either
	 * the site-wide bypass cookie OR a matching single-post bypass cookie
	 * is present.
	 *
	 * @param bool     $is_post_restricted Whether the post is restricted.
	 * @param int|null $post_id            Post ID under evaluation. Required
	 *                                     for single-post scoping.
	 * @param int|null $user_id            User ID (unused).
	 *
	 * @return bool
	 */
	public static function filter_post_restricted( $is_post_restricted, $post_id = null, $user_id = null ) {
		if ( ! self::is_verification_enabled() ) {
			return $is_post_restricted;
		}
		if ( self::is_cookie_set() ) {
			return false;
		}
		$single = self::get_single_post_bypass_id();
		if ( null !== $single && (int) $post_id === $single ) {
			return false;
		}
		return $is_post_restricted;
	}

	/**
	 * Filter: tell WooCommerce Memberships to treat the post as public when
	 * either bypass cookie applies to the post under evaluation.
	 *
	 * WC Memberships dispatches this filter with `$is_public, $post_id`. The
	 * `$post_id` arg is the authoritative subject of the check — it can refer
	 * to an arbitrary post (cap checks, restrict_post in loops, REST output,
	 * widget/related-posts queries) that is not the main queried object. We must
	 * compare against `$post_id`, not `get_queried_object_id()`, to scope the
	 * single-post bypass correctly.
	 *
	 * The hook is registered unconditionally — if WC Memberships isn't active,
	 * the filter is never dispatched and this method is inert.
	 *
	 * @param bool     $is_public Whether the post is publicly accessible.
	 * @param int|null $post_id   Post ID being evaluated by WC. Null in some
	 *                            edge dispatches, in which case we fall back
	 *                            to the queried object.
	 *
	 * @return bool
	 */
	public static function filter_wc_memberships_is_post_public( $is_public, $post_id = null ) {
		if ( ! self::is_verification_enabled() ) {
			return $is_public;
		}
		if ( self::is_cookie_set() ) {
			return true;
		}
		$single = self::get_single_post_bypass_id();
		if ( null === $single ) {
			return $is_public;
		}
		$eval_post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
		if ( $eval_post_id === $single ) {
			return true;
		}
		return $is_public;
	}

	/**
	 * Set the per-post bypass cookie with the post ID as its value.
	 *
	 * @param int $post_id Verified post ID.
	 */
	private static function set_single_post_bypass_cookie( $post_id ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie,WordPress.PHP.NoSilencedErrors.Discouraged -- see the note in set_bypass_cookie().
		@setcookie(
			self::SINGLE_POST_COOKIE_NAME,
			(string) $post_id,
			[
				'expires'  => time() + self::BYPASS_TTL,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		// Make the cookie visible to the rest of this request — see the note
		// in set_bypass_cookie().
		$_COOKIE[ self::SINGLE_POST_COOKIE_NAME ] = (string) $post_id; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}
}
Newsletters_Access::init();
