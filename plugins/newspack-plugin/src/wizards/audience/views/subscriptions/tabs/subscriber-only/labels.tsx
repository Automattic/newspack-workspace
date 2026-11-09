/**
 * Search labels for the subscriber-only products list.
 *
 * What the list's search matches for a category or all-products restriction.
 */

/**
 * WordPress dependencies.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import type { Restriction } from './types';

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
