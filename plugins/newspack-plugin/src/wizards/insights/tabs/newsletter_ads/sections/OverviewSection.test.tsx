/**
 * Tests for the Newsletter Ads OverviewSection (NPPD-1861): scorecard render,
 * the non-computable CTR em-dash treatment (never "0%"), the excluded-ads
 * footnote, the delta-less lifetime cards, and the no_opportunity collapse.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import OverviewSection from './OverviewSection';
import type { InsightsWindow } from '../../../api/newsletter_ads';
const metrics = ( over: InsightsWindow = {} ): InsightsWindow => ( {
	lifetime_impressions: { value: 950000, computable: true, type: 'count' },
	lifetime_clicks: { value: 12400, computable: true, type: 'count' },
	total_impressions: { value: 84000, computable: true, type: 'count' },
	total_clicks: { value: 1200, computable: true, type: 'count' },
	ctr: { value: 0.0143, computable: true, type: 'rate', numerator: 1200, denominator: 84000 },
	total_revenue: { value: 4200, computable: true, type: 'currency' },
	ecpm: { value: 50, computable: true, type: 'currency' },
	active_ads: { value: 7, computable: true, type: 'count' },
	revenue_excluded_ads: { value: 0, computable: true, type: 'count' },
	...over,
} );

/** Locate a MetricCard by its label. */
const cardByLabel = ( container: HTMLElement, label: string ) =>
	Array.from( container.querySelectorAll( '.newspack-insights__metric-card-label' ) )
		.find( el => el.textContent === label )
		?.closest( '.newspack-insights__metric-card' );

describe( 'OverviewSection', () => {
	it( 'renders the timeframe scorecards and the all-time cards', () => {
		const { container } = render( <OverviewSection current={ metrics() } previous={ null } hasWindowActivity /> );

		expect( screen.getByText( 'Impressions' ) ).toBeInTheDocument();
		expect( screen.getByText( '84,000' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Clicks' ) ).toBeInTheDocument();
		expect( screen.getByText( '$4,200' ) ).toBeInTheDocument();
		expect( screen.getByText( '$50.00' ) ).toBeInTheDocument(); // eCPM keeps cents below $1K.
		expect( screen.getByText( '1.4%' ) ).toBeInTheDocument(); // CTR as a percent.
		// All-time cards, clearly labeled, values from the lifetime counters.
		expect( cardByLabel( container, 'All-Time Impressions' ) ).toHaveTextContent( '950,000' );
		expect( cardByLabel( container, 'All-Time Clicks' ) ).toHaveTextContent( '12,400' );
	} );

	it( 'never renders a delta on the lifetime cards, even under comparison', () => {
		const previous = metrics( { total_impressions: { value: 70000, computable: true, type: 'count' } } );
		const { container } = render( <OverviewSection current={ metrics() } previous={ previous } hasWindowActivity /> );

		// The timeframe impressions card compares normally…
		expect( cardByLabel( container, 'Impressions' )?.querySelector( '.newspack-insights__metric-card-delta' ) ).not.toBeNull();
		// …but the cumulative all-time cards never carry a delta.
		expect( cardByLabel( container, 'All-Time Impressions' )?.querySelector( '.newspack-insights__metric-card-delta' ) ).toBeNull();
		expect( cardByLabel( container, 'All-Time Clicks' )?.querySelector( '.newspack-insights__metric-card-delta' ) ).toBeNull();
	} );

	it( 'renders the em-dash treatment for a non-computable CTR — never 0%', () => {
		const current = metrics( { ctr: { value: null, computable: false, type: 'rate', numerator: 0, denominator: 0 } } );
		const { container } = render( <OverviewSection current={ current } previous={ null } hasWindowActivity /> );

		const ctrCard = cardByLabel( container, 'CTR' );
		expect( ctrCard ).toBeTruthy();
		expect( ctrCard?.querySelector( '.newspack-insights__metric-card-na' ) ).toBeInTheDocument();
		expect( screen.getByText( 'No impressions in this timeframe to calculate a click-through rate.' ) ).toBeInTheDocument();
		expect( ctrCard ).not.toHaveTextContent( '0%' );
	} );

	it( 'renders the em-dash treatment for a non-computable eCPM — never $0.00', () => {
		// The Austin-shaped window: clicks exist, impressions are zero, so the
		// eCPM division is undefined. Currency cards opt in to the em-dash
		// treatment via an explicit notComputableMessage (NPPD-1861).
		const current = metrics( { ecpm: { value: 0, computable: false, type: 'currency' } } );
		const { container } = render( <OverviewSection current={ current } previous={ null } hasWindowActivity /> );

		const ecpmCard = cardByLabel( container, 'eCPM' );
		expect( ecpmCard ).toBeTruthy();
		expect( ecpmCard?.querySelector( '.newspack-insights__metric-card-na' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Requires both revenue and impressions in this timeframe to calculate.' ) ).toBeInTheDocument();
		expect( ecpmCard ).not.toHaveTextContent( '$0.00' );
	} );

	it( 'shows the excluded-ads footnote when revenue_excluded_ads > 0', () => {
		const current = metrics( { revenue_excluded_ads: { value: 3, computable: true, type: 'count' } } );
		render( <OverviewSection current={ current } previous={ null } hasWindowActivity /> );

		expect( screen.getByText( '3 ads excluded from revenue (missing price or flight dates)' ) ).toBeInTheDocument();
	} );

	it( 'hides the excluded-ads footnote at zero', () => {
		render( <OverviewSection current={ metrics() } previous={ null } hasWindowActivity /> );
		expect( screen.queryByText( /excluded from revenue/ ) ).not.toBeInTheDocument();
	} );

	it( 'collapses to a no_opportunity EmptyMetricSection when hasWindowActivity is false', () => {
		const { container } = render( <OverviewSection current={ metrics() } previous={ null } hasWindowActivity={ false } /> );

		expect( container.querySelector( '[data-empty-state="no_opportunity"]' ) ).toBeInTheDocument();
		expect( container ).toHaveTextContent( 'No newsletter ad activity in this timeframe' );
		expect( screen.queryByText( '84,000' ) ).not.toBeInTheDocument();
	} );

	it( 'does NOT collapse while the signal is absent (loading / not ready)', () => {
		const { container } = render( <OverviewSection current={ metrics() } previous={ null } hasWindowActivity={ undefined } /> );
		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '84,000' ) ).toBeInTheDocument();
	} );
} );
