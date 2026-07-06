/**
 * Tests for the tiered currency formatter (NPPD-1684).
 *
 * Expected strings assume the en-US default locale used by the test environment.
 */

/**
 * Internal dependencies
 */
import { formatCount, formatCurrency, formatPercent } from './format';

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

describe( 'formatCount', () => {
	it( 'renders below 1M in full with thousands separators and no title', () => {
		expect( formatCount( 98765 ) ).toEqual( { display: '98,765', title: null } );
	} );

	it( 'rounds a fractional value to a whole number (impressions-per-session case)', () => {
		expect( formatCount( 3.4 ) ).toEqual( { display: '3', title: null } );
	} );

	it( 'abbreviates 1M+ to the millions tier and carries the full value as a title', () => {
		expect( formatCount( 2400000 ) ).toEqual( { display: '2.4M', title: '2,400,000' } );
	} );

	it( 'treats exactly 1,000,000 as the abbreviated tier', () => {
		// Trailing ".0" varies by ICU version ("1M" vs "1.0M"); assert the invariants.
		const result = formatCount( 1000000 );
		expect( result.display ).toMatch( /^1(\.0)?M$/ );
		expect( result.title ).toBe( '1,000,000' );
	} );

	it( 'renders zero as 0 with no title', () => {
		expect( formatCount( 0 ) ).toEqual( { display: '0', title: null } );
	} );
} );

describe( 'formatPercent', () => {
	it( 'formats a normal fraction to one decimal', () => {
		expect( formatPercent( 0.123 ) ).toBe( '12.3%' );
	} );

	it( 'renders a genuine zero as 0%', () => {
		expect( formatPercent( 0 ) ).toBe( '0%' );
	} );

	it( 'renders a positive-but-tiny rate as <0.1% instead of a misleading 0% (NPPD-1746)', () => {
		// 12 conversions / 156,117 impressions = 0.0077% — would round to "0%".
		expect( formatPercent( 12 / 156117 ) ).toBe( '<0.1%' );
		expect( formatPercent( 0.0004 ) ).toBe( '<0.1%' );
	} );

	it( 'still rounds a rate at/above the display threshold to a real percent', () => {
		// >= 0.05% rounds to a shown "0.1%", not the <0.1% sentinel.
		expect( formatPercent( 0.0006 ) ).toBe( '0.1%' );
	} );
} );
