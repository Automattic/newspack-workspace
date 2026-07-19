/**
 * Types for the Subscription Products DataViews page.
 *
 * Ambient (no imports/exports) so these are globally available, matching the
 * convention used by other audience wizard views.
 */

/**
 * One price variation of a variable subscription.
 */
interface SubscriptionProductVariation {
	id: number;
	name: string;
	base_price: number | null;
	period: string;
	interval: number;
	price_label: string;
	// Active subscribers on this plan; a plan with subscribers can't be removed.
	active_subscriptions: number;
	// Group-subscription (multi-seat) settings for this plan.
	group: { enabled: boolean; limit: number };
}

/**
 * A subscription product bundled into a grouped (plan-switching) product.
 */
interface SubscriptionProductBundled {
	id: number;
	name: string;
	type: string;
	type_label: string;
	price_label: string;
}

/**
 * A product category.
 */
interface SubscriptionProductCategory {
	id: number;
	name: string;
	slug: string;
}

/**
 * The consolidated, productized row for a subscription product.
 */
interface SubscriptionProduct {
	id: number;
	name: string;
	type: 'subscription' | 'variable-subscription' | 'grouped' | 'simple';
	type_label: string;
	// Whether the product is flagged as a donation (Donations::is_donation_product).
	is_donation: boolean;
	// Derived availability tier (how the plan is offered) — see derive_availability() in PHP.
	// Named "availability", NOT "access", to avoid colliding with the Access control feature.
	availability: 'public' | 'private' | 'free';
	availability_label: string;
	// Content gates this product unlocks (reverse lookup into the Access control feature).
	unlocks: { id: number; title: string }[];
	unlocks_label: string;
	// Group subscription (multi-seat) summary (content-gate feature).
	is_group_subscription: boolean;
	group_member_limit: number;
	group_member_label: string;
	// Subscription products bundled by a grouped (plan-switching) product.
	bundled_products: SubscriptionProductBundled[];
	status: string;
	status_label: string;
	base_price: number | null;
	price_label: string;
	price_range_label: string;
	period: string;
	interval: number;
	variations: SubscriptionProductVariation[];
	categories: SubscriptionProductCategory[];
	category_ids: number[];
	category_label: string;
	active_subscriptions: number | null;
	edit_url: string;
}

/**
 * Store currency details surfaced by the REST endpoint.
 */
interface SubscriptionProductsCurrency {
	code: string;
	symbol: string;
	decimals: number;
}

/**
 * Shape of the GET /products REST response.
 */
interface SubscriptionProductsResponse {
	products: SubscriptionProduct[];
	currency: SubscriptionProductsCurrency;
	available_categories: { id: number; name: string }[];
	// Whether the group-subscription (multi-seat) content-gate feature is enabled.
	group_subscriptions_enabled: boolean;
}

interface Window {
	newspackAudienceSubscriptionProducts?: {
		new_product_url: string;
		manage_products_url: string;
		woocommerce_subscriptions_active: boolean;
	};
}
