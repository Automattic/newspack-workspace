/**
 * Tests for the Subscribers wizard formatting helpers.
 *
 * These pin the non-obvious formatting decisions: that a billing rate collapses
 * cleanly when data is missing, and — most importantly — that the schedule row
 * is driven by the dates, not the status, so a subscription WooCommerce is
 * winding down still tells the admin when it ends.
 */

/**
 * WordPress dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { fmtCurrency, billingText, orDash, scheduleRow } from './format';

describe( 'fmtCurrency', () => {
	it( 'formats an amount with its currency', () => {
		// Non-breaking space and symbol placement vary by CI locale data, so assert
		// on the parts that are invariant rather than an exact string.
		const formatted = fmtCurrency( 12.5, 'USD' );
		expect( formatted ).toContain( '12.50' );
		expect( formatted ).toContain( '$' );
	} );

	it( 'falls back to a plain two-decimal amount for an unknown currency', () => {
		expect( fmtCurrency( 12.5, 'NOTACODE' ) ).toBe( '12.50' );
	} );

	it( 'returns an empty string when there is no numeric amount', () => {
		expect( fmtCurrency( null, 'USD' ) ).toBe( '' );
		expect( fmtCurrency( undefined, 'USD' ) ).toBe( '' );
		expect( fmtCurrency( '', 'USD' ) ).toBe( '' );
	} );
} );

describe( 'billingText', () => {
	// The currency symbol/prefix is the environment's locale data (a bare Node
	// gives "US$10.00", a browser in en-US "$10.00"), so assert on the invariant
	// structure — amount, separator, period — not the exact prefix.
	it( 'renders "amount / period" for a single-interval cycle', () => {
		expect( billingText( { amount: 10, currency: 'USD', billingPeriod: 'month', billingInterval: 1 } ) ).toMatch( /10\.00 \/ month$/ );
	} );

	it( 'counts the period for a multi-interval cycle', () => {
		expect( billingText( { amount: 30, currency: 'USD', billingPeriod: 'month', billingInterval: 3 } ) ).toMatch( /30\.00 \/ 3 months$/ );
	} );

	it( 'shows the bare price when the period is unknown, and a dash when there is no amount', () => {
		expect( billingText( { amount: 10, currency: 'USD', billingPeriod: 'fortnight', billingInterval: 1 } ) ).toMatch( /10\.00$/ );
		expect( billingText( { amount: 10, currency: 'USD', billingPeriod: 'fortnight', billingInterval: 1 } ) ).not.toContain( '/' );
		expect( billingText( { amount: null, currency: 'USD', billingPeriod: 'month', billingInterval: 1 } ) ).toBe( '—' );
		expect( billingText() ).toBe( '—' );
	} );
} );

describe( 'orDash', () => {
	it( 'passes a value through and dashes an empty one', () => {
		expect( orDash( 'x' ) ).toBe( 'x' );
		expect( orDash( '' ) ).toBe( '—' );
		expect( orDash( undefined ) ).toBe( '—' );
	} );
} );

describe( 'scheduleRow', () => {
	it( 'shows next billing when there is a next-billing date', () => {
		const row = scheduleRow( { status: 'active', nextBillingDate: '2099-08-01', endDate: null } );
		expect( row.label ).toBe( 'Next billing' );
		expect( row.value ).not.toBe( '—' );
	} );

	it( 'shows a future end date as "Ends" — the pending-cancel case with no next payment', () => {
		// A subscription WooCommerce is winding down maps to status "active" but has
		// its next payment deleted and an end date in the prepaid term. The row must
		// surface that it is ending, not a blank "Next billing —".
		const row = scheduleRow( { status: 'active', nextBillingDate: null, endDate: '2099-08-01' } );
		expect( row.label ).toBe( 'Ends' );
		expect( row.value ).not.toBe( '—' );
	} );

	it( 'shows a past end date as "Ended"', () => {
		const row = scheduleRow( { status: 'cancelled', nextBillingDate: null, endDate: '2000-01-01' } );
		expect( row.label ).toBe( 'Ended' );
	} );

	it( 'prefers next billing over an end date when both are present', () => {
		const row = scheduleRow( { status: 'active', nextBillingDate: '2099-08-01', endDate: '2099-12-01' } );
		expect( row.label ).toBe( 'Next billing' );
	} );

	it( 'dashes the value when neither date is present', () => {
		const row = scheduleRow( { status: 'active', nextBillingDate: null, endDate: null } );
		expect( row.label ).toBe( 'Next billing' );
		expect( row.value ).toBe( '—' );
	} );

	// The endpoint derives endDate in the SITE's timezone, so "today" must be read
	// on that same basis. Pinned with a site 10 hours behind UTC and an instant
	// that has already rolled over in UTC but not on the site: the plan ends
	// *today* for the publisher, so it reads "Ends", whatever zone the admin's
	// browser is in.
	it( "decides Ends/Ended on the site's calendar day, not the viewer's", () => {
		const settings = getSettings();
		setSettings( { ...settings, timezone: { offset: -10, string: 'Pacific/Honolulu', abbr: 'HST' } } );
		jest.useFakeTimers().setSystemTime( new Date( '2026-01-02T05:00:00Z' ) ); // 2026-01-01 19:00 in Honolulu.

		expect( scheduleRow( { nextBillingDate: null, endDate: '2026-01-01' } ).label ).toBe( 'Ends' );
		expect( scheduleRow( { nextBillingDate: null, endDate: '2025-12-31' } ).label ).toBe( 'Ended' );

		jest.useRealTimers();
		setSettings( settings );
	} );
} );
