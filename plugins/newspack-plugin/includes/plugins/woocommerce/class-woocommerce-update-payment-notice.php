<?php
/**
 * Newspack WooCommerce Update Payment Notice.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Class for the update payment notice.
 */
class WooCommerce_Update_Payment_Notice {

	const NOTICE_TIMESTAMP_KEY = 'newspack_payment_notice';
	const NOTICE_INTERVAL = 60 * 60 * 24; // 24 hours.

	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'wp', [ __CLASS__, 'maybe_add_wc_notices' ] );
		add_action( 'wp_footer', [ __CLASS__, 'maybe_add_newspack_notices' ] );
		add_action( 'newspack_ui_notice_dismissed', [ __CLASS__, 'handle_notice_dismissed' ] );
	}

	/**
	 * Maybe add WC notices.
	 */
	public static function maybe_add_wc_notices() {
		// Only for My Account UI v1 and above.
		if ( version_compare( WooCommerce_My_Account::get_version(), '1.0.0', '<' ) ) {
			return;
		}

		// Only use WC notices on account pages.
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		// Only display the notice if there are no other notices.
		if ( ! empty( wc_get_notices() ) ) {
			return;
		}

		$notices = self::get_notices();
		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			wc_add_notice( $notice, 'notice' );
		}
	}

	/**
	 * Get notice dismiss timestamp.
	 *
	 * @param string $notice_id The ID of the notice.
	 *
	 * @return int The timestamp.
	 */
	private static function get_notice_dismiss_timestamp( $notice_id ) {
		return get_user_meta( wp_get_current_user()->ID, self::NOTICE_TIMESTAMP_KEY . '_' . $notice_id, true );
	}

	/**
	 * Maybe add Newspack UI snackbar.
	 */
	public static function maybe_add_newspack_notices() {
		// Only for My Account UI v1 and above.
		if ( version_compare( WooCommerce_My_Account::get_version(), '1.0.0', '<' ) ) {
			return;
		}

		// Under "My Account" page we use WC notices.
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return;
		}

		// Don't show notices on the checkout page.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return;
		}

		// Don't show in modal checkout frame.
		if ( method_exists( 'Newspack_Blocks\Modal_Checkout', 'is_modal_checkout' ) && \Newspack_Blocks\Modal_Checkout::is_modal_checkout() ) {
			return;
		}

		$notices = self::get_notices();
		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$notice_id = md5( $notice );
			$timestamp = self::get_notice_dismiss_timestamp( $notice_id );
			if ( $timestamp && $timestamp > time() - self::NOTICE_INTERVAL ) {
				continue;
			}

			Newspack_UI::add_notice(
				$notice,
				[
					'id'       => $notice_id,
					'type'     => 'warning',
					'corner'   => 'top-right',
					'autohide' => false,
				]
			);
		}
	}

	/**
	 * Get the current user notices for subscriptions that need payment.
	 *
	 * @return string[] The notices.
	 */
	private static function get_notices() {
		if ( ! function_exists( 'wcs_get_subscriptions' ) || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		if ( ! is_user_logged_in() ) {
			return [];
		}

		$subscriptions = wcs_get_subscriptions(
			[
				'customer_id' => wp_get_current_user()->ID,
			]
		);

		$notices = [];

		foreach ( $subscriptions as $subscription ) {
			if ( 'cancelled' === $subscription->get_status() ) {
				continue;
			}
			if ( ! $subscription->needs_payment() ) {
				continue;
			}

			$line_items = $subscription->get_items();
			if ( empty( $line_items ) ) {
				continue;
			}
			$line_item  = reset( $line_items );
			$product    = wc_get_product( $line_item->get_product_id() );
			// If the product has a parent, use the parent product.
			if ( $product->get_parent_id() ) {
				$product = wc_get_product( $product->get_parent_id() );
			}
			// If the product belongs to a grouped product, use the grouped product.
			if ( class_exists( 'WC_Subscriptions_Product' ) && method_exists( 'WC_Subscriptions_Product', 'get_visible_grouped_parent_product_ids' ) ) {
				$parent = \WC_Subscriptions_Product::get_visible_grouped_parent_product_ids( $product );
				if ( ! empty( $parent ) ) {
					$product = wc_get_product( reset( $parent ) );
				}
			}
			// Check if there's another active subscription of the same grouped or variable product.
			$active_subscriptions = WooCommerce_Subscriptions::get_user_subscription( $product );
			if ( $active_subscriptions ) {
				continue;
			}

			$is_donation = Donations::is_donation_product( $product->get_id() );

			$link_attrs = [];
			$url = $subscription->get_view_order_url();

			// If we have a last order that needs payment, we can use the checkout payment URL.
			$last_order = wc_get_order( $subscription->get_last_order() );
			if ( $last_order && $last_order->needs_payment() ) {
				$url = $last_order->get_checkout_payment_url();
				$link_attrs = [
					'class'                => 'pay',
					'data-action'          => $is_donation ? 'donation_renewal' : 'subscription_renewal',
					'data-subscription-id' => $subscription->get_id(),
				];
			}

			$notice = self::get_message( $subscription ) . ' ' . sprintf(
					/* translators: %1$s: action URL, %2$s: link attributes */
				__( 'Please <a href="%1$s" %2$s>update your payment method</a>.', 'newspack-plugin' ),
				esc_url( $url ),
				implode(
					' ',
					array_map(
						function( $key, $value ) {
							return sprintf( '%s="%s"', $key, $value );
						},
						array_keys( $link_attrs ),
						array_values( $link_attrs )
					)
				)
			);
			$notices[] = $notice;
			add_filter(
				'newspack_ui_notice_is_urgent',
				function( $is_urgent, $printed_notice ) use ( $notice ) {
					if ( $printed_notice === $notice ) {
						$is_urgent = true;
					}
					return $is_urgent;
				},
				10,
				2
			);
		}

		return $notices;
	}

	/**
	 * Get the notice message given a subscription.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return string The notice message.
	 */
	protected static function get_message( $subscription ) {
		$product = array_values( $subscription->get_items() )[0]->get_product();
		$is_donation = Donations::is_donation_product( $product->get_id() );
		if ( $is_donation ) {
			$message = sprintf(
				/* translators: %s: donation formatted value */
				__( 'Your recurring donation of %s has stopped.', 'newspack-plugin' ),
				$subscription->get_formatted_order_total()
			);
		} else {
			$message = sprintf(
				/* translators: %s: subscription product name */
				__( 'Your “%s” subscription is not active.', 'newspack-plugin' ),
				$product->get_name()
			);
		}

		return $message;
	}

	/**
	 * Dismiss a Newspack notice.
	 *
	 * @param string $notice_id The ID of the notice that was dismissed.
	 */
	public static function handle_notice_dismissed( $notice_id ) {
		update_user_meta( wp_get_current_user()->ID, self::NOTICE_TIMESTAMP_KEY . '_' . $notice_id, time() );
	}
}
WooCommerce_Update_Payment_Notice::init();
