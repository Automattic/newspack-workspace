<?php
/**
 * Newspack Newsletters Access — bypass-cookie management and
 * Newsletters-issued access grants for the content gate.
 *
 * The first capability provided here is newsletter link bypass: when a
 * reader clicks an HMAC-signed link from a Newspack Newsletters email,
 * this class verifies the signature, sets a short-lived cookie whose `wp`
 * prefix causes Batcache to exempt the request from page cache, and overrides the
 * `newspack_is_post_restricted` filter for the cookie's lifetime.
 *
 * Future Newsletters-related access features can live alongside the
 * existing methods in this class.
 *
 * Note on secret rotation: tokens are signed with HMAC keys derived from
 * the site's WordPress salts (AUTH_KEY, AUTH_SALT, etc.). Rotating those
 * salts invalidates every in-flight signed npnl token AND every active
 * bypass cookie immediately — readers mid-bypass will see the gate
 * return until they click a freshly-signed link. This is intentional
 * (token rotation IS the security feature) but worth knowing if a
 * rotation appears to "break" the feature.
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
	 * Site-wide bypass cookie name. Set by the signed-token path.
	 *
	 * The `wp` 2-char prefix triggers cache exemption in Batcache's
	 * advanced-cache.php: any cookie whose name starts with `wp` causes
	 * Batcache to skip page cache for that request (with a small allowlist
	 * — default only `wordpress_test_cookie` — that is explicitly NOT
	 * exempted). The `_npnl_bypass` portion is purely human-readable
	 * convention and has no technical effect on caching; it signals that
	 * this cookie is an HMAC-verified bypass grant, not just a cache signal.
	 *
	 * See: https://github.com/Automattic/batcache/blob/master/advanced-cache.php
	 *
	 * Value is the build_signed_cookie_value() output for the payload '1':
	 * `1.<expiry>|<hmac>`. is_cookie_set() verifies the HMAC and expiry
	 * before treating the cookie as a valid bypass grant.
	 */
	const COOKIE_NAME = 'wp_npnl_bypass';

	/**
	 * Single-post bypass cookie name. Set by the UTM-fallback path.
	 *
	 * Same `wp` prefix → Batcache cache exemption as COOKIE_NAME above (see
	 * that constant's docblock for the full mechanism). The `_single` suffix
	 * reflects that this cookie scopes the bypass to one post: its value
	 * carries the verified post ID rather than the generic '1' sentinel, so
	 * the server-side check can reject bypass attempts on any other post.
	 *
	 * See: https://github.com/Automattic/batcache/blob/master/advanced-cache.php
	 */
	const SINGLE_POST_COOKIE_NAME = 'wp_npnl_bypass_single';

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
	 * Salt key used to derive the cookie-signing HMAC secret via wp_salt().
	 * Separate from SALT_KEY (npnl token signing) so the two secrets
	 * rotate independently and so a token can't be replayed as a cookie
	 * or vice versa.
	 */
	const COOKIE_SALT_KEY = 'newspack_newsletters_access_cookie';

	/**
	 * Object-cache group for per-newsletter resolved-href maps used by
	 * the UTM fallback's link-scan path. Cached entries are flushed on
	 * newsletter post updates / deletions via the clean_post_cache and
	 * before_delete_post hooks registered in init().
	 */
	const HREFS_CACHE_GROUP = 'newspack_newsletters_access_hrefs';

	/**
	 * In-request memo for UTM-fallback verification: maps a key
	 * derived from list_id + normalized request URL to either the
	 * matched post ID (int) or false (no match). Avoids repeating
	 * the HTML scan when multiple restriction filters fire in the
	 * same request.
	 *
	 * @var array<string,int|false>
	 */
	private static $utm_verification_memo = [];

	/**
	 * Initialize hooks.
	 *
	 * Signing always registers so the toggle can be flipped on later and
	 * recent campaigns still grant bypass. Verification + bypass hooks only
	 * register when the toggle is on.
	 */
	public static function init() {
		// Signing always happens so the toggle can be flipped on later and
		// recent campaigns still grant bypass.
		add_filter( 'newspack_newsletters_process_link', [ __CLASS__, 'append_signature_to_link' ], 20, 3 );

		// Verification + bypass paths only fire when the toggle is on.
		if ( ! self::is_verification_enabled() ) {
			return;
		}
		// Priority 2 (matching Click::handle_click) so Data Events and
		// ActionScheduler are initialized before we redirect.
		add_action( 'init', [ __CLASS__, 'handle_inbound_request_action' ], 2 );
		add_action( 'wp', [ __CLASS__, 'handle_utm_fallback_request_action' ], 10 );
		add_filter( 'newspack_is_post_restricted', [ __CLASS__, 'filter_post_restricted' ], 20, 3 );
		add_filter( 'wc_memberships_is_post_public', [ __CLASS__, 'filter_wc_memberships_is_post_public' ], 20, 2 );

		// Invalidate per-newsletter href cache on mutations. We hook BOTH
		// post-level events (clean_post_cache, before_delete_post — cover
		// the wp_update_post / wp_delete_post paths) AND meta-level events
		// (added/updated/deleted_post_meta scoped to newspack_email_html
		// — cover bare update_post_meta() calls from sender-side code,
		// since update_post_meta does NOT fire clean_post_cache in core).
		add_action( 'clean_post_cache', [ __CLASS__, 'maybe_flush_newsletter_hrefs_cache' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'maybe_flush_newsletter_hrefs_cache' ] );
		add_action( 'added_post_meta', [ __CLASS__, 'maybe_flush_newsletter_hrefs_cache_on_meta_update' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'maybe_flush_newsletter_hrefs_cache_on_meta_update' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'maybe_flush_newsletter_hrefs_cache_on_meta_update' ], 10, 3 );
	}

	/**
	 * Filter callback: append a signed npnl param to newsletter links.
	 *
	 * Skips when the post isn't a newsletter (e.g., newsletter ads, which the
	 * Click class proxies — those carry the signature through via the proxy's
	 * forwarded-params allow-list).
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
	 * Return the newsletter CPT slug. Uses the constant from the Newsletters
	 * plugin when available, so the two stay in sync automatically; falls back
	 * to the hard-coded string on sites without the Newsletters plugin.
	 *
	 * @return string
	 */
	private static function newsletter_cpt_slug() {
		// `defined()` with the class-constant FQN is the safe runtime check —
		// `class_exists()` alone can return true for a class that is loadable
		// but doesn't (yet) have the constant available, which produced
		// Undefined-constant errors in the newspack-plugin standalone test
		// environment.
		if ( defined( '\Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT' ) ) {
			return \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
		}
		return 'newspack_nl_cpt';
	}

	/**
	 * Whether the given post is a newsletter (i.e., the newsletter CPT).
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return bool
	 */
	private static function is_newsletter_post( $post ) {
		return self::newsletter_cpt_slug() === $post->post_type;
	}

	/**
	 * Build the canonical HMAC payload for a newsletter ID. Includes the
	 * current blog ID so tokens minted on one site of a multisite network
	 * cannot be replayed against another site, regardless of whether
	 * AUTH_KEY / AUTH_SALT differ across sites.
	 *
	 * @param int $newsletter_id Newsletter post ID.
	 *
	 * @return string
	 */
	private static function build_payload( $newsletter_id ) {
		return (int) get_current_blog_id() . '|' . (int) $newsletter_id;
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
		$payload = self::build_payload( $newsletter_id );
		$hmac    = hash_hmac( 'sha256', $payload, self::get_secret() );
		return self::base64url_encode( (string) $newsletter_id . '|' . $hmac );
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
		// Real tokens are ~90 bytes (20-char int ID + '|' + 64-char hex
		// HMAC, base64url-encoded). Cap well above that to reject
		// pathological inputs (e.g. ?npnl=<10MB>) before the base64
		// decode pass, which is O(N) over the input length.
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 512 ) {
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
		$expected_hmac = hash_hmac( 'sha256', self::build_payload( $newsletter_id ), self::get_secret() );
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
	 * Re-pads to a multiple of 4 before calling base64_decode in strict mode,
	 * because base64url_encode strips padding ("=") and strict-mode
	 * base64_decode returns false for unpadded input.
	 *
	 * @param string $data URL-safe base64 string.
	 *
	 * @return string|false
	 */
	private static function base64url_decode( $data ) {
		$padding = strlen( $data ) % 4;
		if ( $padding > 0 ) {
			$data .= str_repeat( '=', 4 - $padding );
		}
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		return $decoded;
	}

	/**
	 * Action callback for the `init` hook. Thin wrapper around
	 * process_inbound_request() so the action signature doesn't need to
	 * accommodate WordPress's empty-string arg padding.
	 */
	public static function handle_inbound_request_action() {
		self::process_inbound_request( true );
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
	public static function process_inbound_request( $with_side_effects = true ) {
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
			if ( wp_safe_redirect( $clean_url ) ) {
				exit;
			}
			// Redirect rejected — fall through to return so the page renders
			// normally (the bypass cookie is still set, so the gate is bypassed
			// on this request).
			return [
				'action'        => 'verified',
				'newsletter_id' => $verified['newsletter_id'],
			];
		}

		return [
			'action'        => 'verified',
			'newsletter_id' => $verified['newsletter_id'],
		];
	}

	/**
	 * Set the bypass cookie. The `wp` 2-char prefix triggers cache exemption
	 * in Batcache's advanced-cache.php (any cookie matching /^wp/ skips
	 * cache, with a small allowlist for `wordpress_test_cookie`). The value
	 * is HMAC-signed to prevent forgery; a forged cookie still causes an
	 * uncached render, but the signature check will reject the bypass.
	 */
	private static function set_bypass_cookie() {
		$expiry = time() + self::BYPASS_TTL;
		$value  = self::build_signed_cookie_value( '1', $expiry );
		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie(
				self::COOKIE_NAME,
				$value,
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
		// Unconditionally update $_COOKIE so same-request filters can see
		// the bypass even in test environments where headers are already sent.
		$_COOKIE[ self::COOKIE_NAME ] = $value; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Action callback for the `wp` hook. Thin wrapper around
	 * process_utm_fallback_request() so the action signature doesn't need to
	 * accommodate WordPress's empty-string arg padding.
	 */
	public static function handle_utm_fallback_request_action() {
		self::process_utm_fallback_request( true );
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
	public static function process_utm_fallback_request( $with_side_effects = true ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'email' !== ( $_GET['utm_medium'] ?? '' ) ) {
			return [ 'action' => 'skipped' ];
		}

		// Non-singular requests (homepage, archives, etc.) can never grant
		// a per-post bypass, so they can't poison the cache. Bail before
		// touching the cache so a `?utm_medium=email` on the homepage
		// doesn't force an uncached render unnecessarily.
		if ( ! is_singular() ) {
			return [ 'action' => 'skipped' ];
		}

		// Cache defeat for any singular utm_medium=email request. Must
		// happen BEFORE further validation so cache poisoning is prevented
		// even on bypass-rejection paths that could still set a cookie.
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

		$list_id = sanitize_text_field( wp_unslash( $_GET['utm_source'] ?? '' ) );
		// phpcs:enable
		if ( empty( $list_id ) || ! self::is_valid_send_list_id( $list_id ) ) {
			return [ 'action' => 'invalid' ];
		}

		$current_post_id = (int) get_queried_object_id();
		if ( empty( $current_post_id ) ) {
			return [ 'action' => 'invalid' ];
		}

		$current_url     = get_permalink( $current_post_id );
		$matched_post_id = self::find_matching_newsletter_for_url( $list_id, $current_url );
		if ( null === $matched_post_id ) {
			return [ 'action' => 'invalid' ];
		}
		if ( $with_side_effects ) {
			self::set_single_post_bypass_cookie( $current_post_id );
		}
		return [
			'action'  => 'verified',
			'post_id' => $current_post_id,
		];
	}

	/**
	 * Whether verification of inbound newsletter signatures/UTMs is enabled.
	 *
	 * Delegates to Content_Gate_Advanced_Settings::get_settings() so that all
	 * advanced-settings reads go through the same cached lookup.
	 *
	 * @return bool
	 */
	public static function is_verification_enabled() {
		$settings = \Newspack\Content_Gate_Advanced_Settings::get_settings();
		return ! empty( $settings['newsletter_link_bypass_enabled'] );
	}

	/**
	 * Whether the given send-list ID is known to Newspack Newsletters' list registry.
	 *
	 * Applies the `newspack_newsletters_access_is_valid_send_list_id` filter
	 * first, allowing callers (including tests) to short-circuit the registry
	 * lookup by returning true or false. Returning null defers to the
	 * Subscription_List registry.
	 *
	 * @param string $list_id Send list ID from utm_source.
	 *
	 * @return bool
	 */
	private static function is_valid_send_list_id( $list_id ) {
		/**
		 * Filter the validity of a send-list ID for the newsletter-link-bypass
		 * UTM fallback path. Return true/false to short-circuit the registry
		 * lookup, or null to delegate to Newspack\Newsletters\Subscription_List.
		 *
		 * Production code should generally leave this alone; tests apply the
		 * filter to avoid needing a configured ESP connection.
		 *
		 * @param bool|null $valid   Override decision: true, false, or null to defer.
		 * @param string    $list_id Send list ID from utm_source.
		 */
		$filtered = apply_filters( 'newspack_newsletters_access_is_valid_send_list_id', null, $list_id );
		if ( null !== $filtered ) {
			return (bool) $filtered;
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
	 * Results are cached in a 1-hour transient keyed by list ID. Newsletters
	 * aren't sent more than a couple of times per day per list, so a stale
	 * cache simply omits the most recent send for up to an hour — at which
	 * point the cache refreshes naturally. For freshly-sent newsletters the
	 * signed-token (npnl) path covers bypass independently, so cache staleness
	 * only affects UTM-fallback grants on pre-deploy campaigns.
	 *
	 * @param string $list_id Send list ID.
	 *
	 * @return int[]
	 */
	private static function find_recent_sent_newsletters_for_list( $list_id ) {
		$cache_key = 'newspack_nl_access_list_' . md5( $list_id );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$cutoff = time() - self::SIGNATURE_TTL;
		$ids    = get_posts(
			[
				'post_type'              => self::newsletter_cpt_slug(),
				'post_status'            => [ 'publish', 'private' ],
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

		set_transient( $cache_key, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	/**
	 * Locate the candidate newsletter whose email HTML contains the given URL.
	 * Memoized in-request so multiple restriction filter dispatches in the
	 * same request don't repeat the HTML scan.
	 *
	 * @param string $list_id     Send list ID (already validated upstream).
	 * @param string $current_url Inbound request URL.
	 *
	 * @return int|null Matched newsletter post ID, or null if no match.
	 */
	private static function find_matching_newsletter_for_url( $list_id, $current_url ) {
		$memo_key = $list_id . '|' . $current_url;
		if ( array_key_exists( $memo_key, self::$utm_verification_memo ) ) {
			$cached = self::$utm_verification_memo[ $memo_key ];
			return false === $cached ? null : $cached;
		}

		$current_post_id = self::resolve_url_to_post_id( $current_url );
		if ( $current_post_id <= 0 ) {
			self::$utm_verification_memo[ $memo_key ] = false;
			return null;
		}

		$matched    = false;
		$candidates = self::find_recent_sent_newsletters_for_list( $list_id );
		foreach ( $candidates as $newsletter_id ) {
			$linked_post_ids = self::get_linked_post_ids_for_newsletter( $newsletter_id );
			if ( in_array( $current_post_id, $linked_post_ids, true ) ) {
				$matched = $newsletter_id;
				break;
			}
		}

		self::$utm_verification_memo[ $memo_key ] = $matched;
		return false === $matched ? null : $matched;
	}

	/**
	 * Resolve a URL to its corresponding post ID, preferring the cached
	 * VIP helper when available, falling back to WordPress core.
	 *
	 * The fallback's per-call cost is bounded by the outer (list_id, url)
	 * memo in find_matching_newsletter_for_url() and the 50-candidate cap
	 * on the newsletter lookup, so it's safe to use even on VIP-equipped
	 * sites where the rule would otherwise prefer the cached variant.
	 *
	 * @param string $url URL to resolve.
	 *
	 * @return int Post ID, or 0 if no match.
	 */
	private static function resolve_url_to_post_id( $url ) {
		if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
			return (int) wpcom_vip_url_to_postid( $url );
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- VIP variant used above when available; this fallback is bounded by the outer memo + 50-candidate cap.
		return (int) url_to_postid( $url );
	}

	/**
	 * Resolved post IDs for every href in a newsletter's email HTML.
	 * Cached in the object cache to skip the DOMDocument parse + N
	 * url_to_postid calls on subsequent UTM-fallback requests that touch
	 * the same newsletter.
	 *
	 * Returned values are deduped (each linked post ID appears once).
	 * Cache is invalidated on newsletter post update / delete via the
	 * hooks registered in init() — there is no TTL because mutations
	 * are reliably hook-fired.
	 *
	 * @param int $newsletter_id Newsletter post ID.
	 *
	 * @return int[] Resolved (deduped) linked post IDs. Empty array if
	 *               the newsletter has no email HTML or no hrefs that
	 *               resolve to a post on this site.
	 */
	private static function get_linked_post_ids_for_newsletter( $newsletter_id ) {
		$newsletter_id = (int) $newsletter_id;
		if ( $newsletter_id <= 0 ) {
			return [];
		}

		$cached = wp_cache_get( $newsletter_id, self::HREFS_CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$html = (string) get_post_meta( $newsletter_id, 'newspack_email_html', true );
		if ( '' === $html ) {
			wp_cache_set( $newsletter_id, [], self::HREFS_CACHE_GROUP );
			return [];
		}

		$hrefs = self::extract_hrefs_from_html( $html );
		$ids   = [];
		foreach ( $hrefs as $href ) {
			$resolved = self::resolve_url_to_post_id( $href );
			if ( $resolved > 0 ) {
				$ids[ $resolved ] = true; // Dedupe via assoc-key set.
			}
		}
		$ids = array_keys( $ids );

		wp_cache_set( $newsletter_id, $ids, self::HREFS_CACHE_GROUP );
		return $ids;
	}

	/**
	 * Cache-invalidation callback for clean_post_cache / before_delete_post.
	 *
	 * Fires on every post mutation, so it short-circuits when the post
	 * isn't a newsletter. Covers the wp_update_post / wp_delete_post paths
	 * (Gutenberg / REST editor saves).
	 *
	 * Bare update_post_meta() calls do NOT fire clean_post_cache in WP
	 * core, so the meta-update siblings below cover that surface
	 * separately for the newspack_email_html meta key.
	 *
	 * @param int $post_id Post being updated/deleted.
	 *
	 * @return void
	 */
	public static function maybe_flush_newsletter_hrefs_cache( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( ! $post_type || $post_type !== self::newsletter_cpt_slug() ) {
			return;
		}
		wp_cache_delete( $post_id, self::HREFS_CACHE_GROUP );
	}

	/**
	 * Cache-invalidation callback for the post-meta update actions
	 * (added/updated/deleted_post_meta). Scoped to the newspack_email_html
	 * key — that's the only meta whose value the per-newsletter href cache
	 * depends on, and the meta actions fire for every key so an unscoped
	 * handler would re-invalidate on noise.
	 *
	 * WP core fires these actions with (meta_id, object_id, meta_key,
	 * meta_value). We only need the second and third.
	 *
	 * @param int    $meta_id   Meta row ID (unused).
	 * @param int    $post_id   Post the meta is attached to.
	 * @param string $meta_key  Meta key being mutated.
	 *
	 * @return void
	 */
	public static function maybe_flush_newsletter_hrefs_cache_on_meta_update( $meta_id, $post_id, $meta_key ) {
		if ( 'newspack_email_html' !== $meta_key ) {
			return;
		}
		self::maybe_flush_newsletter_hrefs_cache( $post_id );
	}

	/**
	 * Extract all href attribute values from anchor tags in the given HTML.
	 *
	 * Uses DOMDocument so we match only real link targets — not URLs that
	 * happen to appear in plain text body, in comments, or inside other
	 * attributes — which would widen the bypass eligibility beyond
	 * "actually clicked the newsletter link."
	 *
	 * @param string $html Email HTML.
	 *
	 * @return string[] List of href values (raw, may be HTML-entity-encoded).
	 */
	private static function extract_hrefs_from_html( $html ) {
		if ( '' === $html ) {
			return [];
		}
		$dom = new \DOMDocument();
		// Suppress libxml warnings about the email HTML (often has minor
		// well-formedness issues — MJML output, encoded entities, etc.).
		$prior = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );

		$hrefs = [];
		foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
			$href = $anchor->getAttribute( 'href' );
			if ( '' !== $href ) {
				$hrefs[] = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $hrefs;
	}

	/**
	 * Whether a valid (correctly signed, non-expired) site-wide bypass
	 * cookie was sent on the current request.
	 *
	 * @return bool
	 */
	public static function is_cookie_set() {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC-verified below; sanitization not applicable.
		$raw = $_COOKIE[ self::COOKIE_NAME ] ?? '';
		if ( ! is_string( $raw ) || '' === $raw ) {
			return false;
		}
		$payload = self::verify_signed_cookie_value( $raw );
		return null !== $payload && '1' === $payload;
	}

	/**
	 * Verify the single-post bypass cookie and return the post ID it
	 * authorizes, or null if the cookie is absent, malformed, tampered,
	 * or expired.
	 *
	 * @return int|null
	 */
	public static function get_single_post_bypass_id() {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC-verified below; sanitization not applicable.
		$raw = $_COOKIE[ self::SINGLE_POST_COOKIE_NAME ] ?? '';
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$payload = self::verify_signed_cookie_value( $raw );
		if ( null === $payload || ! ctype_digit( $payload ) ) {
			return null;
		}
		return (int) $payload;
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
	 * The hook is registered only when verification is enabled (see init()).
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
	 * Set the per-post bypass cookie with a signed value encoding the post ID.
	 *
	 * @param int $post_id Verified post ID.
	 */
	private static function set_single_post_bypass_cookie( $post_id ) {
		$expiry = time() + self::BYPASS_TTL;
		$value  = self::build_signed_cookie_value( (string) (int) $post_id, $expiry );
		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			setcookie(
				self::SINGLE_POST_COOKIE_NAME,
				$value,
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
		// Unconditionally update $_COOKIE so same-request filters can see
		// the bypass even in test environments where headers are already sent.
		$_COOKIE[ self::SINGLE_POST_COOKIE_NAME ] = $value; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}

	/**
	 * Build a signed cookie value of the form "<payload>.<expiry>|<hmac>"
	 * where the HMAC binds both the payload and the expiry timestamp to
	 * the class's COOKIE_SALT_KEY-derived secret.
	 *
	 * @param string $payload Cookie payload (sentinel "1" or post ID as string).
	 * @param int    $expiry  Unix timestamp at which the cookie expires.
	 *
	 * @return string
	 */
	private static function build_signed_cookie_value( $payload, $expiry ) {
		$body = $payload . '.' . (int) $expiry;
		$hmac = hash_hmac( 'sha256', $body, wp_salt( self::COOKIE_SALT_KEY ) );
		return $body . '|' . $hmac;
	}

	/**
	 * Verify a signed cookie value and return the inner payload string,
	 * or null on malformed input, bad signature, or expired cookie.
	 *
	 * @param string $value Raw cookie value as received in $_COOKIE.
	 *
	 * @return string|null
	 */
	private static function verify_signed_cookie_value( $value ) {
		$parts = explode( '|', $value, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		list( $body, $provided_hmac ) = $parts;
		$expected_hmac = hash_hmac( 'sha256', $body, wp_salt( self::COOKIE_SALT_KEY ) );
		if ( ! hash_equals( $expected_hmac, $provided_hmac ) ) {
			return null;
		}
		$body_parts = explode( '.', $body );
		if ( 2 !== count( $body_parts ) ) {
			return null;
		}
		list( $payload, $expiry_raw ) = $body_parts;
		if ( ! ctype_digit( $expiry_raw ) ) {
			return null;
		}
		if ( (int) $expiry_raw <= time() ) {
			return null;
		}
		return $payload;
	}
}
Newsletters_Access::init();
