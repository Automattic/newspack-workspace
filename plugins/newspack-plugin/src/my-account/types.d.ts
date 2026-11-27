/**
 * Ambient declarations for globals consumed by the My Account scripts under
 * src/my-account/. Global-script form (no top-level imports/exports), so every
 * declaration lands in the global scope.
 */

/**
 * Data localized as `newspack_my_account` by the core My Account script (loaded
 * in all My Account versions). Top-level scalars pass through
 * wp_localize_script(), which casts them to strings ('1'/'') — they are only
 * ever read as truthy/falsy flags. Only the members these scripts read are
 * declared.
 */
declare const newspack_my_account: {
	labels: {
		generic_error: string;
		[ key: string ]: string | undefined;
	};
	rest_url: string;
	nonce: string;
	should_rate_limit: boolean | string;
	// Checkout-context flags and cart summaries, present on the My Account /
	// checkout scripts. Summaries localize as an empty array when the cart holds
	// no matching items, so every member is read through optional chaining.
	is_switch_subscription_checkout_page?: boolean;
	cart_switch_subscriptions_summary?: {
		subscription_id?: Array< number | string >;
		upgraded_or_downgraded?: Array< string | boolean >;
	};
	is_reorder_checkout_page?: boolean;
	cart_reorder_summary?: {
		order_id?: number | string;
		// Localizes as `false` when the cart holds no early renewal; read only
		// through optional chaining, so the falsy case needs no distinct type.
		early_renewal?: { subscription_id?: number | string };
		product_id?: Array< number | string >;
	};
};
