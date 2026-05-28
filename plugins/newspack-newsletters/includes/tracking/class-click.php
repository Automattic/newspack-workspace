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
	 * @param int    $newsletter_id Newsletter ID.
	 * @param string $url           Destination URL.
	 *
	 * @return string Proxied URL.
	 */
	public static function get_proxied_url( $newsletter_id, $url ) {
		return add_query_arg(
			[
				'id'  => $newsletter_id,
				'url' => urlencode( $url ),
				'em'  => Utils::get_email_address_tag(),
			],
			self::get_tracking_url()
		);
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
		return self::get_proxied_url( $post->ID, $url );
	}

	/**
	 * Track click.
	 *
	 * @param int    $newsletter_id Newsletter ID.
	 * @param string $email_address Email address.
	 * @param string $url           Destination URL.
	 *
	 * @return void
	 */
	public static function track_click( $newsletter_id, $email_address, $url ) {
		if ( ! $newsletter_id || ! $email_address || ! Admin::is_tracking_click_enabled() ) {
			return;
		}

		$clicks = \get_post_meta( $newsletter_id, 'tracking_clicks', true );
		if ( ! $clicks ) {
			$clicks = 0;
		}
		$clicks++;
		\update_post_meta( $newsletter_id, 'tracking_clicks', $clicks );

		/**
		 * Fires when a click is tracked.
		 *
		 * @param int    $newsletter_id Newsletter ID.
		 * @param string $url           Destination URL.
		 */
		do_action( 'newspack_newsletters_tracking_click', $newsletter_id, $email_address, $url );
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
		$newsletter_id = \intval( $_GET['id'] ?? 0 );
		$email_address = \sanitize_email( $_GET['em'] ?? '' );
		// We need to decode the URL before redirecting, as it may contain encoded characters.
		$url = html_entity_decode( esc_url_raw( \wp_unslash( $_GET['url'] ?? '' ) ) );
		// phpcs:enable

		$is_admin_user = current_user_can( 'edit_others_posts' );
		// If the redirect URL does not point to the site, double-check it is actually a URL within the email.
		$url_without_query_args = untrailingslashit( strtok( $url, '?' ) );
		if ( false === strpos( $url_without_query_args, \get_site_url() ) ) {
			$newsletter_content = (string) get_post_meta( $newsletter_id, 'newspack_email_html', true );
			if (
				false === stripos( $newsletter_content, $url_without_query_args ) &&
				false === stripos( $newsletter_content, urlencode( $url_without_query_args ) ) // URL might be encoded via a block pattern.
			) {
				\wp_die( 'Invalid URL', '', 400 );
				exit;
			}
		}

		/**
		 * The ESP tracking functionality may add UTM parameters to our proxied URL,
		 * let's pass them along to the destination URL.
		 */
		$utm_params = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term' ];
		foreach ( $utm_params as $utm_param ) {
			if ( isset( $_GET[ $utm_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$url = \add_query_arg( $utm_param, \sanitize_text_field( \wp_unslash( $_GET[ $utm_param ] ) ), $url ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		if ( ! $url || ! \wp_http_validate_url( $url ) ) {
			\wp_die( 'Invalid URL', '', 400 );
			exit;
		}

		// Don't track if the user is a logged-in editor or admin user.
		if ( ! $is_admin_user ) {
			self::track_click( $newsletter_id, $email_address, $url );
		}

		if ( $with_redirect ) {
			\wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
	}
}
Click::init();
