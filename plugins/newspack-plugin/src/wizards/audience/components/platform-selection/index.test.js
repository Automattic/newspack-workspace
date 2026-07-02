import { OPTIONS, PLATFORM_PLUGINS } from './';

describe( 'PlatformSelection mapping', () => {
	it( 'offers the three platforms in order', () => {
		expect( OPTIONS.map( o => o.value ) ).toEqual( [ 'wc', 'nrh', 'other' ] );
	} );

	it( 'relabels the NRH platform as RevEngine', () => {
		expect( OPTIONS.find( o => o.value === 'nrh' ).title ).toBe( 'RevEngine' );
	} );

	it( 'maps each platform to its required plugins', () => {
		expect( PLATFORM_PLUGINS.wc ).toEqual( [ 'woocommerce', 'woocommerce-subscriptions', 'newspack-blocks' ] );
		expect( PLATFORM_PLUGINS.nrh ).toEqual( [ 'newspack-blocks' ] );
		expect( PLATFORM_PLUGINS.other ).toEqual( [ 'newspack-blocks' ] );
	} );
} );
