/**
 * Tests for the tiered currency formatter (NPPD-1684).
 *
 * Named `.test.js` (not `.ts`) so the suite is actually collected: the current
 * testMatch only matches `test.js` / `test.jsx`, excluding `.ts`/`.tsx`
 * (NPPD-1683). The `./format` import still resolves to format.ts via Jest's
 * default moduleFileExtensions + the TS-aware babel transform. Expected strings
 * assume the en-US default locale used by the test environment.
 */

/**
 * Internal dependencies
 */
import { formatCurrency } from './format';

describe( 'formatCurrency', () => {
	it( 'keeps cents below $1,000', () => {
		expect( formatCurrency( 89.42 ) ).toEqual( { display: '$89.42', title: null } );
	} );

	it( 'drops cents from $1,000 to under $1M', () => {
		expect( formatCurrency( 41690.29 ) ).toEqual( { display: '$41,690', title: null } );
	} );

	it( 'abbreviates $1M+ and carries the full value as a title', () => {
		expect( formatCurrency( 1234567.89 ) ).toEqual( { display: '$1.2M', title: '$1,234,567.89' } );
	} );

	it( 'treats exactly $1,000 as the no-cents tier', () => {
		expect( formatCurrency( 1000 ) ).toEqual( { display: '$1,000', title: null } );
	} );

	it( 'treats exactly $1,000,000 as the abbreviated tier', () => {
		// The compact display's trailing ".0" varies by ICU version ("$1M" on
		// newer Node, "$1.0M" on older). Assert the meaningful invariants — the
		// abbreviated tier was taken (full-value title present) and the rounded
		// magnitude — without pinning the ICU-specific form.
		const result = formatCurrency( 1000000 );
		expect( result.display ).toMatch( /^\$1(\.0)?M$/ );
		expect( result.title ).toBe( '$1,000,000.00' );
	} );

	it( 'tiers negatives by magnitude and keeps the sign', () => {
		expect( formatCurrency( -1234.56 ) ).toEqual( { display: '-$1,235', title: null } );
	} );

	it( 'renders zero with cents and no title', () => {
		expect( formatCurrency( 0 ) ).toEqual( { display: '$0.00', title: null } );
	} );
} );
