/**
 * Shared product-targeting helpers for the Subscriptions Demo — how a rule's
 * `{ targeting, productIds, category, excludedIds }` resolves to store
 * products and to the "Applies to" labels. Used by both subscriber discounts
 * and subscriber-only product restrictions.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { PRODUCTS, getProductById } from './mock-catalog';

export function productsForRule( rule ) {
	let matches;
	if ( rule.targeting === 'products' ) {
		matches = ( rule.productIds || [] ).map( getProductById ).filter( Boolean );
	} else {
		matches = PRODUCTS.filter( p => rule.targeting === 'all' || p.category === rule.category );
	}
	// A variable product is sold through its variations, so previews and
	// exclusions operate on those instead of the parent.
	const expanded = matches.flatMap( p => ( ( p.variations || [] ).length ? p.variations.map( v => getProductById( v.id ) ) : [ p ] ) );
	const excluded = new Set( rule.excludedIds || [] );
	return expanded.filter( p => ! excluded.has( p.id ) && ! excluded.has( p.parentId ) );
}

// Parent-level product names for a specific-products rule, for list cells that
// show which products are targeted rather than just a count.
export function productNamesForRule( rule ) {
	if ( rule.targeting !== 'products' ) {
		return [];
	}
	return ( rule.productIds || [] ).map( id => getProductById( id )?.name ).filter( Boolean );
}

// Parent-level ids of every product a rule's targeting covers, exclusions
// applied — the membership test behind the list's product filter.
export function coveredParentProductIds( rule ) {
	return [ ...new Set( productsForRule( rule ).map( p => p.parentId || p.id ) ) ];
}

export function targetingBaseLabel( rule ) {
	if ( rule.targeting === 'all' ) {
		return __( 'All products', 'newspack-plugin' );
	}
	if ( rule.targeting === 'category' ) {
		// translators: %s: product category name.
		return sprintf( __( '%s category', 'newspack-plugin' ), rule.category );
	}
	const count = ( rule.productIds || [] ).length;
	// translators: %d: number of products.
	return sprintf( _n( '%d product', '%d products', count, 'newspack-plugin' ), count );
}

export function excludedLabel( rule ) {
	const excluded = ( rule.excludedIds || [] ).length;
	if ( ! excluded ) {
		return '';
	}
	// translators: %d: number of excluded products.
	return sprintf( __( '%d excluded', 'newspack-plugin' ), excluded );
}

export function targetingLabel( rule ) {
	const base = targetingBaseLabel( rule );
	const excluded = excludedLabel( rule );
	if ( excluded ) {
		// translators: %1$s: targeting label, %2$s: excluded products label.
		return sprintf( __( '%1$s · %2$s', 'newspack-plugin' ), base, excluded );
	}
	return base;
}
