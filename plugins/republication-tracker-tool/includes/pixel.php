<?php
/**
 * Tracking pixel endpoint.
 *
 * Thin request handler: every counting decision lives in named, testable
 * functions in pixel-functions.php. This file only reads the request, calls
 * them, and emits the image.
 *
 * @package Republication_Tracker_Tool
 */

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
		// For page caches other than batcache: the batcache Newspack ships
		// never reads this constant, so on that stack the protection rests on
		// batcache_cancel() above.
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! headers_sent() ) {
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	}
}

// The pixel endpoint is public: requests without a resolvable post still get
// the image below, but there is nothing to count (and never a fallback to an
// unrelated post — see wprtt_resolve_shared_post()).
$wprtt_shared_post = wprtt_resolve_shared_post( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( $wprtt_shared_post instanceof WP_Post ) {
	$wprtt_referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

	// If the request is coming from WP Admin, bail out (when the copied content is inserted into the WP editor, the pixel will be pinged).
	if ( false !== stripos( $wprtt_referrer, '/wp-admin/' ) ) {
		exit;
	}

	$wprtt_ga4_param = isset( $_GET['ga4'] ) ? sanitize_text_field( wp_unslash( $_GET['ga4'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	wprtt_record_view( $wprtt_shared_post, $wprtt_referrer, $wprtt_ga4_param, $wprtt_guards_enabled );
}

if ( ! headers_sent() ) {
	header( 'Content-Type: image/png' );
}
// A transparent 1x1 px PNG image.
echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII=' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit;
