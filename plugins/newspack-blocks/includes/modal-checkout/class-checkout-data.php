<?php
/**
 * Newspack Blocks Modal Checkout Data.
 *
 * @package Newspack
 */

namespace Newspack_Blocks\Modal_Checkout;

/**
 * Checkout Data Class.
 */
final class Checkout_Data {
	/**
	 * Get price string for the price summary card to render in auth flow.
	 *
	 * @param string $name       The name.
	 * @param string $price      The price. Optional. If not provided, the price string will contain 0.
	 * @param string $frequency  The frequency. Optional. If not provided, the price will be treated as a one-time payment.
	 * @param int    $product_id Product ID to get additional subscription details. Optional.
	 *
	 * @return string The price string.
	 */
	public static function get_price_summary( $name, $price = '', $frequency = '', $product_id = null ) {
		if ( ! $price ) {
			$price = '0';
		}

		if ( function_exists( 'wcs_price_string' ) && function_exists( 'wc_price' ) && function_exists( 'wc_get_product' ) && class_exists( 'WC_Subscriptions_Product' ) ) {
			if ( $frequency && $frequency !== 'once' ) {
				// Get additional subscription details if product_id is provided.
				$subscription_interval = 1;
				$trial_length          = 0;
				$trial_period          = '';
				$initial_amount        = 0;

				if ( $product_id ) {
					$product = wc_get_product( $product_id );
					$subscription_interval = \WC_Subscriptions_Product::get_interval( $product );
					$trial_length = \WC_Subscriptions_Product::get_trial_length( $product );
					$trial_period = \WC_Subscriptions_Product::get_trial_period( $product );
					$initial_amount = \WC_Subscriptions_Product::get_sign_up_fee( $product );

					if ( empty( $subscription_interval ) ) {
						$subscription_interval = 1;
					}
				}

				$price = wp_strip_all_tags(
					wcs_price_string(
						[
							'recurring_amount'      => $price,
							'subscription_period'   => $frequency,
							'subscription_interval' => $subscription_interval,
							'use_per_slash'         => true,
							'trial_length'          => $trial_length,
							'trial_period'          => $trial_period,
							'initial_amount'        => $initial_amount,
						]
					)
				);
			} elseif ( $price !== '{{PRICE}}' ) { // Preserve placeholder for templating.
				$price = wp_strip_all_tags( wc_price( $price ) );
			}
		}

		// translators: 1 is the name of the item. 2 is the price of the item.
		$price_summary = sprintf( __( '%1$s: %2$s', 'newspack-blocks' ), $name, $price );

		/**
		 * Filters the price summary string that appears in modal checkout.
		 *
		 * @param string $price_summary The formatted price summary string.
		 * @param string $product_id    The product ID, if available.
		 *
		 * @return string The filtered price summary string.
		 */
		return apply_filters( 'newspack_modal_checkout_price_summary', $price_summary, $product_id );
	}

	/**
	 * Returns whether a product is a one time purchase, or recurring and when.
	 *
	 * @param string $product_id Product's ID.
	 *
	 * @return string The purchase recurrence.
	 */
	public static function get_purchase_recurrence( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'once';
		}
		$product = \wc_get_product( $product_id );
		if ( $product && ( $product->is_type( 'subscription' ) || $product->is_type( 'subscription_variation' ) ) ) {
			$recurrence = get_post_meta( $product_id, '_subscription_period', true );
			if ( ! empty( $recurrence ) ) {
				return $recurrence;
			}
		}
		return 'once';
	}

	/**
	 * Returns whether a product ID is associated with a membership.
	 *
	 * @param string $product_id Product's ID.
	 */
	public static function is_membership_product( $product_id ) {
		if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
			return false;
		}
		$membership_plans = wc_memberships_get_membership_plans();
		$plans            = [];

		foreach ( $membership_plans as $plan ) {
			$subscription_plan  = new \WC_Memberships_Integration_Subscriptions_Membership_Plan( $plan->get_id() );
			$required_products = $subscription_plan->get_subscription_product_ids();
			if ( in_array( $product_id, $required_products ) ) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Returns the product type: product, subscription, donation, or membership.
	 *
	 * @param string $product_id Product's ID.
	 */
	public static function get_product_type( $product_id ) {
		$product_type = 'product';
		$recurrence   = self::get_purchase_recurrence( $product_id );

		// Check if it's a subscription product.
		if ( 'once' !== $recurrence ) {
			$product_type = 'subscription';
		}

		// Check if it's a membership product.
		if ( self::is_membership_product( $product_id ) ) {
			$product_type = 'membership';
		}

		// Check if it's a donation product.
		if ( method_exists( 'Newspack\Donations', 'is_donation_product' ) ) {
			if ( \Newspack\Donations::is_donation_product( $product_id ) ) {
				$product_type = 'donation';
			}
		}

		return $product_type;
	}

	/**
	 * Returns the action type: checkout_button or donation.
	 *
	 * @param string    $product_id Product's ID.
	 * @param \WC_Order $order      Optional order to check if it's a subscription switch.
	 */
	public static function get_action_type( $product_id, $order = null ) {
		$action_type = 'checkout_button';

		// Check if it's a donation product, and update action_type, product_type.
		if ( method_exists( 'Newspack\Donations', 'is_donation_product' ) ) {
			if ( \Newspack\Donations::is_donation_product( $product_id ) ) {
				$action_type = 'donation';
			}
		}

		// Check if it's a subscription switch.
		if ( $order ) {
			if ( function_exists( 'wcs_order_contains_switch' ) && wcs_order_contains_switch( $order ) ) {
				$action_type = 'subscription_switch';
			}
		} elseif ( method_exists( 'WC_Subscriptions_Switcher', 'cart_contains_switches' ) && \WC_Subscriptions_Switcher::cart_contains_switches( 'any' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action_type = 'subscription_switch';
		}

		// Check if the action type is set in the URL.
		if ( isset( $_GET['action_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action_type = sanitize_text_field( wp_unslash( $_GET['action_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return $action_type;
	}

	/**
	 * Get donate product checkout data.
	 *
	 * @param string $frequency The frequency.
	 * @param int    $amount    The amount.
	 *
	 * @return array
	 */
	public static function get_donation_checkout_data( $frequency, $amount = null ) {
		if ( ! method_exists( 'Newspack\Donations', 'get_donation_product' ) ) {
			return [];
		}
		$product_id = \Newspack\Donations::get_donation_product( $frequency );
		if ( ! $product_id ) {
			return [];
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [];
		}
		$data = self::get_checkout_data( $product );
		if ( $amount ) {
			$data['amount'] = $amount;
		}
		return $data;
	}

	/**
	 * Recursively get the children of a product, returning only ungrouped children.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array Array of child product IDs.
	 */
	public static function get_children( $product ) {
		$children = [];
		foreach ( $product->get_children() as $child_id ) {
			$child = \wc_get_product( $child_id );
			// Check if the child has children of its own.
			if ( method_exists( $child, 'get_children' ) && $child->get_children() ) {
				$children = array_merge( $children, self::get_children( $child ) );
			} else {
				$children[] = $child_id;
			}
		}
		return $children;
	}

	/**
	 * Returns checkout data given a product, product variation, cart or order object.
	 *
	 * @param \WC_Product|\WC_Product_Variation|\WC_Cart|\WC_Order $source Product, product variation, cart or order object.
	 *
	 * @return array
	 */
	public static function get_checkout_data( $source ) {
		$data = [];
		if ( empty( $source ) ) {
			return $data;
		}

		$cart_item    = null;
		$order        = null;
		$referrer     = '';
		$variation_id = null;
		$is_variable  = false;
		$is_grouped   = false;

		if ( $source instanceof \WC_Product ) {
			if ( $source->is_type( 'grouped' ) ) {
				$is_grouped = true;
				$product_id = $source->get_id();
				$children   = self::get_children( $source );
			} elseif ( $source->is_type( 'variable' ) ) {
				$is_variable = true;
				$product_id  = $source->get_id();
				$children    = self::get_children( $source );
			} elseif ( $source instanceof \WC_Product_Variation ) {
				$product_id = $source->get_parent_id();
				$variation_id = $source->get_id();
				$amount = $source->get_price();
			} else {
				$product_id = $source->get_id();
				if ( $source->get_parent_id() ) {
					$product_id   = $source->get_parent_id();
					$variation_id = $source->get_id();
				}
				$amount = $source->get_price();
			}
		} elseif ( $source instanceof \WC_Cart ) {
			$cart_items   = $source->get_cart();
			$cart_item    = reset( $cart_items ); // Use only the first item in the cart.
			$product_id   = $cart_item['product_id'];
			$variation_id = $cart_item['variation_id'];
			$amount       = $cart_item['data']->get_price();
			$referrer     = $cart_item['referer'] ?? '';
		} elseif ( $source instanceof \WC_Order ) {
			// If order as actually a subscription object, we need to get the original order.
			if ( $source instanceof \WC_Subscription ) {
				$order = $source->get_parent();
			} else {
				$order = $source;
			}
			$order_items  = $order->get_items();
			$order_item   = reset( $order_items ); // Use only the first item in the order.
			$product_id   = $order_item->get_product_id();
			$variation_id = $order_item->get_variation_id();
			$amount       = $order_item->get_subtotal();
			$referrer     = $order->get_meta( '_newspack_referer' );
		}

		// If we have no referrer, set it to the current path.
		if ( ! $referrer ) {
			global $wp;
			$referrer = $wp->request;
		}

		$product_type = self::get_product_type( $product_id );
		$recurrence   = self::get_purchase_recurrence( $variation_id ? $variation_id : $product_id );

		/**
		 * Price summary name.
		 */
		if ( 'donation' === $product_type ) {
			$name = __( 'Donate', 'newspack-blocks' );
		} elseif ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$name = $variation->get_name();
		} else {
			$product = wc_get_product( $product_id );
			$name = $product->get_name();
		}

		$data = [
			'action_type'  => self::get_action_type( $product_id, $order ),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? \get_woocommerce_currency() : 'USD',
			'product_id'   => strval( $product_id ? $product_id : '' ),
			'product_type' => $product_type,
			'referrer'     => $referrer ? str_replace( home_url(), '', $referrer ) : '', // Keeps format consistent for Homepage with Donate and Checkout Button blocks.
		];

		if ( $is_variable ) {
			$data['is_variable'] = true;
			$data['variation_ids'] = $children;
		} elseif ( $is_grouped ) {
			$data['is_grouped'] = true;
			$data['child_ids'] = $children;
		} else {
			$data['amount']           = $amount;
			$data['price_summary']    = self::get_price_summary( $name, $amount, $recurrence, $variation_id ? $variation_id : $product_id );
			$data['summary_template'] = self::get_price_summary( $name, '{{PRICE}}', $recurrence, $variation_id ? $variation_id : $product_id );
			$data['recurrence']       = $recurrence;
		}
		if ( $variation_id ) {
			$data['variation_id'] = strval( $variation_id );
		}

		/**
		 * Order specific data.
		 */
		if ( $order ) {
			$data['order_id'] = $order->get_id();
			if ( in_array( $product_type, [ 'subscription', 'membership' ], true ) ) {
				$subscription_renewal = $order->get_meta( '_subscription_renewal' );
				if ( $subscription_renewal ) {
					$data['subscription_renewal'] = $subscription_renewal;
				}
				if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
					$subscriptions = wcs_get_subscriptions_for_order( $order );
					if ( ! empty( $subscriptions ) ) {
						$data['subscription_ids'] = array_values(
							array_map(
								function( $subscription ) {
									return $subscription->get_id();
								},
								$subscriptions
							)
						);
					}
				}
			}
		}

		/**
		 * Gate and popup data.
		 */
		$gate_post_id = null;
		$newspack_popup_id = null;
		$prompt_title = null;
		if ( $order ) {
			$gate_post_id = $order->get_meta( '_gate_post_id' );
			$newspack_popup_id = $order->get_meta( '_newspack_popup_id' );
			$prompt_title = $order->get_meta( '_prompt_title' );
		} elseif ( $cart_item ) {
			$gate_post_id = $cart_item['gate_post_id'] ?? null;
			$newspack_popup_id = $cart_item['newspack_popup_id'] ?? null;
			$prompt_title = $cart_item['prompt_title'] ?? null;
		} else {
			$gate_post_id = filter_input( INPUT_GET, 'gate_post_id', FILTER_SANITIZE_NUMBER_INT );
			$newspack_popup_id = filter_input( INPUT_GET, 'newspack_popup_id', FILTER_SANITIZE_NUMBER_INT );
			$prompt_title = filter_input( INPUT_GET, 'prompt_title', FILTER_SANITIZE_SPECIAL_CHARS );
		}
		if ( $gate_post_id ) {
			$data['gate_post_id'] = $gate_post_id;
		}
		if ( $newspack_popup_id ) {
			$data['newspack_popup_id'] = $newspack_popup_id;
		}
		if ( $prompt_title ) {
			$data['prompt_title'] = $prompt_title;
		}

		/**
		 * Filters the checkout data.
		 *
		 * @param array $data The checkout data.
		 * @param \WC_Product|\WC_Product_Variation|\WC_Cart|\WC_Order $source Product, product variation, cart or order object.
		 */
		return apply_filters( 'newspack_modal_checkout_data', $data, $source );
	}
}
