<?php
/**
 * Newspack data events listeners.
 *
 * @package Newspack
 */

namespace Newspack\Data_Events;

use Newspack\Data_Events;
use Newspack\Reader_Activation;
use Newspack\Donations;
use Newspack\Memberships;
use Newspack\Reader_Data;

/**
 * Register the v2 transactional Woo actions. These are dispatched by the listeners below
 * (one event per line item per status change). See the README for payload schema.
 */
Data_Events::register_action( 'woo_order_updated' );
Data_Events::register_action( 'woo_subscription_updated' );

/**
 * For when a reader registers.
 */
Data_Events::register_listener(
	'newspack_registered_reader',
	'reader_registered',
	function( $email, $authenticate, $user_id, $existing_user, $metadata ) {
		if ( $existing_user ) {
			return null;
		}
		return [
			'user_id'  => $user_id,
			'email'    => $email,
			'metadata' => $metadata,
		];
	}
);

/**
 * For when a reader is deleted.
 */
Data_Events::register_listener(
	'delete_user',
	'reader_deleted',
	function( $user_id, $reassign, $user ) {
		if ( ! Reader_Activation::is_user_reader( $user ) ) {
			return;
		}
		return [
			'user_id' => $user_id,
			'user'    => $user,
		];
	}
);


/**
 * For when a reader is deleted, syncs the membership status update to the ESP.
 */
Data_Events::register_listener(
	'delete_user',
	'reader_delete_sync',
	function( $user_id, $reassign, $user ) {
		if ( ! Reader_Activation::is_user_reader( $user ) ) {
			return;
		}
		return [
			'user_id' => $user_id,
			'user'    => $user,
		];
	}
);

/**
 * For when a reader registers via Woo.
 */
Data_Events::register_listener(
	'newspack_registered_reader_via_woo',
	'reader_registered',
	function( $email, $user_id, $metadata ) {
		return [
			'user_id'  => $user_id,
			'email'    => $email,
			'metadata' => $metadata,
		];
	}
);

/**
 * For when a reader logs in.
 */
Data_Events::register_listener(
	'wp_login',
	'reader_logged_in',
	function( $user_login, $user ) {
		if ( ! Reader_Activation::is_user_reader( $user ) ) {
			return;
		}
		return [
			'user_id' => $user->ID,
			'email'   => $user->user_email,
		];
	}
);

/**
 * For when a reader is marked as verified.
 */
Data_Events::register_listener(
	'newspack_reader_verified',
	'reader_verified',
	function( $user ) {
		return [
			'user_id' => $user->ID,
			'email'   => $user->user_email,
		];
	}
);

/**
 * For when a new contact is added to newsletter lists for the first time.
 */
Data_Events::register_listener(
	'newspack_newsletters_contact_subscribed',
	'newsletter_subscribed',
	function( $provider, $contact, $lists, $result ) {
		if ( empty( $lists ) ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			return;
		}
		$user = get_user_by( 'email', $contact['email'] );
		return [
			'user_id'  => $user ? $user->ID : null,
			'email'    => $contact['email'],
			'provider' => $provider,
			'contact'  => $contact,
			'lists'    => $lists,
		];
	}
);

/**
 * For when a contact newsletter subscription is updated.
 */
Data_Events::register_listener(
	'newspack_newsletters_update_contact_lists',
	'newsletter_updated',
	function( $provider, $email, $lists_added, $lists_removed, $result ) {
		if ( empty( $lists_added ) && empty( $lists_removed ) ) {
			return;
		}
		if ( true !== $result ) {
			return;
		}
		$user = get_user_by( 'email', $email );
		return [
			'user_id'       => $user ? $user->ID : null,
			'email'         => $email,
			'provider'      => $provider,
			'lists_added'   => $lists_added,
			'lists_removed' => $lists_removed,
		];
	}
);

/**
 * For when there's a new donation processed through WooCommerce.
 */
Data_Events::register_listener(
	'newspack_donation_order_processed',
	'woocommerce_donation_order_processed',
	function( $order_id, $product_id ) {
		return \Newspack\Data_Events\Utils::get_order_data( $order_id, true );
	}
);

/**
 * For when there's a new donation payment failed through WooCommerce.
 *
 * Known issue: If the user tries to pay again after a failed payment, and the payment fails for a second time,
 * the order is already marked as failed so this hook will not trigger.
 */
Data_Events::register_listener(
	'woocommerce_order_status_failed',
	'woocommerce_order_failed',
	function( $order_id, $order ) {
		return \Newspack\Data_Events\Utils::get_order_data( $order_id, true );
	}
);

/**
 * For when a Subscription is confirmed.
 */
Data_Events::register_listener(
	'woocommerce_subscription_status_updated',
	'donation_subscription_new',
	function( $subscription, $status_to, $status_from ) {
		if ( 'active' !== $status_to || 'pending' !== $status_from ) {
			return;
		}
		$product_id = Donations::get_order_donation_product_id( $subscription->get_id() );
		if ( ! $product_id ) {
			return;
		}
		$recurrence = get_post_meta( $product_id, '_subscription_period', true );
		return [
			'user_id'         => $subscription->get_customer_id(),
			'email'           => $subscription->get_billing_email(),
			'subscription_id' => $subscription->get_id(),
			'amount'          => (float) $subscription->get_total(),
			'currency'        => $subscription->get_currency(),
			'recurrence'      => empty( $recurrence ) ? 'once' : $recurrence,
			'platform'        => Donations::get_platform_slug(),
			'referer'         => $subscription->get_meta( '_newspack_referer' ),
			'popup_id'        => $subscription->get_meta( '_newspack_popup_id' ),
		];
	}
);

/**
 * For when there's a new donation confirmed
 */
Data_Events::register_listener(
	'woocommerce_order_status_completed',
	'donation_new',
	function ( $order_id, $order ) {
		return \Newspack\Data_Events\Utils::get_order_data( $order_id, true );
	}
);

/**
 * For any completed purchase. This data event triggers a contact sync to the connected ESP.
 */
Data_Events::register_listener(
	'woocommerce_order_status_completed',
	'order_completed',
	function( $order_id, $order ) {
		return \Newspack\Data_Events\Utils::get_order_data( $order_id );
	}
);

/**
 * For when a donation subscription is cancelled.
 */
Data_Events::register_listener(
	'woocommerce_subscription_status_updated',
	'donation_subscription_cancelled',
	function( $subscription, $status_to, $status_from ) {
		if ( 'cancelled' !== $status_to ) {
			return;
		}
		return \Newspack\Data_Events\Utils::get_recurring_donation_data( $subscription );
	}
);

/**
 * For when a donation subscription status changes.
 */
Data_Events::register_listener(
	'woocommerce_subscription_status_updated',
	'donation_subscription_changed',
	function( $subscription, $status_to, $status_from ) {
		$data = \Newspack\Data_Events\Utils::get_recurring_donation_data( $subscription );
		if ( ! is_array( $data ) ) {
			return;
		}
		return array_merge(
			$data,
			[
				'status_before' => $status_from,
				'status_after'  => $status_to,
			]
		);
	}
);

/**
 * For when a donation subscription is switched (recurrence/amount change).
 */
Data_Events::register_listener(
	'woocommerce_subscriptions_switch_completed',
	'donation_subscription_changed',
	function( $order ) {

		if ( ! function_exists( 'wcs_get_objects_property' ) ) {
			return;
		}

		$switch_order_data = wcs_get_objects_property( $order, 'subscription_switch_data' );

		if ( empty( $switch_order_data ) || ! is_array( $switch_order_data ) ) {
			return;
		}

		$subscription_id = array_key_first( $switch_order_data );

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}

		$data = \Newspack\Data_Events\Utils::get_recurring_donation_data( $subscription );
		if ( ! is_array( $data ) ) {
			return;
		}
		return array_merge(
			$data,
			[
				'status_before' => $subscription->get_status(),
				'status_after'  => $subscription->get_status(),
			]
		);
	}
);

/**
 * When a non-donation subscription status changes.
 * The subscription will be removed from the user's list of active subscriptions.
 */
Data_Events::register_listener(
	'woocommerce_subscription_status_updated',
	'product_subscription_changed',
	function( $subscription, $status_to, $status_from ) {
		// We only want to fire this for non-donation products.
		$product_ids = array_values(
			array_filter(
				\Newspack\WooCommerce_Connection::get_products_for_order( $subscription->get_id() ),
				function( $product_id ) {
					return ! Donations::is_donation_product( $product_id );
				}
			)
		);

		if ( empty( $product_ids ) ) {
			return;
		}

		return [
			'user_id'         => $subscription->get_customer_id(),
			'email'           => $subscription->get_billing_email(),
			'subscription_id' => $subscription->get_id(),
			'product_ids'     => $product_ids,
			'amount'          => (float) $subscription->get_total(),
			'currency'        => $subscription->get_currency(),
			'recurrence'      => $subscription->get_billing_period(),
			'status_before'   => $status_from,
			'status_after'    => $status_to,
		];
	}
);

/**
 * When a subscription of any type renews.
 */
Data_Events::register_listener(
	'wcs_renewal_order_created',
	'subscription_renewal_attempt',
	function( $renewal_order, $subscription ) {
		return [
			'user_id'         => $subscription->get_customer_id(),
			'email'           => $subscription->get_billing_email(),
			'order_id'        => $renewal_order->get_id(),
			'subscription_id' => $subscription->get_id(),
			'amount'          => (float) $subscription->get_total(),
			'currency'        => $subscription->get_currency(),
			'recurrence'      => $subscription->get_billing_period(),
		];
	}
);

/**
 * For when a non-donation subscription is switched (recurrence/amount change).
 */
Data_Events::register_listener(
	'woocommerce_subscriptions_switch_completed',
	'product_subscription_changed',
	function( $order ) {

		if ( ! function_exists( 'wcs_get_objects_property' ) ) {
			return;
		}

		$switch_order_data = wcs_get_objects_property( $order, 'subscription_switch_data' );

		if ( empty( $switch_order_data ) || ! is_array( $switch_order_data ) ) {
			return;
		}

		$subscription_id = array_key_first( $switch_order_data );

		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}

		$product_ids = array_values(
			array_filter(
				\Newspack\WooCommerce_Connection::get_products_for_order( $subscription->get_id() ),
				function( $product_id ) {
					return ! Donations::is_donation_product( $product_id );
				}
			)
		);

		if ( empty( $product_ids ) ) {
			return;
		}

		return [
			'user_id'         => $subscription->get_customer_id(),
			'email'           => $subscription->get_billing_email(),
			'subscription_id' => $subscription->get_id(),
			'product_ids'     => $product_ids,
			'amount'          => (float) $subscription->get_total(),
			'currency'        => $subscription->get_currency(),
			'recurrence'      => $subscription->get_billing_period(),
			'status_before'   => $subscription->get_status(),
			'status_after'    => $subscription->get_status(),
		];
	}
);

/**
 * When a WooCommerce Memberships plan becomes active for a reader.
 * This hook is fired whenever a user is granted access to a membership plan.
 * The membership plan will be add to the user's list of active memberships.
 */
Data_Events::register_listener(
	'wc_memberships_user_membership_saved',
	'membership_status_active',
	function( $membership_plan, $args ) {
		$membership = \wc_memberships_get_user_membership( $args['user_membership_id'] );

		// Only fire for active statuses.
		if ( ! in_array( $membership->get_status(), Memberships::$active_statuses, true ) ) {
			return;
		}

		$user       = \get_user_by( 'id', $args['user_id'] );
		$user_email = $user ? $user->user_email : '';
		return [
			'user_id' => $args['user_id'],
			'email'   => $user_email,
			'plan_id' => $membership_plan->get_id(),
		];
	}
);

/**
 * When a WooCommerce Memberships plan becomes inactive for a reader.
 * This hook is fired when an existing user membership is updated, but not when created.
 * The membership plan will be removed from the user's list of active memberships.
 */
Data_Events::register_listener(
	'wc_memberships_user_membership_status_changed',
	'membership_status_inactive',
	function( $membership, $old_status, $new_status ) {
		// Only fire for inactive statuses.
		if ( in_array( $new_status, Memberships::$active_statuses, true ) ) {
			return;
		}

		$user_id    = $membership->get_user_id();
		$user       = \get_user_by( 'id', $user_id );
		$user_email = $user ? $user->user_email : '';
		return [
			'user_id'       => $user_id,
			'email'         => $user_email,
			'plan_id'       => $membership->get_plan_id(),
			'status_before' => $old_status,
			'status_after'  => $new_status,
		];
	}
);

/**
 * When a membership is created or updated.
 */
Data_Events::register_listener(
	'wc_memberships_user_membership_saved',
	'membership_saved',
	function( $membership_plan, $args ) {
		if ( ! $membership_plan ) {
			return;
		}
		$membership = \wc_memberships_get_user_membership( $args['user_membership_id'] );
		$user       = \get_user_by( 'id', $args['user_id'] );
		$user_email = $user ? $user->user_email : '';
		return [
			'user_id'               => $args['user_id'],
			'email'                 => $user_email,
			'membership_plan'       => $membership_plan->get_name(),
			'membership_status'     => $membership->get_status(),
			'membership_start_date' => $membership->get_start_date(),
			'membership_end_date'   => $membership->get_end_date(),
		];
	}
);

/**
 * For every WC order status transition. Fires one woo_order_updated event per product line item.
 *
 * Uses raw add_action (not Data_Events::register_listener) because register_listener only supports
 * a single dispatch per hook fire — multi-line orders need one event per line item.
 * Runs at PHP_INT_MAX so any earlier handlers that mutate the order are reflected in the payload,
 * matching the convention in Data_Events::register_listener.
 * The action is registered at the top of this file.
 */
add_action(
	'woocommerce_order_status_changed',
	function ( $order_id, $status_from, $status_to, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = \wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		foreach ( \Newspack\Data_Events\Utils::get_woo_order_updated_payloads( $order, $status_to, $status_from ) as $payload ) {
			Data_Events::dispatch( 'woo_order_updated', $payload );
		}
	},
	PHP_INT_MAX,
	4
);

/**
 * For every WC Subscription status transition. Fires one woo_subscription_updated event per line item.
 *
 * Uses raw add_action (not Data_Events::register_listener) because register_listener only supports
 * a single dispatch per hook fire — multi-line subscriptions need one event per line item.
 * Runs at PHP_INT_MAX so any earlier handlers that mutate the subscription are reflected in the payload,
 * matching the convention in Data_Events::register_listener.
 * The action is registered at the top of this file.
 */
add_action(
	'woocommerce_subscription_status_updated',
	function ( $subscription, $status_to, $status_from ) {
		if ( ! $subscription instanceof \WC_Subscription ) {
			return;
		}
		foreach ( \Newspack\Data_Events\Utils::get_woo_subscription_updated_payloads( $subscription, $status_to, $status_from ) as $payload ) {
			Data_Events::dispatch( 'woo_subscription_updated', $payload );
		}
	},
	PHP_INT_MAX,
	3
);

/**
 * For WC Subscription switches (recurrence/amount changes without a status change).
 * Fires woo_subscription_updated with the post-switch status for each affected subscription.
 *
 * Uses raw add_action because each switch order can affect multiple subscriptions and
 * each subscription can have multiple line items — N events per hook fire.
 * Runs at PHP_INT_MAX so the dispatched status reflects post-switch adjustments made by other
 * callbacks on the same hook, matching the convention in Data_Events::register_listener.
 * The action is registered at the top of this file.
 */
add_action(
	'woocommerce_subscriptions_switch_completed',
	function ( $order ) {
		if ( ! function_exists( 'wcs_get_objects_property' ) || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$switch_data = \wcs_get_objects_property( $order, 'subscription_switch_data' );
		if ( empty( $switch_data ) || ! is_array( $switch_data ) ) {
			return;
		}
		foreach ( array_keys( $switch_data ) as $subscription_id ) {
			$subscription = \wcs_get_subscription( $subscription_id );
			if ( ! $subscription instanceof \WC_Subscription ) {
				continue;
			}
			$current_status = $subscription->get_status();
			// For switches, no status transition occurs — pass the current status as both from/to.
			foreach ( \Newspack\Data_Events\Utils::get_woo_subscription_updated_payloads( $subscription, $current_status, $current_status, true ) as $payload ) {
				Data_Events::dispatch( 'woo_subscription_updated', $payload );
			}
		}
	},
	PHP_INT_MAX,
	1
);
