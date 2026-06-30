/**
 * Types for the Subscription Products DataViews page.
 *
 * Ambient (no imports/exports) so these are globally available, matching the
 * convention used by other audience wizard views.
 */

/**
 * A single pricing policy applied to a product (RSM Layer 2).
 */
interface SubscriptionPolicy {
	id: string;
	slug: string;
	label: string;
	type: 'promo' | 'season' | 'winback' | 'loyalty' | string;
	is_winning: boolean;
	adjustment_label: string;
}

/**
 * One segment of a product's per-cycle price trajectory (RSM Layer 2). Cycle 1 is
 * the purchase; each segment runs from `from_cycle` until the next one takes over.
 */
interface SubscriptionPolicySegment {
	from_cycle: number;
	amount: number;
	rule_id: string;
	rule_label: string;
}

/**
 * Resolved policy stack + effective price for a product (RSM Layer 2).
 *
 * Returned by the PHP integration seam ({@see Subscription_Policy_Resolver}).
 */
interface SubscriptionPolicyResolution {
	is_mock: boolean;
	base_price: number;
	effective_price: number;
	currency: string;
	cycle: string;
	policies: SubscriptionPolicy[];
	schedule: SubscriptionPolicySegment[];
}

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
	// Layer 2: each variation resolves its own policy stack + effective price.
	policy: SubscriptionPolicyResolution;
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
 * The consolidated, productized row for a subscription product (Layer 1 + Layer 2).
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
	policy: SubscriptionPolicyResolution;
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
	policy_source_is_mock: boolean;
	available_categories: { id: number; name: string }[];
}

interface Window {
	newspackAudienceSubscriptionProducts?: {
		new_product_url: string;
		manage_products_url: string;
		policy_source_is_mock: boolean;
		woocommerce_subscriptions_active: boolean;
	};
}
