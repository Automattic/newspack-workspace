/**
 * Tests for WindowedSection empty states (NPPD-1696): the whole-section
 * no_opportunity collapse, the per-card no-acquisition treatment, the
 * one-time/recurring mode suppression on the revenue breakdown line, and the
 * currency zeroFallback. The split here is deliberate — EmptyMetricSection for
 * whole-section emptiness, per-card treatments for legitimate zeros inside an
 * otherwise-populated section that also carries real revenue.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import WindowedSection from './WindowedSection';
import type { DonorsWindow } from '../../api/donors';
import type { DateRange } from '../../state/useDateRange';

const RANGE: DateRange = { preset: 'last-30', start: '2026-05-18', end: '2026-06-16' };

const makeWindow = ( over: Partial< DonorsWindow > = {} ): DonorsWindow => ( {
	window: { start: '2026-05-18', end: '2026-06-16' },
	new_donors: 12,
	lapsed_donors: 3,
	one_time_revenue: 800,
	recurring_revenue: 1200,
	total_revenue: 2000,
	average_gift: 65,
	lapsed_donor_recovery_rate: { value: 0.1, computable: true, denominator: 30 },
	recurring_donor_retention: { value: 0.8, computable: true, denominator: 50 },
	donations_by_tier: [],
	donations_by_campaign: [],
	has_window_activity: true,
	...over,
} );

/**
 * The breakdown line lives in the Total donation revenue card's `secondary`
 * slot. We scope assertions to that element specifically — the card's
 * `description` ("One-time gifts + recurring renewals…") and the Lapsed donors
 * description ("stopped recurring giving") both contain "one-time"/"recurring",
 * so a loose text query would match the wrong node.
 */
const revenueBreakdownText = ( container: HTMLElement ): string => {
	const label = Array.from( container.querySelectorAll( '.newspack-insights__metric-card-label' ) ).find(
		el => el.textContent === 'Total donation revenue'
	);
	const card = label?.closest( '.newspack-insights__metric-card' );
	return card?.querySelector( '.newspack-insights__metric-card-secondary' )?.textContent ?? '';
};

describe( 'WindowedSection empty states', () => {
	it( 'collapses to a no_opportunity EmptyMetricSection when the window saw no activity', () => {
		const current = makeWindow( {
			has_window_activity: false,
			new_donors: 0,
			lapsed_donors: 0,
			one_time_revenue: 0,
			recurring_revenue: 0,
			total_revenue: 0,
			average_gift: 0,
		} );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeDonors={ 0 } /> );

		expect( container.querySelector( '[data-empty-state="no_opportunity"]' ) ).toBeInTheDocument();
		// Assert body on the container — the Notice's speak() duplicates copy into a
		// global live-region, so a screen-level text query would match twice.
		expect( container ).toHaveTextContent( 'No donations in this timeframe' );
		// The grid (and its cards) is gone.
		expect( screen.queryByText( 'Lapsed donors' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Total donation revenue' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the per-card no-acquisition state on New donors without collapsing the section', () => {
		// Section has revenue and a lapse but no first-time donors: the New donors card
		// gets the existing-donor context line, while the real revenue cards still render.
		const current = makeWindow( { new_donors: 0, lapsed_donors: 2, total_revenue: 1500 } );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ makeWindow() } activeDonors={ 42 } /> );

		// No whole-section empty state — the section is NOT collapsed.
		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '42 active donors, but none new this timeframe' ) ).toBeInTheDocument();
		// The real revenue cards are still present.
		expect( screen.getByText( 'Total donation revenue' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Lapsed donors' ) ).toBeInTheDocument();
	} );

	it( 'does NOT show the no-acquisition line when there are no active donors either', () => {
		// new_donors 0 AND activeDonors 0 — there is simply no donor base to contextualize;
		// the card renders a plain zero, not the "existing donors active" line. (In practice
		// has_window_activity would usually be false here, but guard the per-card branch.)
		const current = makeWindow( { new_donors: 0, lapsed_donors: 1, total_revenue: 500 } );
		render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeDonors={ 0 } /> );

		expect( screen.queryByText( /existing donors active/ ) ).not.toBeInTheDocument();
	} );

	it( 'renders every card with the full one-time + recurring breakdown when populated', () => {
		const current = makeWindow();
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeDonors={ 200 } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'New donors' ) ).toBeInTheDocument();

		const breakdown = revenueBreakdownText( container );
		expect( breakdown ).toMatch( /one-time/ );
		expect( breakdown ).toMatch( /recurring/ );
		expect( breakdown ).toContain( '+' );
	} );

	it( 'suppresses the empty mode in the revenue breakdown — recurring-only window', () => {
		const current = makeWindow( { one_time_revenue: 0, recurring_revenue: 1200, total_revenue: 1200, average_gift: 0 } );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeDonors={ 50 } /> );

		// The breakdown line shows recurring only — no "one-time" half, no "$0 one-time", no "+".
		const breakdown = revenueBreakdownText( container );
		expect( breakdown ).toMatch( /recurring/ );
		expect( breakdown ).not.toMatch( /one-time/ );
		expect( breakdown ).not.toContain( '+' );
		// Average one-time gift falls back to a count message rather than $0.00.
		expect( screen.getByText( 'No one-time gifts in this timeframe' ) ).toBeInTheDocument();
	} );

	it( 'suppresses the empty mode in the revenue breakdown — one-time-only window', () => {
		const current = makeWindow( { one_time_revenue: 800, recurring_revenue: 0, total_revenue: 800, average_gift: 40 } );
		const { container } = render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeDonors={ 50 } /> );

		const breakdown = revenueBreakdownText( container );
		expect( breakdown ).toMatch( /one-time/ );
		expect( breakdown ).not.toMatch( /recurring/ );
		expect( breakdown ).not.toContain( '+' );
	} );

	it( 'shows the Total donation revenue count fallback when revenue is a real zero', () => {
		// A window where only lapses happened: section renders (has_window_activity true),
		// but total revenue is genuinely $0 → "No donations in this timeframe", not "$0.00".
		const current = makeWindow( {
			new_donors: 0,
			lapsed_donors: 4,
			one_time_revenue: 0,
			recurring_revenue: 0,
			total_revenue: 0,
			average_gift: 0,
		} );
		render( <WindowedSection range={ RANGE } current={ current } previous={ null } activeDonors={ 30 } /> );

		expect( screen.getByText( 'No donations in this timeframe' ) ).toBeInTheDocument();
	} );
} );
