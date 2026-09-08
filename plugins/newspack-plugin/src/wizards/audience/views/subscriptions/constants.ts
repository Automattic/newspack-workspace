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

/**
 * How many names a list row shows before collapsing the rest into "+N more".
 *
 * A row is one line, so the names have to share it with the count that follows
 * them. Both tabs cap at the same point; what each one counts in the remainder
 * is its own business.
 */
export const MAX_NAMED_ITEMS = 2;
