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
	 * Subscription statuses for which the "needs payment" notice is actionable.
	 * Only states where paying actually restores the subscription. Terminal
	 * statuses (expired, cancelled, switched) and states where the reader still
	 * has access (active, pending-cancel) are intentionally excluded. NPPM-2926.
	 *
	 * Closed set of core WCS statuses. A publisher's custom "recoverable but
	 * unpaid" status would not match, so readers in it would silently miss the
	 * notice; both callers fail closed, so the only effect is a missed nudge,
	 * never a wrong action. Extend this constant (or add a filter) only if such
	 * a status is found in the field.
	 *
	 * @var string[]
	 */
	const NOTICE_RECOVERABLE_STATUSES = [ 'on-hold', 'pending' ];

	/**
	 * My Account endpoints that show the notice. Order pages are left out: an
	 * unpaid order's page asks for its own payment, and a paid one needs nothing.
	 * A subscription's page shows only that subscription's notice.
	 *
	 * @var string[]
	 */
	const NOTICE_ACCOUNT_ENDPOINTS = [ 'edit-account', 'subscriptions', 'view-subscription', 'orders' ];

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

		if ( ! self::is_notice_endpoint() ) {
			return;
		}

		// Fetched first so a notice still queued from an earlier request is marked urgent too.
		$notices = self::get_notices();
		if ( empty( $notices ) ) {
			return;
		}

		// Only display the notice if there are no other notices.
		if ( ! empty( wc_get_notices() ) ) {
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

		$viewed_subscription_id = \absint( \get_query_var( 'view-subscription' ) );

		foreach ( $subscriptions as $subscription ) {
			if ( $viewed_subscription_id && $viewed_subscription_id !== (int) $subscription->get_id() ) {
				continue;
			}
			// Only nag for statuses where paying can restore the subscription (NPPM-2926);
			// see NOTICE_RECOVERABLE_STATUSES for why terminal/has-access statuses are excluded.
			if ( ! $subscription->has_status( self::NOTICE_RECOVERABLE_STATUSES ) ) {
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
			// Variation-accurate purchased product for membership-plan lookup;
			// $product gets reassigned to the grouped/parent below for Layer 1.
			$purchased_product = $line_item->get_product();
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
			// Also suppress when the reader holds equivalent active access (same
			// membership plan via another active subscription, or active
			// membership) bought through a different product. Pass this
			// subscription's ID so an active membership derived from it is not
			// mistaken for independent access. NPPM-2926.
			if ( $purchased_product && Memberships::user_has_equivalent_active_access( $purchased_product, null, $subscription->get_id() ) ) {
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
					if ( self::normalize_notice( $printed_notice ) === self::normalize_notice( $notice ) ) {
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
	 * Reduce a notice to its plain text, so a notice matches the same notice after
	 * WooCommerce has sanitized its markup.
	 *
	 * @param string $notice The notice markup.
	 *
	 * @return string The normalized text.
	 */
	private static function normalize_notice( $notice ) {
		return preg_replace( '/\s+/', ' ', trim( html_entity_decode( \wp_strip_all_tags( $notice ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Whether the current My Account endpoint is one that shows the notice.
	 *
	 * @return bool
	 */
	public static function is_notice_endpoint() {
		global $wp;
		$query_vars = $wp->query_vars ?? [];
		foreach ( self::NOTICE_ACCOUNT_ENDPOINTS as $endpoint ) {
			if ( isset( $query_vars[ $endpoint ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a subscription is a recurring donation.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return bool
	 */
	public static function is_donation_subscription( $subscription ) {
		$items   = array_values( $subscription->get_items() );
		$product = empty( $items ) ? null : $items[0]->get_product();
		return $product && Donations::is_donation_product( $product->get_id() );
	}

	/**
	 * The message asking a reader to pay a payable order, on that order's page: what
	 * the payment restores, after a word on the failed payment when there was one.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $amount The amount due, formatted.
	 *
	 * @return string The message.
	 */
	public static function get_order_payment_message( $order, $amount ) {
		/* translators: %s: the amount due. */
		$message      = sprintf( __( 'Pay %s to complete your order.', 'newspack-plugin' ), $amount );
		$subscription = null;
		if ( function_exists( 'wcs_order_contains_renewal' ) && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) && \wcs_order_contains_renewal( $order ) ) {
			$subscriptions = \wcs_get_subscriptions_for_renewal_order( $order );
			$subscription  = $subscriptions ? reset( $subscriptions ) : null;
		}
		if ( $subscription && self::is_donation_subscription( $subscription ) ) {
			$period = 1 === (int) $subscription->get_billing_interval() ? $subscription->get_billing_period() : '';
			if ( 'month' === $period ) {
				/* translators: %s: the amount due. */
				$message = sprintf( __( 'Pay %s to restart your monthly donation.', 'newspack-plugin' ), $amount );
			} elseif ( 'year' === $period ) {
				/* translators: %s: the amount due. */
				$message = sprintf( __( 'Pay %s to restart your annual donation.', 'newspack-plugin' ), $amount );
			} else {
				/* translators: %s: the amount due. */
				$message = sprintf( __( 'Pay %s to restart your recurring donation.', 'newspack-plugin' ), $amount );
			}
		} elseif ( $subscription ) {
			/* translators: %s: the amount due. */
			$message = sprintf( __( 'Pay %s to reactivate your subscription.', 'newspack-plugin' ), $amount );
		}
		if ( $order->has_status( 'failed' ) ) {
			$message = __( "This payment didn't go through.", 'newspack-plugin' ) . ' ' . $message;
		}
		return $message;
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
		if ( self::is_donation_subscription( $subscription ) ) {
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
