/**
 * Tests for the Subscribers wizard formatting helpers.
 *
 * These pin the non-obvious formatting decisions: that a billing rate collapses
 * cleanly when data is missing, that every date the wizard shows — absolute and
 * relative alike — is read on the site's calendar rather than the viewer's, and
 * — most importantly — that the schedule row is driven by the dates, not the
 * status, so a subscription WooCommerce is winding down still tells the admin
 * when it ends.
 */

/**
 * WordPress dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import { fmtCurrency, fmtRelative, billingText, orDash, scheduleRow } from './format';

// Two sites whose calendar day disagrees with UTC's, in opposite directions: one
// where UTC has rolled into the next day and the site has not, and one where the
// site has rolled over and UTC has not. Asserting on both is what makes a
// timezone test bite — a regression to UTC or to browser-local "today" moves the
// answer in one direction or the other, so neither can pass both.
const SITE_BEHIND_UTC = { offset: -10, string: 'Pacific/Honolulu', abbr: 'HST' };
const SITE_AHEAD_OF_UTC = { offset: 14, string: 'Pacific/Kiritimati', abbr: '+14' };

// Captured before any test mutates it, so restoring is restoring, not re-reading
// a fixture.
const REAL_DATE_SETTINGS = getSettings();

const pinSite = ( timezone, nowUtc ) => {
	setSettings( { ...REAL_DATE_SETTINGS, timezone } );
	jest.useFakeTimers().setSystemTime( new Date( nowUtc ) );
};

// Used from afterEach rather than at the end of each test, so that a failing
// expect cannot leak the fixture timezone or the fake clock into the rest of the
// suite — which runs whole, and would then fail somewhere unrelated.
const unpinSite = () => {
	jest.useRealTimers();
	setSettings( REAL_DATE_SETTINGS );
};

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

// The relative line sits directly under the absolute date in the "Member since"
// and "Last seen" cells, so it has to describe the same civil day that line
// shows. It gets there differently from fmtDate — humanTimeDiff reads a bare date
// as midnight in the SITE's zone and measures from now, where fmtDate anchors at
// UTC in order to print the stored day verbatim — but both land on the site's
// calendar, which is the property worth pinning.
describe( 'fmtRelative', () => {
	afterEach( unpinSite );

	it( 'reads a site ahead of UTC, where the site day has already rolled over', () => {
		pinSite( SITE_AHEAD_OF_UTC, '2026-01-01T12:00:00Z' ); // 2026-01-02 02:00 in Kiritimati.

		// Two hours into the site's today. Anchoring the bare date at UTC midnight
		// instead would put it twelve hours in the *future* and render the member's
		// join date as "in 12 hours".
		expect( fmtRelative( '2026-01-02' ) ).toBe( '2 hours ago' );
	} );

	it( 'reads a site behind UTC, where the site day has not yet rolled over', () => {
		pinSite( SITE_BEHIND_UTC, '2026-01-02T05:00:00Z' ); // 2026-01-01 19:00 in Honolulu.

		// Still the site's today; a UTC anchor would put it 29 hours back and age it
		// to "a day ago".
		expect( fmtRelative( '2026-01-01' ) ).toBe( '19 hours ago' );
	} );

	it( 'renders nothing for a missing date', () => {
		expect( fmtRelative( '' ) ).toBe( '' );
		expect( fmtRelative( null ) ).toBe( '' );
		expect( fmtRelative( undefined ) ).toBe( '' );
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
	// on that same basis, whatever zone the admin's browser is in.
	describe( "deciding Ends/Ended on the site's calendar day", () => {
		afterEach( unpinSite );

		it( 'reads a site behind UTC, where the site day has not yet rolled over', () => {
			pinSite( SITE_BEHIND_UTC, '2026-01-02T05:00:00Z' ); // 2026-01-01 19:00 in Honolulu.

			// The plan ends *today* for the publisher, so it still reads "Ends".
			expect( scheduleRow( { nextBillingDate: null, endDate: '2026-01-01' } ).label ).toBe( 'Ends' );
			expect( scheduleRow( { nextBillingDate: null, endDate: '2025-12-31' } ).label ).toBe( 'Ended' );
		} );

		it( 'reads a site ahead of UTC, where the site day has already rolled over', () => {
			pinSite( SITE_AHEAD_OF_UTC, '2026-01-01T12:00:00Z' ); // 2026-01-02 02:00 in Kiritimati.

			expect( scheduleRow( { nextBillingDate: null, endDate: '2026-01-02' } ).label ).toBe( 'Ends' );
			// Yesterday on the site, even though it is still today in UTC.
			expect( scheduleRow( { nextBillingDate: null, endDate: '2026-01-01' } ).label ).toBe( 'Ended' );
		} );
	} );
} );
