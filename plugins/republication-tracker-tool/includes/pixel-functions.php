<?php
/**
 * Tracking pixel counting: request validation, view recording, and the
 * counting guards (bot filtering and per-client deduplication).
 *
 * The guards gate only the guarded shadow count. The raw counter and the GA4
 * Measurement Protocol event keep their legacy per-view behavior in both flag
 * states.
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Whether the counting guards (bot filtering, deduplication, uncacheable
 * responses) are enabled.
 *
 * Off by default for a gradual rollout. Enabling switches no displayed number:
 * the raw counter keeps its legacy counting rules, and the plugin additionally
 * records a guarded comparison count and makes pixel responses uncacheable
 * (which can itself raise the raw count on busy stories — see
 * wprtt_record_view()). Enable per site (or fleet-wide via a managed define)
 * with the WPRTT_COUNTING_GUARDS_ENABLED constant, or in code via the filter.
 *
 * @return bool True if the counting guards are enabled.
 */
function wprtt_counting_guards_enabled(): bool {
	$enabled = defined( 'WPRTT_COUNTING_GUARDS_ENABLED' ) && WPRTT_COUNTING_GUARDS_ENABLED;

	/**
	 * Filters whether the pixel counting guards are enabled.
	 *
	 * @param bool $enabled Whether the guards are enabled.
	 */
	return (bool) apply_filters( 'wprtt_counting_guards_enabled', $enabled );
}

/**
 * Resolve the shared post a pixel request refers to.
 *
 * The pixel endpoint is public, so the post ID is untrusted input, and only a
 * strictly positive integer may reach get_post(): get_post( 0 ) does NOT
 * return null — it falls back to the global post, which by template time is an
 * unrelated published post — and PHP's coercing casts map other garbage to
 * real IDs (a non-empty array to 1, '-47' or '47abc' to 47). Counting against
 * any of those credits views to a story that was never republished (the
 * settings page's copy-paste snippet ships a literal YOUR-POST-ID placeholder,
 * so this is a real request shape).
 *
 * @param array $get The request query params (typically $_GET).
 * @return WP_Post|null The shared post, or null when the request carries no
 *                      resolvable post.
 */
function wprtt_resolve_shared_post( array $get ): ?WP_Post {
	if ( ! isset( $get['post'], $get['ga4'] ) ) {
		return null;
	}
	// Strict positive-integer validation, not absint(): PHP's int cast maps
	// garbage to REAL post IDs — a non-empty array to 1, '-47' (via abs) or
	// '47abc' to 47 — so a coercing validator resolves attacker-chosen posts.
	// FILTER_VALIDATE_INT refuses arrays, negatives, floats, and trailing
	// garbage alike.
	$post_id = filter_var( $get['post'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
	if ( false === $post_id ) {
		return null;
	}
	$post = get_post( $post_id );
	return $post instanceof WP_Post ? $post : null;
}

/**
 * Whether a pixel request comes from a bot, crawler, link-preview agent, or script.
 *
 * The tracking pixel is a plain <img>, so any crawler or chat/social link-preview
 * agent that fetches images registers a hit. Real browsers always send a user
 * agent, so an empty one is treated as a bot.
 *
 * @param string $user_agent The request's user agent string.
 * @return bool True if the request should be treated as a bot.
 */
function wprtt_is_bot_request( string $user_agent ): bool {
	$user_agent = trim( $user_agent );
	if ( '' === $user_agent ) {
		return true;
	}
	// Tokens must target crawlers, not brand names: in-app browsers (Pinterest,
	// Facebook, Instagram) carry their brand in a real reader's user agent, so
	// e.g. Pinterest's crawler is matched by its full product token
	// (Pinterest/0.x) rather than the bare word.
	$bot_pattern = '/(?<!cu)bot|crawl|spider|slurp|preview|externalhit|feedfetcher|embedly|quora link|outbrain|pinterest\/0\.|vkshare|validator|whatsapp|telegram|skypeuripreview|nuzzel|discordapp|qwantify|bitlybot|scanner|scrape|curl|wget|python|libwww|httpunit|nutch|go-http-client|java\/|okhttp|phantomjs|headlesschrome|lighthouse|pingdom|gtmetrix|uptimerobot|statuscake|newspaper|monitor/i';

	/**
	 * Filters the bot-detection pattern, so a false positive in the field is a
	 * support-desk fix instead of a plugin release.
	 *
	 * @param string $bot_pattern The regex matched against the user agent.
	 */
	$bot_pattern = apply_filters( 'wprtt_bot_request_pattern', $bot_pattern );

	return (bool) preg_match( $bot_pattern, $user_agent );
}

/**
 * Generate a random client ID string and set the newspack-cid fallback cookie if not set.
 *
 * @return string Randomly generated client ID.
 */
function wprtt_create_cid_cookie_if_not_set(): string {
	$cid = (string) wp_rand( 100000000, 999999999 );

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
	setcookie( 'newspack-cid', $cid, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, true );

	return $cid;
}

/**
 * Read a client ID from the _ga or newspack-cid cookies, without creating one.
 *
 * Cookies are client-controlled, so present-but-empty values are treated the
 * same as missing.
 *
 * @return string Client ID, or empty string when no usable cookie value exists.
 */
function wprtt_read_cid_from_cookies(): string {
	if ( isset( $_COOKIE['_ga'] ) ) {
		$ga_cookie = sanitize_text_field( wp_unslash( $_COOKIE['_ga'] ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		// A well-formed cookie (GA1.2.<cid>) yields the third piece; malformed
		// values with fewer pieces still yield their last piece.
		$cookie_pieces = explode( '.', $ga_cookie, 3 );
		$cid           = trim( (string) end( $cookie_pieces ) );
		if ( '' !== $cid ) {
			return $cid;
		}
	}

	if ( isset( $_COOKIE['newspack-cid'] ) ) {
		$cid = trim( sanitize_text_field( wp_unslash( $_COOKIE['newspack-cid'] ) ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		if ( '' !== $cid ) {
			return $cid;
		}
	}
	return '';
}

/**
 * Extract the client ID from the cookies, generating (and setting) one when no
 * usable cookie value exists.
 *
 * @return string Client ID.
 */
function wprtt_extract_cid_from_cookies(): string {
	$cid = wprtt_read_cid_from_cookies();
	if ( '' !== $cid ) {
		return $cid;
	}
	return wprtt_create_cid_cookie_if_not_set();
}

/**
 * Get the identity used to deduplicate views from the same client.
 *
 * Prefers the analytics client ID when the request carries one. Cross-site
 * pixel requests usually don't — browsers withhold SameSite=Lax cookies on
 * third-party image loads — so the fallback is a hash of IP, user agent, and
 * Accept-Language, which are available on every request. Nothing is stored
 * beyond the hashed dedup key.
 *
 * @return string Dedup identity, or empty string when nothing is available.
 */
function wprtt_get_dedup_identity(): string {
	$cid = wprtt_read_cid_from_cookies();
	if ( '' !== $cid ) {
		return $cid;
	}
	// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- The pixel response is explicitly uncacheable (no-store + batcache_cancel), and these values only feed a salted dedup hash: spoofing them merely weakens dedup for that client (fails open, same as no dedup).
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$lang = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
	// phpcs:enable
	if ( '' === $ip && '' === $ua ) {
		return '';
	}
	// Accept-Language adds entropy the reduced modern UA no longer carries, so
	// readers behind a shared egress IP (CGNAT, offices, schools) don't all
	// collapse into one identity. wp_hash() is HMAC-MD5 keyed on the auth salt;
	// rotating the site's salts resets every dedup identity and restarts the
	// window — harmless, it fails open.
	return 'ipua_' . wp_hash( $ip . '|' . $ua . '|' . $lang );
}

/**
 * Function to get the title of the referring url.
 *
 * @param string $url URL of the referrer.
 * @return string Title of the referring URL, or empty string if we can't find it.
 */
function wprtt_get_referring_page_title( string $url ): string {
	// The 2-second timeout bounds how long a pixel request can hold a worker on
	// this blocking outbound call — the endpoint is public, and with the
	// counting guards on its responses are uncacheable. The VIP helper gets the
	// same bound explicitly (its own default is 1s). wp_safe_remote_get
	// (reject_unsafe_urls) re-validates every redirect hop: the caller's
	// wp_http_validate_url() gate only covers the first hop, so without it a
	// referrer host that 302s to an internal address would be followed.
	$response = function_exists( 'vip_safe_wp_remote_get' ) ? vip_safe_wp_remote_get( $url, '', 3, 2 ) : wp_safe_remote_get( $url, [ 'timeout' => 2 ] );

	$title = '';

	// if there was no issue grabbing the url, grab the title.
	if ( ! is_wp_error( $response ) ) {

		// find the title element inside of the response body.
		preg_match( '/<title[^>]*>(.*)<\/title>/iU', $response['body'], $title_matches );

		// if a title element was found, let's get the text from it: remove
		// EOL's and excessive whitespace. The (string) cast covers preg_replace
		// returning null on a PCRE failure against a malformed remote body.
		if ( $title_matches ) {
			$title = trim( (string) preg_replace( '/\s+/', ' ', $title_matches[1] ) );
		}
	}

	return $title;
}

/**
 * Increment a referrer-keyed sharing counter meta value.
 *
 * @param int    $post_id      The shared post.
 * @param string $meta_key     The counter meta key.
 * @param string $referrer_url The referrer the view arrived from.
 */
function wprtt_increment_sharing_counter( int $post_id, string $meta_key, string $referrer_url ): void {
	$value = get_post_meta( $post_id, $meta_key, true );
	if ( ! is_array( $value ) ) {
		$value = [];
	}
	if ( isset( $value[ $referrer_url ] ) ) {
		$value[ $referrer_url ]++;
	} else {
		$value[ $referrer_url ] = 1;
	}
	update_post_meta( $post_id, $meta_key, $value );
}

/**
 * Snapshot the raw counter at the start of the guarded-count era for a post.
 *
 * The raw counter carries lifetime history while the guarded count starts at
 * zero the moment the flag flips, so a bare comparison mixes time windows on
 * any story with existing views. The baseline — the raw counter as it stood
 * before the first guarded-era view, plus a start timestamp — makes the honest
 * comparison possible: guarded vs (raw minus baseline) over the same period.
 *
 * @param int $post_id The shared post ID.
 */
function wprtt_maybe_snapshot_guarded_baseline( int $post_id ): void {
	if ( is_array( get_post_meta( $post_id, 'republication_tracker_tool_sharing_guarded_baseline', true ) ) ) {
		return;
	}
	$raw = get_post_meta( $post_id, 'republication_tracker_tool_sharing', true );
	add_post_meta(
		$post_id,
		'republication_tracker_tool_sharing_guarded_baseline',
		[
			'started' => time(),
			'raw'     => is_array( $raw ) ? $raw : [],
		],
		true // Unique: check-then-act, so a duplicate row is possible; only the first is ever read.
	);
}

/**
 * Record a view of a shared post from a pixel request.
 *
 * The raw counter (republication_tracker_tool_sharing) is the publisher-visible
 * number and its counting rules are identical in both flag states: every view
 * that reaches this function counts. (With the guards on, MORE requests reach
 * it — pixel responses become uncacheable, so hits that page/edge caches used
 * to absorb now arrive — which can raise the displayed number on busy stories.)
 * With the counting guards enabled, a second, guarded count is written
 * alongside it (republication_tracker_tool_sharing_guarded): bot-filtered and
 * per-client-per-republisher deduplicated. The pilot compares the two counts
 * (and Parse.ly) on real traffic; switching the displayed number to the
 * guarded count is separate, data-informed work.
 *
 * The GA4 Measurement Protocol event keeps its legacy shape: it fires once per
 * raw view when GA4 is fully configured (Measurement ID + API secret) and the
 * request's ga4 param matches. The client-ID cookie is only ever minted inside
 * that fully-configured branch — newspack-cid is the shared Newspack
 * reader-identity cookie, and a view counter must not create it on sites that
 * never finished GA4 setup.
 *
 * @param WP_Post $shared_post    The shared post the pixel refers to.
 * @param string  $referrer_url   The sanitized referrer URL ('' when absent).
 * @param string  $ga4_param      The raw ga4 query param from the request.
 * @param bool    $guards_enabled Whether the counting guards are enabled.
 */
function wprtt_record_view( WP_Post $shared_post, string $referrer_url, string $ga4_param, bool $guards_enabled ): void {
	$shared_post_id = $shared_post->ID;

	// The baseline must capture the raw counter BEFORE this view increments it,
	// so the guarded era and the raw-since-baseline delta start together.
	if ( $guards_enabled ) {
		wprtt_maybe_snapshot_guarded_baseline( $shared_post_id );
	}

	wprtt_increment_sharing_counter( $shared_post_id, 'republication_tracker_tool_sharing', $referrer_url );

	// Shadow count: what the counter WOULD read with bot filtering and
	// per-client dedup applied. Written only when the guards are on; never
	// affects the raw counter above.
	if ( $guards_enabled ) {
		// Read here, next to the dedup identity that reads the same request
		// attributes, so the bot check and the identity can't diverge.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- Only read when the guards are on, which makes the pixel response uncacheable (no-store + batcache_cancel in pixel.php).
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( ! wprtt_is_bot_request( $user_agent ) && wprtt_should_count_view( $shared_post_id, wprtt_get_dedup_identity(), $referrer_url ) ) {
			wprtt_increment_sharing_counter( $shared_post_id, 'republication_tracker_tool_sharing_guarded', $referrer_url );
		}
	}

	// If we have the necessary GA4 info, let's push data to it.
	// We need both a Measurement ID and an API secret for GA4.
	// https://developers.google.com/analytics/devguides/collection/protocol/ga4/sending-events?client_type=gtag#required_parameters.
	$ga4_id     = get_option( 'republication_tracker_tool_analytics_ga4_id' );
	$ga4_secret = get_option( 'republication_tracker_tool_analytics_ga4_secret', false );
	if ( ! $ga4_id || ! $ga4_secret || $ga4_param !== $ga4_id ) {
		return;
	}

	// The title fetch is a blocking outbound request only the GA4 event needs.
	// The referrer is client-controlled: validate it (blocks non-http(s) and
	// local/private targets) before fetching anything server-side.
	$url_title = '' !== $referrer_url && wp_http_validate_url( $referrer_url ) ? wprtt_get_referring_page_title( $referrer_url ) : '';

	$shared_post_permalink = get_permalink( $shared_post_id );

	// add_query_arg() does not encode values itself; a secret containing & or #
	// would silently corrupt the URL without the explicit encoding. Verified
	// live: newly added args pass through add_query_arg() unencoded — only
	// values parsed from an existing query string on the base URL are
	// re-encoded, and this base URL has none. No double-encoding here.
	$base_url = add_query_arg(
		[
			'api_secret'     => rawurlencode( $ga4_secret ),
			'measurement_id' => rawurlencode( $ga4_id ),
		],
		'https://www.google-analytics.com/mp/collect'
	);
	$payload  = [
		'client_id' => wprtt_extract_cid_from_cookies(),
		'events'    => [
			[
				'name'   => 'page_view',
				// Params for page_view events: https://developers.google.com/analytics/devguides/collection/ga4/views?client_type=gtag.
				'params' => [
					'page_title'       => substr( $url_title, 0, 100 ),
					'page_location'    => substr( $shared_post_permalink, 0, 100 ),
					'page_referrer'    => substr( $referrer_url, 0, 100 ),
					'shared_post_id'   => substr( (string) $shared_post_id, 0, 100 ),
					'shared_post_slug' => substr( rawurlencode( $shared_post->post_name ), 0, 100 ),
					'shared_post_url'  => substr( $shared_post_permalink, 0, 100 ),
				],
			],
		],
	];

	wp_remote_post(
		$base_url,
		[
			'body'    => wp_json_encode( $payload ),
			// Bound the worker-hold time on this public endpoint; the response
			// body is never read.
			'timeout' => 2,
		]
	);
}

/**
 * Whether a view of a shared post should be counted for this client.
 *
 * Deduplicates repeat hits from the same client on the same post FROM THE SAME
 * REPUBLISHER within a time window, so prefetches, reloads, and cache replays
 * don't inflate the counter. The referrer is part of the key because the
 * counter itself is keyed per referrer: the tool's value is per-republisher
 * attribution, so the same reader opening the story on a second republisher
 * counts on that republisher rather than being absorbed by the first.
 *
 * Without a client ID there is nothing to key on, so the view counts —
 * bot filtering is the guard for cookie-less clients.
 *
 * @param int    $post_id      The shared post ID.
 * @param string $client_id    The client ID extracted from cookies.
 * @param string $referrer_url The referrer the view arrived from.
 * @param int    $window       Dedup window in seconds. Defaults to 30 minutes,
 *                             matching the session windows of GA4 and Parse.ly.
 * @return bool True if the view should be counted.
 */
function wprtt_should_count_view( int $post_id, string $client_id, string $referrer_url = '', int $window = 30 * MINUTE_IN_SECONDS ): bool {
	if ( '' === $client_id ) {
		return true;
	}
	$key = 'wprtt_view_' . $post_id . '_' . md5( $client_id . '|' . $referrer_url );

	if ( wp_using_ext_object_cache() ) {
		// wp_cache_add is atomic: when two simultaneous pixel loads race, only
		// one add succeeds, so only one counts. Eviction under memory pressure
		// fails open — an extra count, the same direction as no dedup.
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- $window defaults to 30 minutes and is typed int; the sniff just can't resolve the parameter statically.
		if ( wp_cache_add( $key, 1, 'wprtt_views', $window ) ) {
			return true;
		}
		// add() also returns false when the cache backend itself is down — and
		// that must fail open (count), or an unhealthy backend silently drives
		// the guarded count to zero and corrupts the pilot comparison. A miss
		// on the follow-up get distinguishes "already counted" from "broken".
		return false === wp_cache_get( $key, 'wprtt_views' );
	}

	// No persistent object cache (this plugin is also distributed on
	// WordPress.org): fall back to transients. Two options rows per counted
	// view (autoload=no, reaped by WP's daily expired-transients cleanup) is a
	// conscious trade-off — the alternative is no dedup at all on such hosts.
	if ( get_transient( $key ) ) {
		return false;
	}
	set_transient( $key, 1, $window );
	return true;
}
