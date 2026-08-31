/**
 * Shared term/taxonomy helpers for DataView screens.
 *
 * Reads `_embedded.wp:term`, paginates beyond the 100-item REST cap,
 * and round-trips `FormTokenField` tokens. `resolveTokens` preserves
 * existing selections' IDs across re-renders (see its comment for the
 * residual duplicate-name caveat on hierarchical taxonomies).
 */

import apiFetch from '@wordpress/api-fetch';

export const TERMS_PER_PAGE = 100;

// Walk every page — `per_page` caps at 100 server-side, so a single request silently truncates on sites with many terms.
export async function fetchAllTerms( basePath, { fields = [ 'id', 'name' ] } = {} ) {
	const all = [];
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
export const termsForTaxonomy = ( item, taxonomy ) => {
	const groups = item?._embedded?.[ 'wp:term' ] || [];
	for ( const group of groups ) {
		if ( Array.isArray( group ) && group.length > 0 && group[ 0 ]?.taxonomy === taxonomy ) {
			return group;
		}
	}
	return [];
};

const initialSelectionsForTaxonomy = ( item, taxonomy ) =>
	termsForTaxonomy( item, taxonomy )
		.map( term => ( { id: term?.id, name: term?.name } ) )
		.filter( s => typeof s.id === 'number' && s.name );

const selectionsFromIds = ( ids, options ) =>
	( Array.isArray( ids ) ? ids : [] )
		.map( id => ( Array.isArray( options ) ? options : [] ).find( option => option?.id === id ) )
		.filter( option => option && option.name )
		.map( option => ( { id: option.id, name: option.name } ) );

// Resolve a post's stored term IDs into `{ id, name }` selections from
// both the `wp:term` embed and a fetched options list. Neither source is
// complete on its own: the embed is absent whenever no term-backed column
// is visible, and it caps at 100 terms per taxonomy
// (`WP_REST_Server::embed_links()` raises an embedded collection's
// `per_page` to the schema maximum, and the terms schema maxes at 100).
// Both yield the same REST `name` for a given term, so precedence is
// immaterial today — but it is the embed that wins, being spread last
// into a `Map` that keeps the last entry per ID.
export const selectionsForTaxonomy = ( item, ids, taxonomy, options ) => {
	const embedded = initialSelectionsForTaxonomy( item, taxonomy );
	if ( ! Array.isArray( ids ) ) {
		return embedded;
	}
	const byId = new Map( [ ...selectionsFromIds( ids, options ), ...embedded ].map( selection => [ selection.id, selection ] ) );
	return ids.map( id => byId.get( id ) ).filter( Boolean );
};

// IDs the options list cannot account for. Distinct from `unresolvedIds`,
// which measures against the merged selections: the embed can render a token
// the options list has never heard of, and that token looks editable while
// being impossible to restore, since the options list is what feeds both the
// suggestions and `__experimentalValidateInput`. Remove it once and it cannot
// be typed back. So this, not the merged gap, is what decides editability.
export const idsMissingFromOptions = ( ids, options ) => {
	const known = new Set( ( Array.isArray( options ) ? options : [] ).map( option => option?.id ) );
	return ( Array.isArray( ids ) ? ids : [] ).filter( id => typeof id === 'number' && ! known.has( id ) );
};

// Union of two term lists, newest name winning, sorted by name to match the
// REST default. Used when a list is re-fetched: `fetchAllTerms` returns
// whatever it collected when a page request fails, so replacing a good list
// with a short one would drop terms that are still there. Growing only means a
// failed re-attempt can never cost ground. The trade is that a term deleted
// elsewhere lingers until the screen is reloaded.
export const mergeTerms = ( current, next ) => {
	const byId = new Map( ( Array.isArray( current ) ? current : [] ).map( term => [ term.id, term ] ) );
	( Array.isArray( next ) ? next : [] ).forEach( term => byId.set( term.id, term ) );
	return [ ...byId.values() ].sort( ( a, b ) => String( a.name ).localeCompare( String( b.name ) ) );
};

export const unresolvedIds = ( ids, selections ) => {
	const resolved = new Set( selections.map( selection => selection.id ) );
	return ( Array.isArray( ids ) ? ids : [] ).filter( id => typeof id === 'number' && ! resolved.has( id ) );
};

export const sortedIdsEqual = ( a, b ) => {
	if ( a.length !== b.length ) {
		return false;
	}
	// Numeric comparator — default sort is lexicographic (`[2, 10]` → `[10, 2]`).
	const sa = a.map( s => s.id ).sort( ( x, y ) => x - y );
	const sb = b.map( s => s.id ).sort( ( x, y ) => x - y );
	return sa.every( ( v, i ) => v === sb[ i ] );
};

// Existing selections keep their ID; a freshly-typed token resolves to the first name match — duplicate-name siblings on hierarchical taxonomies can land on the "wrong" one (acceptable trade-off vs. disambiguating every suggestion label).
export const resolveTokens = ( newTokens, currentSelections, options ) =>
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
		.filter( Boolean );
