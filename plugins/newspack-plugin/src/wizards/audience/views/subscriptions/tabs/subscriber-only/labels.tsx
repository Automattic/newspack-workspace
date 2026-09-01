/**
 * Row labels for the subscriber-only products list.
 *
 * Rows lead with the products themselves, because that is what a publisher
 * scans the list for. Category and all-products rules have no product names to
 * show, so they show their scope instead.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import type { Restriction } from './types';

/** How many product names a row shows before collapsing the rest into "+N more". */
export const MAX_NAMED_PRODUCTS = 2;

/**
 * The names a row leads with, and how many are left over.
 *
 * @param restriction The restriction.
 * @param nameOf      Resolves a product ID to its name.
 */
export function leadingProductNames( restriction: Restriction, nameOf: ( id: number ) => string | undefined ) {
	const names = ( restriction.product_ids || [] ).map( nameOf ).filter( Boolean ) as string[];
	return {
		shown: names.slice( 0, MAX_NAMED_PRODUCTS ),
		remaining: Math.max( 0, names.length - MAX_NAMED_PRODUCTS ),
	};
}

/**
 * The scope label for a category or all-products restriction.
 *
 * @param restriction The restriction.
 * @param nameOf      Resolves a category ID to its name.
 */
export function scopeLabel( restriction: Restriction, nameOf: ( id: number ) => string | undefined ) {
	if ( 'all' === restriction.targeting ) {
		return __( 'All products', 'newspack-plugin' );
	}
	const names = ( restriction.category_ids || [] ).map( nameOf ).filter( Boolean ) as string[];
	if ( ! names.length ) {
		return __( 'No categories', 'newspack-plugin' );
	}
	return sprintf(
		/* translators: %s: comma-separated product category names. */
		_n( '%s category', '%s categories', names.length, 'newspack-plugin' ),
		names.join( ', ' )
	);
}

/**
 * The "N excluded" suffix, or an empty string when nothing is excluded.
 *
 * @param restriction The restriction.
 */
export function excludedLabel( restriction: Restriction ) {
	const count = ( restriction.excluded_product_ids || [] ).length;
	if ( ! count ) {
		return '';
	}
	return sprintf(
		/* translators: %d: number of excluded products. */
		_n( '%d excluded', '%d excluded', count, 'newspack-plugin' ),
		count
	);
}

/**
 * The "+N more" line under a product-targeted row.
 *
 * @param remaining How many product names are not shown.
 */
export function moreProductsLabel( remaining: number ) {
	return sprintf(
		/* translators: %d: number of further products. */
		_n( '+%d more product', '+%d more products', remaining, 'newspack-plugin' ),
		remaining
	);
}
