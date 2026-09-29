/**
 * Unit tests for the scope-target picker — the endpoint paths that decide
 * whether variations can be targeted (engine route, core fallback) and the
 * saved-id degradation contract: ids no route resolves must keep placeholder
 * tokens, because the token field only round-trips ids it renders.
 */

/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import ScopeTargets, { SOURCES } from './scope-targets';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Narrow the components barrel to the one real component under test, without
// evaluating the whole package index (submodules never import the barrel).
jest.mock( '../../../../../packages/components/src', () => ( {
	AutocompleteTokenField: jest.requireActual( '../../../../../packages/components/src/autocomplete-tokenfield' ).default,
} ) );

describe( 'scope-targets SOURCES', () => {
	it( 'products search uses the engine route, which serves variations', () => {
		expect( SOURCES.product_ids.suggestionsPath( 'gold' ) ).toBe( '/wc-dynamic-pricing/v1/products?search=gold&per_page=50' );
	} );

	it( 'saved product ids hydrate through the engine route include param', () => {
		expect( SOURCES.product_ids.savedPath( [ 4201, 42 ] ) ).toBe( '/wc-dynamic-pricing/v1/products?include=4201%2C42' );
	} );

	it( 'products fall back to core WP REST, which serves parents only', () => {
		expect( SOURCES.product_ids.fallbackSuggestionsPath( 'gold' ) ).toBe( '/wp/v2/product?search=gold&per_page=20&_fields=id%2Ctitle' );
		expect( SOURCES.product_ids.fallbackSavedPath( [ 4201, 42 ] ) ).toBe( '/wp/v2/product?include=4201%2C42&per_page=2&_fields=id%2Ctitle' );
	} );

	it( 'categories stay on core WP REST, with no fallback', () => {
		expect( SOURCES.category.suggestionsPath( 'news' ) ).toBe( '/wp/v2/product_cat?search=news&per_page=20&_fields=id%2Cname' );
		expect( SOURCES.category.savedPath( [ 1, 2, 3 ] ) ).toBe( '/wp/v2/product_cat?include=1%2C2%2C3&per_page=3&_fields=id%2Cname' );
		expect( SOURCES.category.fallbackSuggestionsPath ).toBeUndefined();
		expect( SOURCES.category.fallbackSavedPath ).toBeUndefined();
	} );

	it( 'only id-scoped types have a source', () => {
		expect( SOURCES.all_products ).toBeUndefined();
		expect( SOURCES.all_subscriptions ).toBeUndefined();
	} );
} );

describe( 'saved-id degradation', () => {
	it( 'keeps unresolved saved ids as placeholder tokens when the engine route fails, resolving the rest through the fallback', async () => {
		apiFetch.mockImplementation( ( { path } ) => {
			if ( path.startsWith( '/wc-dynamic-pricing/v1/products' ) ) {
				return Promise.reject( new Error( 'rest_no_route' ) );
			}
			if ( path.startsWith( '/wp/v2/product?include=' ) ) {
				return Promise.resolve( [ { id: 4201, title: { rendered: 'Gold' } } ] );
			}
			return Promise.resolve( [] );
		} );
		const { findAllByText } = render( <ScopeTargets scopeType="product_ids" value={ [ 4201, 42 ] } onChange={ () => {} } /> );
		// The id the fallback resolved renders with its label; the id no route
		// resolved keeps a placeholder token instead of disappearing.
		expect( ( await findAllByText( 'Gold' ) ).length ).toBeGreaterThan( 0 );
		expect( ( await findAllByText( '#42' ) ).length ).toBeGreaterThan( 0 );
	}, 15000 );
} );
