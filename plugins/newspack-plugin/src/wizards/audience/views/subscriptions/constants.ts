/**
 * Shared constants for the Subscriptions wizard.
 */

export const WIZARD_SLUG = 'newspack-audience-subscriptions';

/** REST namespace for every endpoint on this wizard. */
export const WIZARD_ENDPOINT = `/newspack/v1/wizard/${ WIZARD_SLUG }`;

/** Search endpoints the shell provides to every tab. */
export const SEARCH_ENDPOINTS = {
	products: 'products-search',
	productCategories: 'product-categories-search',
	subscriptions: 'subscriptions-search',
} as const;
