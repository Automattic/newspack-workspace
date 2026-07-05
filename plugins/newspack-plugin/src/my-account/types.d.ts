/**
 * Ambient declarations for globals consumed by the My Account scripts under
 * src/my-account/. This file is a global script: no top-level imports, so
 * every declaration lands in the global scope.
 */

/**
 * Data localized as `newspack_my_account` by
 * WooCommerce_My_Account::enqueue_scripts() for the core My Account script
 * (loaded in all My Account versions). Top-level scalars pass through
 * wp_localize_script(), which casts them to strings ('1'/'') — they are only
 * ever used as truthy/falsy flags. Only the members consumed by these
 * scripts are declared.
 */
declare const newspack_my_account: {
	labels: {
		cancel_subscription_message?: string;
	};
	rest_url: string;
	should_rate_limit: boolean | string;
	nonce: string;
	is_switch_subscription_checkout_page: boolean | string;
	is_reorder_checkout_page: boolean | string;
	/**
	 * Cart summary for "order again" checkouts. An empty PHP array (serialized
	 * as `[]`) when the cart contains no reorder, hence all members optional.
	 */
	cart_reorder_summary: {
		order_id?: unknown;
		product_id?: unknown;
		early_renewal?: {
			subscription_id?: unknown;
		};
	};
	/**
	 * Cart summary for subscription-switch checkouts. An empty PHP array
	 * (serialized as `[]`) when the cart contains no switches, hence all
	 * members optional.
	 */
	cart_switch_subscriptions_summary: {
		subscription_id?: unknown;
		upgraded_or_downgraded?: unknown;
	};
};
