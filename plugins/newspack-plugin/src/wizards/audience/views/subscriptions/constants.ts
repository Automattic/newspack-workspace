/**
 * Shared constants for the Subscriptions wizard.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

export const WIZARD_SLUG = 'newspack-audience-subscriptions';

/** REST namespace for every endpoint on this wizard. */
export const WIZARD_ENDPOINT = `/newspack/v1/wizard/${ WIZARD_SLUG }`;

/** Search endpoints the shell provides to every tab. */
export const SEARCH_ENDPOINTS = {
	products: 'products-search',
	productCategories: 'product-categories-search',
	subscriptions: 'subscriptions-search',
} as const;

/**
 * How many names a list row shows before collapsing the rest into "+N more".
 *
 * A row is one line, so the names have to share it with the count that follows
 * them. Both tabs cap at the same point; what each one counts in the remainder
 * is its own business.
 */
export const MAX_NAMED_ITEMS = 2;

/**
 * Stands for an "all subscriptions" rule in a list's Subscription filter, which
 * otherwise offers one option per subscription id. Zero is never a product id,
 * so it cannot collide with a real subscription.
 */
export const ALL_SUBSCRIPTIONS_FILTER_VALUE = 0;

/**
 * What a rule open to every active subscriber is called.
 *
 * A function rather than a constant so `__()` runs after the translations load.
 * Both tabs, the shared editor field and the lists' filters and search all name
 * the audience through this, so none of them can drift from another.
 */
export const allSubscriptionsLabel = (): string => __( 'All subscriptions', 'newspack-plugin' );
