/**
 * Shared types for the Subscriptions wizard and its subscriber-commerce tabs.
 */

/** How a rule picks the store products it covers. */
export type Targeting = 'products' | 'category' | 'all';

/**
 * The targeting half of a rule — the "Applies to" fields, shared by every
 * subscriber-commerce feature.
 */
export interface RuleTargeting {
	targeting: Targeting;
	product_ids: number[];
	category_ids: number[];
	excluded_product_ids: number[];
}

/** The fields every subscriber-commerce rule carries. */
export interface BaseRule extends RuleTargeting {
	id: string;
	/** Subscription products whose subscribers the rule applies to. */
	subscription_product_ids: number[];
	active: boolean;
	created_at: string;
}

/** A product as returned by the shell's search endpoints. */
export interface ProductSearchItem {
	id: number;
	name: string;
	parent_id: number;
	type_label: string;
	price: string;
	regular_price: string;
	sale_price: string;
	is_on_sale: boolean;
}

/** A product category as returned by the shell's search endpoint. */
export interface TermSearchItem {
	id: number;
	name: string;
	type_label: string;
}

/** A tab registered on the Subscriptions wizard. */
export interface SubscriptionsTab {
	slug: string;
	label: string;
	path: string;
}

/** The front-end half of a tab registration. */
export interface SubscriptionsTabComponent {
	/** Renders the tab's screen. */
	render: () => JSX.Element;
	/** Label for the last breadcrumb. Defaults to the tab label. */
	breadcrumbLabel?: string;
	/** Render the tab full-width, without the wizard content column. */
	fullWidth?: boolean;
	/**
	 * The tab authors its leaf crumb at render time via headerData.sectionName,
	 * so the static trail holds ancestors only.
	 */
	rendersLeafCrumb?: boolean;
}
