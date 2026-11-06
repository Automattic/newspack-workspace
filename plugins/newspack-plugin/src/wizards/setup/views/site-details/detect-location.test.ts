import { currencyFor, detectCountrySource } from './detect-location';

const codes = [ 'GB', 'CA', 'FR', 'US', 'AQ', 'IN', 'UA' ];
const map = { GB: 'GBP', CA: 'CAD', FR: 'EUR', US: 'USD' };

describe( 'detectCountrySource', () => {
	it( 'uses the time zone first', () => {
		expect( detectCountrySource( 'Europe/London', 'en_US', codes )?.country ).toBe( 'GB' );
		expect( detectCountrySource( 'America/Toronto', 'en_US', codes )?.country ).toBe( 'CA' );
	} );

	it( 'knows the legacy zone ids browsers still report', () => {
		expect( detectCountrySource( 'Asia/Calcutta', 'en_US', codes )?.country ).toBe( 'IN' );
		expect( detectCountrySource( 'Europe/Kiev', 'en_US', codes )?.country ).toBe( 'UA' );
		expect( detectCountrySource( 'Europe/Simferopol', 'en_US', codes )?.country ).toBe( 'UA' );
	} );

	it( 'reports where the guess came from', () => {
		expect( detectCountrySource( 'Europe/London', 'en_US', codes ) ).toEqual( { country: 'GB', source: 'zone' } );
		expect( detectCountrySource( 'UTC', 'fr_FR', codes ) ).toEqual( { country: 'FR', source: 'locale' } );
	} );

	it( 'falls back to the region of the site locale', () => {
		expect( detectCountrySource( 'UTC', 'fr_FR', codes )?.country ).toBe( 'FR' );
	} );

	it( 'returns null when neither points at a known country', () => {
		expect( detectCountrySource( 'UTC', 'en', codes ) ).toBeNull();
		expect( detectCountrySource( 'Europe/Paris', 'en', [ 'GB' ] ) ).toBeNull();
	} );
} );

describe( 'currencyFor', () => {
	it( 'maps a country to its currency', () => {
		expect( currencyFor( 'GB', map ) ).toBe( 'GBP' );
	} );

	it( 'returns null without a country or a hint', () => {
		expect( currencyFor( null, map ) ).toBeNull();
		expect( currencyFor( 'AQ', map ) ).toBeNull();
	} );
} );
