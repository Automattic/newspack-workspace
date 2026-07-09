<?php
/**
 * Tracking pixel counting guards: bot filtering and per-client deduplication.
 *
 * These run on every pixel request, before the view counter and the GA4
 * Measurement Protocol event, so filtered hits affect neither number.
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Whether the counting guards (bot filtering, deduplication, uncacheable
 * responses) are enabled.
 *
 * Off by default for a gradual rollout: enabling changes what the "views"
 * number means, and counts visibly drop. Enable per site (or fleet-wide via a
 * managed define) with the WPRTT_COUNTING_GUARDS_ENABLED constant, or in code
 * via the filter. When off, the pixel counts exactly as it always has.
 *
 * @return bool True if the counting guards are enabled.
 */
function wprtt_counting_guards_enabled() {
	$enabled = defined( 'WPRTT_COUNTING_GUARDS_ENABLED' ) && WPRTT_COUNTING_GUARDS_ENABLED;

	/**
	 * Filters whether the pixel counting guards are enabled.
	 *
	 * @param bool $enabled Whether the guards are enabled.
	 */
	return (bool) apply_filters( 'wprtt_counting_guards_enabled', $enabled );
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
function wprtt_is_bot_request( $user_agent ) {
	$user_agent = trim( (string) $user_agent );
	if ( '' === $user_agent ) {
		return true;
	}
	$bot_pattern = '/(?<!cu)bot|crawl|spider|slurp|preview|externalhit|feedfetcher|embedly|quora link|outbrain|pinterest|vkshare|validator|whatsapp|telegram|skypeuripreview|nuzzel|discordapp|qwantify|bitlybot|scanner|scrape|curl|wget|python|libwww|httpunit|nutch|go-http-client|java\/|okhttp|phantomjs|headlesschrome|lighthouse|pingdom|gtmetrix|uptimerobot|statuscake|newspaper|monitor/i';
	return (bool) preg_match( $bot_pattern, $user_agent );
}

/**
 * Generate a random client ID string and set the newspack-cid fallback cookie if not set.
 *
 * @return string Randomly generated client ID.
 */
function wprtt_create_cid_cookie_if_not_set() {
	$cid = (string) wp_rand( 100000000, 999999999 );

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
	setcookie( 'newspack-cid', $cid, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, true );

	return $cid;
}

/**
 * Extracts the Client ID from the _ga cookie
 *
 * @return ?string
 */
function wprtt_extract_cid_from_cookies() {
	if ( isset( $_COOKIE['_ga'] ) ) {
		$cookie_pieces = explode( '.', $_COOKIE['_ga'], 3 ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// A well-formed cookie (GA1.2.<cid>) yields the third piece; malformed
		// values with fewer pieces still yield their last piece, never null.
		return end( $cookie_pieces );
	}

	if ( isset( $_COOKIE['newspack-cid'] ) ) {
		return sanitize_text_field( wp_unslash( $_COOKIE['newspack-cid'] ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
	}
	return wprtt_create_cid_cookie_if_not_set();
}

/**
 * Get the identity used to deduplicate views from the same client.
 *
 * Prefers the analytics client ID when the request carries one. Cross-site
 * pixel requests usually don't — browsers withhold SameSite=Lax cookies on
 * third-party image loads — so the fallback is a hash of IP + user agent,
 * which is available on every request. Nothing is stored beyond the hashed
 * transient key.
 *
 * @return string Dedup identity, or empty string when nothing is available.
 */
function wprtt_get_dedup_identity() {
	if ( isset( $_COOKIE['_ga'] ) || isset( $_COOKIE['newspack-cid'] ) ) {
		return (string) wprtt_extract_cid_from_cookies();
	}
	// phpcs:disable WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- The pixel response is explicitly uncacheable (no-store + batcache_cancel), so per-client logic is safe here.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	// phpcs:enable
	if ( '' === $ip && '' === $ua ) {
		return '';
	}
	return 'ipua_' . md5( wp_salt( 'auth' ) . '|' . $ip . '|' . $ua );
}

/**
 * Whether a view of a shared post should be counted for this client.
 *
 * Deduplicates repeat hits from the same client on the same post within a
 * time window, so prefetches, reloads, and cache replays don't inflate the
 * counter. Uses a transient keyed on post + client ID.
 *
 * Without a client ID there is nothing to key on, so the view counts —
 * bot filtering is the guard for cookie-less clients.
 *
 * @param int    $post_id   The shared post ID.
 * @param string $client_id The client ID extracted from cookies.
 * @param int    $window    Dedup window in seconds. Defaults to 30 minutes,
 *                          matching the session windows of GA4 and Parse.ly.
 * @return bool True if the view should be counted.
 */
function wprtt_should_count_view( $post_id, $client_id, $window = 30 * MINUTE_IN_SECONDS ) {
	$client_id = (string) $client_id;
	if ( '' === $client_id ) {
		return true;
	}
	$transient_key = 'wprtt_view_' . absint( $post_id ) . '_' . md5( $client_id );
	if ( get_transient( $transient_key ) ) {
		return false;
	}
	set_transient( $transient_key, 1, $window );
	return true;
}
