<?php
/**
 * WooCommerce Subscriptions renewal class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Main class.
 */
class Renewal {
	/**
	 * Renewal endpoint.
	 *
	 * @var string
	 */
	const RENEWAL_ENDPOINT = 'renew-subscription';

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		if ( ! WooCommerce_Subscriptions::is_active() ) {
			return;
		}

		add_action( 'init', [ __CLASS__, 'add_renewal_endpoint' ] );
		if ( ! is_admin() ) {
			add_filter( 'woocommerce_get_query_vars', [ __CLASS__, 'add_renewal_query_var' ] );
			add_filter( 'pre_get_posts', [ __CLASS__, 'maybe_redirect_renewal_endpoint' ] );
		}
	}

	/**
	 * Add renewal endpoint.
	 */
	public static function add_renewal_endpoint() {
		add_rewrite_endpoint( self::RENEWAL_ENDPOINT, EP_ROOT | EP_PAGES );
		self::flush_rewrite_rules();
	}

	/**
	 * Add renewal query var.
	 *
	 * @param array $query_vars Query vars.
	 *
	 * @return array
	 */
	public static function add_renewal_query_var( $query_vars ) {
		$query_vars[ self::RENEWAL_ENDPOINT ] = self::RENEWAL_ENDPOINT;
		return $query_vars;
	}

	/**
	 * Get the URL for the My Account > Subscriptions page.
	 *
	 * @return string
	 */
	public static function get_subscriptions_url() {
		return wc_get_account_endpoint_url( 'subscriptions' );
	}

	/**
	 * Returns true when on the My Account > Subscriptions front end page.
	 *
	 * @return bool
	 */
	public static function is_subscriptions_page() {
		if ( ! WooCommerce_Subscriptions::is_active() ) {
			return false;
		}
		return is_wc_endpoint_url( 'subscriptions' );
	}

	/**
	 * Conditionally redirects the renewal endpoint url.
	 *
	 * @param \WP_Query $query Query object.
	 */
	public static function maybe_redirect_renewal_endpoint( $query ) { // phpcs:ignore WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.VoidReturn, WordPressVIPMinimum.Hooks.AlwaysReturnInFilter.MissingReturnStatement
		if (
			! $query->is_main_query() ||
			! isset( $query->query_vars[ self::RENEWAL_ENDPOINT ] )
		) {
			return;
		}
		$redirect_url = wc_get_account_endpoint_url( 'subscriptions' );
		if ( is_user_logged_in() ) {
			$pending_renewal = wcs_get_subscriptions(
				[
					'customer_id'         => get_current_user_id(),
					'subscription_status' => [
						'pending',
						'on-hold',
					],
				]
			);
			if ( count( $pending_renewal ) === 1 ) {
				$orders = array_pop( $pending_renewal )->get_related_orders( 'all', 'renewal' );
				foreach ( $orders as $order ) {
					if ( $order->needs_payment() ) {
						$redirect_url = $order->get_checkout_payment_url();
						break;
					}
				}
			}
		} else {
			$redirect_url .= '?redirect=' . wc_get_account_endpoint_url( self::RENEWAL_ENDPOINT );
		}
		wp_safe_redirect( $redirect_url );
		exit();
	}

	/**
	 * Refresh permalinks the first time this feature is enabled.
	 */
	private static function flush_rewrite_rules() {
		if ( ! get_option( 'newspack_subscriptions_renewal_permalinks_refreshed', false ) ) {
			flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
			update_option( 'newspack_subscriptions_renewal_permalinks_refreshed', true );
		}
	}
}
