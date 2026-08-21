/**
 * Unit tests for the scope-target picker's endpoint paths — the behavior that
 * decides whether variations can be targeted (engine route) and how saved ids
 * hydrate. Paths only; the token field itself is stubbed out.
 */
// Factory mock so importing the module under test never loads the real
// @wordpress/components barrel through the package index.
jest.mock( '../../../../../packages/components/src', () => ( {
	AutocompleteTokenField: () => null,
} ) );

import { SOURCES } from './scope-targets';

describe( 'scope-targets SOURCES', () => {
	it( 'products search uses the engine route, which serves variations', () => {
		expect( SOURCES.product_ids.suggestionsPath( 'gold' ) ).toBe(
			'/wc-dynamic-pricing/v1/products?search=gold&per_page=20'
		);
	} );

	it( 'saved product ids hydrate through the engine route include param', () => {
		expect( SOURCES.product_ids.savedPath( [ 4201, 42 ] ) ).toBe(
			'/wc-dynamic-pricing/v1/products?include=4201%2C42'
		);
	} );

	it( 'categories stay on core WP REST', () => {
		expect( SOURCES.category.suggestionsPath( 'news' ) ).toBe(
			'/wp/v2/product_cat?search=news&per_page=20&_fields=id%2Cname'
		);
		expect( SOURCES.category.savedPath( [ 1, 2, 3 ] ) ).toBe(
			'/wp/v2/product_cat?include=1%2C2%2C3&per_page=3&_fields=id%2Cname'
		);
	} );

	it( 'only id-scoped types have a source', () => {
		expect( SOURCES.all_products ).toBeUndefined();
		expect( SOURCES.all_subscriptions ).toBeUndefined();
	} );
} );
