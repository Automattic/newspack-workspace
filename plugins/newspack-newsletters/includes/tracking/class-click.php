<?php
/**
 * Newspack Newsletters Click-Tracking.
 *
 * @package Newspack
 */

namespace Newspack_Newsletters\Tracking;

use Newspack_Newsletters\Ads;

/**
 * Tracking Click-Tracking Class.
 */
final class Click {
	const QUERY_VAR = 'np_newsletters_click';

	/**
	 * Query parameter forwarded by the click proxy in addition to UTMs.
	 * Mirrors `Newspack\Newsletters_Access::QUERY_PARAM` in
	 * the foundation plugin — must stay in sync. We can't import the
	 * foundation constant directly without coupling the two plugins.
	 */
	const FORWARDED_NPNL_PARAM = 'npnl';

	/**
	 * Initialize hooks.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'rewrite_rule' ] );
		\add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		\add_action( 'init', [ __CLASS__, 'handle_click' ], 2, 0 ); // Run on priority 2 to allow Data Events and ActionScheduler to initialize first.
		\add_action( 'template_redirect', [ __CLASS__, 'handle_click' ] );
		\add_filter( 'newspack_newsletters_process_link', [ __CLASS__, 'process_link' ], 10, 3 );
	}

	/**
	 * Add rewrite rule for tracking url.
	 *
	 * Backwards compatibility for old tracking URLs.
	 *
	 * @codeCoverageIgnore
	 */
	public static function rewrite_rule() {
		\add_rewrite_rule( 'np-newsletters-click', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		\add_rewrite_tag( '%' . self::QUERY_VAR . '%', '1' );
		$check_option_name = 'newspack_newsletters_tracking_click_has_rewrite_rule';
		if ( ! \get_option( $check_option_name ) ) {
			\flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
			\add_option( $check_option_name, true );
		}
	}

	/**
	 * Add query vars.
	 *
	 * @codeCoverageIgnore
	 *
	 * @param array $vars Query vars.
	 *
	 * @return array
	 */
	public static function query_vars( $vars = [] ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Get tracking URL.
	 *
	 * Formerly 'home_url( 'np-newsletters-click' );'
	 *
	 * @return string
	 */
	public static function get_tracking_url() {
		return \add_query_arg( [ self::QUERY_VAR => 1 ], \home_url() );
	}

	/**
	 * Get proxied URL.
	 *
	 * @param int    $ad_id         Ad post ID (encoded as the tracked `id`).
	 * @param string $url           Destination URL.
	 * @param int    $newsletter_id Source newsletter post ID (encoded as `nid`), when known.
	 *
	 * @return string Proxied URL.
	 */
	public static function get_proxied_url( $ad_id, $url, $newsletter_id = 0 ) {
		$args = [
			'id'  => $ad_id,
			'url' => urlencode( $url ),
			'em'  => Utils::get_email_address_tag(),
		];
		// Carry the source newsletter so the click can be attributed to it (the ad
		// alone can't tell us which newsletter it was clicked from).
		if ( $newsletter_id > 0 ) {
			$args['nid'] = $newsletter_id;
		}
		return add_query_arg( $args, self::get_tracking_url() );
	}

	/**
	 * Process link.
	 *
	 * @param string   $url           Processed URL.
	 * @param string   $original_url  Original URL.
	 * @param \WP_Post $post          Newsletter post object.
	 *
	 * @return string
	 */
	public static function process_link( $url, $original_url, $post ) {
		if ( ! Admin::is_tracking_click_enabled() ) {
			return $url;
		}
		if ( ! $post ) {
			return $url;
		}
		if ( $post->post_type !== Ads::CPT ) {
			return $url;
		}
		// The renderer tracks the newsletter currently being rendered; capture it so
		// the click can be attributed to the source newsletter. Guarded defensively.
		$newsletter_id = 0;
		if ( class_exists( '\Newspack_Newsletters_Renderer' ) && ! empty( \Newspack_Newsletters_Renderer::$newsletter_id ) ) {
			$newsletter_id = (int) \Newspack_Newsletters_Renderer::$newsletter_id;
		}
		return self::get_proxied_url( $post->ID, $url, $newsletter_id );
	}

	/**
	 * Track click.
	 *
	 * Only ad links are proxied, so the tracked id is the clicked ad's post ID.
	 *
	 * @param int    $ad_id         Clicked ad post ID.
	 * @param string $email_address Email address.
	 * @param string $url           Destination URL.
	 * @param int    $newsletter_id Source newsletter post ID, when known (0 otherwise).
	 *
	 * @return void
	 */
	public static function track_click( $ad_id, $email_address, $url, $newsletter_id = 0 ) {
		if ( ! $ad_id || ! $email_address || ! Admin::is_tracking_click_enabled() ) {
			return;
		}

		$clicks = \get_post_meta( $ad_id, 'tracking_clicks', true );
		if ( ! $clicks ) {
			$clicks = 0;
		}
		$clicks++;
		\update_post_meta( $ad_id, 'tracking_clicks', $clicks );

		// Also record a dated row so clicks can be reported over a timeframe, attributed
		// to the source newsletter when known (0 falls back to the click sentinel).
		Ad_Stats::record_clicks( $ad_id, $newsletter_id, 1 );

		/**
		 * Fires when a click is tracked.
		 *
		 * @param int    $ad_id         Clicked ad post ID.
		 * @param string $email_address Email address.
		 * @param string $url           Destination URL.
		 * @param int    $newsletter_id Source newsletter post ID, when known (0 otherwise).
		 */
		do_action( 'newspack_newsletters_tracking_click', $ad_id, $email_address, $url, $newsletter_id );
	}

	/**
	 * Handle proxied URL click and redirect to destination.
	 *
	 * @param bool $with_redirect Whether to redirect after tracking the link click. This is for testing convenience.
	 */
	public static function handle_click( $with_redirect = true ) {
		if ( ! \get_query_var( self::QUERY_VAR ) && ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$ad_id         = \intval( $_GET['id'] ?? 0 );
		$newsletter_id = \intval( $_GET['nid'] ?? 0 );
		$email_address = \sanitize_email( $_GET['em'] ?? '' );
		// We need to decode the URL before redirecting, as it may contain encoded characters.
		$url = html_entity_decode( esc_url_raw( \wp_unslash( $_GET['url'] ?? '' ) ) );
		// phpcs:enable

		$is_admin_user = current_user_can( 'edit_others_posts' );
		// If the redirect URL does not point to the site, double-check it is actually a URL within the email.
		$url_without_query_args = untrailingslashit( strtok( $url, '?' ) );
		if ( false === strpos( $url_without_query_args, \get_site_url() ) ) {
			// The tracked id is the ad post; its rendered email HTML holds the link.
			$ad_content = (string) get_post_meta( $ad_id, 'newspack_email_html', true );
			if (
				false === stripos( $ad_content, $url_without_query_args ) &&
				false === stripos( $ad_content, urlencode( $url_without_query_args ) ) // URL might be encoded via a block pattern.
			) {
				\wp_die( 'Invalid URL', '', 400 );
				exit;
			}
		}

		/**
		 * The ESP tracking functionality may add UTM parameters to our proxied URL,
		 * let's pass them along to the destination URL. The 'npnl' param is the
		 * Newspack content-gate newsletter pass signature added by the foundation
		 * plugin's Newsletters_Access class and must survive the proxy redirect
		 * so the destination site can verify it.
		 */
		$forwarded_params = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', self::FORWARDED_NPNL_PARAM ];
		foreach ( $forwarded_params as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$url = \add_query_arg( $param, \sanitize_text_field( \wp_unslash( $_GET[ $param ] ) ), $url ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		if ( ! $url || ! \wp_http_validate_url( $url ) ) {
			\wp_die( 'Invalid URL', '', 400 );
			exit;
		}

		// Don't track if the user is a logged-in editor or admin user.
		if ( ! $is_admin_user ) {
			self::track_click( $ad_id, $email_address, $url, $newsletter_id );
		}

		if ( $with_redirect ) {
			\wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
	}
}
Click::init();
