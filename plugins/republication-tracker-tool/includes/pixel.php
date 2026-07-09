<?php
/**
 * Tracking pixel.
 *
 * @package Republication_Tracker_Tool
 */

/**
 * Function to get the title of the referring url.
 *
 * @param string $url URL of the referrer.
 * @return string Title of the referring URL, or empty string if we can't find it.
 */
function wprtt_get_referring_page_title( $url ) {
	$response = function_exists( 'vip_safe_wp_remote_get' ) ? vip_safe_wp_remote_get( $url ) : wp_remote_get( $url ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get

	$title = '';

	// if there was no issue grabbing the url, grab the title.
	if ( ! is_wp_error( $response ) ) {

		// find the title element inside of the response body.
		$response = preg_match( '/<title[^>]*>(.*)<\/title>/iU', $response['body'], $title_matches );

		// if a title element was found, let's get the text from it.
		if ( $title_matches ) {

			// clean up title: remove EOL's and excessive whitespace.
			$title = preg_replace( '/\s+/', ' ', $title_matches[1] );
			$title = trim( $title );
			$title = rawurlencode( $title );

			// return our found title.
			$title = urldecode( $title );
		}
	}

	return $title;
}

// Counting guards (bot filtering, dedup, uncacheable responses) are gated for
// a gradual rollout — see wprtt_counting_guards_enabled(). When off, the pixel
// behaves exactly as it always has.
$wprtt_guards_enabled = wprtt_counting_guards_enabled();

if ( $wprtt_guards_enabled ) {
	// The pixel response must never be cached: a page/edge cache serving the image
	// absorbs or replays hits and skews the view counter in either direction.
	if ( function_exists( 'batcache_cancel' ) ) {
		batcache_cancel();
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
}

$wprtt_user_agent = '';
if ( $wprtt_guards_enabled ) {
	// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- Only read when the guards are on, which makes the pixel response uncacheable (no-store + batcache_cancel above).
	$wprtt_user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
}

// The pixel endpoint is public: unknown or deleted post IDs still get the
// image below, but there is nothing to count.
$wprtt_shared_post = isset( $_GET['post'], $_GET['ga4'] ) ? get_post( absint( $_GET['post'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Non-ga4 hits (bots, crawlers) skip this block entirely. No counter update, no DB writes.
// The wp-admin referrer bailout below is therefore only needed within this block.
// Only update share tracking when a ga4 param is present (real pixel fires from configured republishers).
// Bot, crawler, and link-preview requests are served the image but never counted.
if ( $wprtt_shared_post instanceof WP_Post && ( ! $wprtt_guards_enabled || ! wprtt_is_bot_request( $wprtt_user_agent ) ) ) {

	// set up all of our post vars we want to track.
	$shared_post    = $wprtt_shared_post;
	$shared_post_id = $shared_post->ID;

	$shared_post_slug      = rawurlencode( $shared_post->post_name );
	$shared_post_permalink = get_permalink( $shared_post_id );

	if ( array_key_exists( 'HTTP_REFERER', $_SERVER ) ) {
		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$url = esc_url_raw( $_SERVER['HTTP_REFERER'] );
		}

		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		$url_path = wp_parse_url( $url, PHP_URL_PATH );

	} else {

		$url      = '';
		$url_host = '';

	}

	// If the request is coming from WP Admin, bail out (when the copied content is inserted into the WP editor, the pixel will be pinged).
	if ( false !== stripos( $url, '/wp-admin/' ) ) {
		exit;
	}

	// Deduplicate repeat views: the same client viewing the same shared post
	// within the window counts once, for both the counter and the GA4 event.
	// The dedup identity falls back to IP + user agent, since cross-site pixel
	// requests usually arrive without cookies; the GA4 client ID below keeps
	// the original cookie-or-generated behavior.
	if ( ! $wprtt_guards_enabled || wprtt_should_count_view( $shared_post_id, wprtt_get_dedup_identity() ) ) {
		$wprtt_client_id = wprtt_extract_cid_from_cookies();
		// The title fetch is a blocking outbound request only the GA4 event needs,
		// so it runs only for views that actually count.
		$url_title = '' !== $url ? wprtt_get_referring_page_title( $url ) : '';
		$value = get_post_meta( $shared_post_id, 'republication_tracker_tool_sharing', true );
		if ( $value ) {
			if ( isset( $value[ $url ] ) ) {
				$value[ $url ]++;
			} else {
				$value[ $url ] = 1;
			}
		} else {
			$value = array(
				$url => 1,
			);
		}
		update_post_meta( $shared_post_id, 'republication_tracker_tool_sharing', $value );

		// If we have the necessary GA4 info, let's push data to it.
		// We need both a Measurement ID and an API secret for GA4.
		// https://developers.google.com/analytics/devguides/collection/protocol/ga4/sending-events?client_type=gtag#required_parameters.
		$ga4_id     = get_option( 'republication_tracker_tool_analytics_ga4_id' );
		$ga4_secret = get_option( 'republication_tracker_tool_analytics_ga4_secret', false );

		if ( $ga4_id && $ga4_secret && isset( $_GET['ga4'] ) && $_GET['ga4'] === $ga4_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$base_url = add_query_arg(
				[
					'api_secret'     => $ga4_secret,
					'measurement_id' => $ga4_id,
				],
				'https://www.google-analytics.com/mp/collect'
			);
			$payload  = [
				'client_id' => $wprtt_client_id,
				'events'    => [
					[
						'name'   => 'page_view',
						// Params for page_view events: https://developers.google.com/analytics/devguides/collection/ga4/views?client_type=gtag.
						'params' => [
							'page_title'       => substr( $url_title, 0, 100 ),
							'page_location'    => substr( $shared_post_permalink, 0, 100 ),
							'page_referrer'    => substr( $url, 0, 100 ),
							'shared_post_id'   => substr( $shared_post->ID, 0, 100 ),
							'shared_post_slug' => substr( $shared_post_slug, 0, 100 ),
							'shared_post_url'  => substr( $shared_post_permalink, 0, 100 ),
						],
					],
				],
			];

			wp_remote_post(
				$base_url,
				[
					'body' => wp_json_encode( $payload ),
				]
			);
		}
	}
}

header( 'Content-Type: image/png' );
// A transparent 1x1 px .gif image.
echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII=' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit;
