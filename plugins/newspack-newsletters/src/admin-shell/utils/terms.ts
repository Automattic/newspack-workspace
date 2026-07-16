/**
 * Shared term/taxonomy helpers for DataView screens.
 *
 * Reads `_embedded.wp:term`, paginates beyond the 100-item REST cap,
 * and round-trips `FormTokenField` tokens. `resolveTokens` preserves
 * existing selections' IDs across re-renders (see its comment for the
 * residual duplicate-name caveat on hierarchical taxonomies).
 */

import apiFetch from '@wordpress/api-fetch';

import type { PostItem, Term, TermSelection } from '../types';

export const TERMS_PER_PAGE = 100;

// Walk every page — `per_page` caps at 100 server-side, so a single request silently truncates on sites with many terms.
export async function fetchAllTerms( basePath: string, { fields = [ 'id', 'name' ] }: { fields?: string[] } = {} ): Promise< Term[] > {
	const all: Term[] = [];
	let page = 1;
	let totalPages = 1;
	const fieldsParam = encodeURIComponent( fields.join( ',' ) );
	while ( page <= totalPages ) {
		try {
			const response = await apiFetch( {
				path: `${ basePath }?per_page=${ TERMS_PER_PAGE }&_fields=${ fieldsParam }&page=${ page }`,
				parse: false,
			} );
			const data = await response.json();
			if ( ! Array.isArray( data ) ) {
				break;
			}
			all.push( ...data );
			if ( page === 1 ) {
				const headerPages = parseInt( response.headers?.get?.( 'X-WP-TotalPages' ) || '1', 10 );
				totalPages = Number.isFinite( headerPages ) && headerPages > 0 ? headerPages : 1;
			}
		} catch ( error ) {
			break;
		}
		page += 1;
	}
	return all;
}

// Keyed lookup — group order isn't guaranteed across post types.
export const termsForTaxonomy = ( item: PostItem, taxonomy: string ): Term[] => {
	const groups = item?._embedded?.[ 'wp:term' ] || [];
	for ( const group of groups ) {
		if ( Array.isArray( group ) && group.length > 0 && group[ 0 ]?.taxonomy === taxonomy ) {
			return group;
		}
	}
	return [];
};

export const initialSelectionsForTaxonomy = ( item: PostItem, taxonomy: string ): TermSelection[] =>
	termsForTaxonomy( item, taxonomy )
		.map( term => ( { id: term?.id, name: term?.name } ) )
		.filter( ( s ): s is TermSelection => typeof s.id === 'number' && typeof s.name === 'string' && !! s.name );

export const sortedIdsEqual = ( a: TermSelection[], b: TermSelection[] ): boolean => {
	if ( a.length !== b.length ) {
		return false;
	}
	// Numeric comparator — default sort is lexicographic (`[2, 10]` → `[10, 2]`).
	const sa = a.map( s => s.id ).sort( ( x, y ) => x - y );
	const sb = b.map( s => s.id ).sort( ( x, y ) => x - y );
	return sa.every( ( v, i ) => v === sb[ i ] );
};

// Existing selections keep their ID; a freshly-typed token resolves to the first name match — duplicate-name siblings on hierarchical taxonomies can land on the "wrong" one (acceptable trade-off vs. disambiguating every suggestion label).
export const resolveTokens = (
	newTokens: ReadonlyArray< string | { value: string } >,
	currentSelections: TermSelection[],
	options: Term[]
): TermSelection[] =>
	newTokens
		.map( token => {
			const name = typeof token === 'string' ? token : token.value;
			const existing = currentSelections.find( s => s.name.toLowerCase() === String( name ).toLowerCase() );
			if ( existing ) {
				return existing;
			}
			const match = options.find( o => String( o.name ).toLowerCase() === String( name ).toLowerCase() );
			return match ? { id: match.id, name: match.name } : null;
		} )
		.filter( ( s ): s is TermSelection => s !== null && typeof s.name === 'string' );
