/**
 * Internal dependencies
 */
import { scopeTargeting } from './scope';

describe( 'scopeTargeting', () => {
	it( 'maps every scope type the engine can return', () => {
		expect( scopeTargeting( 'product_ids' ) ).toBe( 'products' );
		expect( scopeTargeting( 'category' ) ).toBe( 'category' );
		expect( scopeTargeting( 'all_products' ) ).toBe( 'all' );
		expect( scopeTargeting( 'all_subscriptions' ) ).toBeNull();
	} );

	it( 'leaves a scope type it does not know to the engine label', () => {
		expect( scopeTargeting( 'something_new' ) ).toBeNull();
	} );
} );
