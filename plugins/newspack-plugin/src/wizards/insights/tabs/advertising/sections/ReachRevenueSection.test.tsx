/**
 * Tests for ReachRevenueSection empty states (NPPD-1697): the whole-section
 * no_opportunity collapse, the per-card no-revenue treatment on Total Revenue,
 * and the error-guard (an errored metric must NOT collapse / mask its error).
 *
 * The is_loading short-circuit is verified at the tab level in
 * AdvertisingTab.test.tsx (sections never see is_loading).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ReachRevenueSection from './ReachRevenueSection';
import type { InsightsWindow } from '../../../api/advertising';

const metrics = ( over: InsightsWindow = {} ): InsightsWindow => ( {
	total_impressions: { value: 2400000, computable: true, type: 'count' },
	total_revenue: { value: 4200, computable: true, type: 'currency' },
	// Cross-system derived scorecards (NPPD-1675): 4200 / 800k * 1000 = $5.25 RPM,
	// 2.4M / 800k = 3 impressions per session (whole-number `count`).
	rpm: { value: 5.25, computable: true, type: 'currency', numerator: 4200, denominator: 800000 },
	avg_impressions_per_session: { value: 3.0, computable: true, type: 'count', numerator: 2400000, denominator: 800000 },
	direct_vs_programmatic: {
		type: 'breakdown',
		computable: true,
		rows: [
			{ label: 'direct', revenue: 2520, impressions: 1320000 },
			{ label: 'programmatic', revenue: 1680, impressions: 1080000 },
		],
	},
	...over,
} );

/** Read a MetricCard's hero value by label (skips non-card nodes). */
const cardValueByLabel = ( container: HTMLElement, label: string ): string => {
	const labelEl = Array.from( container.querySelectorAll( '.newspack-insights__metric-card-label' ) ).find( el => el.textContent === label );
	return labelEl?.closest( '.newspack-insights__metric-card' )?.querySelector( '.newspack-insights__metric-card-value' )?.textContent ?? '';
};

describe( 'ReachRevenueSection empty states', () => {
	it( 'collapses to a no_opportunity EmptyMetricSection when hasWindowActivity is false', () => {
		const current = metrics( {
			total_impressions: { value: 0, computable: true, type: 'count' },
			total_revenue: { value: 0, computable: true, type: 'currency' },
			direct_vs_programmatic: { type: 'breakdown', computable: false, rows: [] },
		} );
		const { container } = render( <ReachRevenueSection current={ current } previous={ null } hasWindowActivity={ false } /> );

		expect( container.querySelector( '[data-empty-state="no_opportunity"]' ) ).toBeInTheDocument();
		// Assert on the container — the Notice's speak() duplicates copy into a live-region.
		expect( container ).toHaveTextContent( 'No ad impressions in this timeframe' );
		expect( screen.queryByText( 'Impressions' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the per-card no-revenue treatment on Total Revenue when impressions run but revenue is zero', () => {
		const current = metrics( {
			total_revenue: { value: 0, computable: true, type: 'currency' },
		} );
		// hasWindowActivity is true here (impressions > 0) — only the revenue card changes.
		// Prior window has lower impressions so the impressions card has a real delta to render.
		const previous = metrics( { total_impressions: { value: 2000000, computable: true, type: 'count' } } );
		const { container } = render( <ReachRevenueSection current={ current } previous={ previous } hasWindowActivity /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		// Real impressions count still shown — section not collapsed. The card value
		// abbreviates at 7 digits (2.4M); the no-revenue secondary line stays full.
		expect( screen.getByText( '2.4M' ) ).toBeInTheDocument();
		expect( screen.getByText( '2,400,000 impressions, but no revenue this timeframe' ) ).toBeInTheDocument();

		const cardByLabel = ( label: string ) =>
			Array.from( container.querySelectorAll( '.newspack-insights__metric-card-label' ) )
				.find( el => el.textContent === label )
				?.closest( '.newspack-insights__metric-card' );
		// The Total Revenue card shows $0 with the period delta suppressed.
		expect( cardByLabel( 'Revenue' )?.querySelector( '.newspack-insights__metric-card-delta' ) ).toBeNull();
		// Special-casing the revenue card must NOT suppress the sibling impressions
		// card's normal period comparison.
		expect( cardByLabel( 'Impressions' )?.querySelector( '.newspack-insights__metric-card-delta' ) ).not.toBeNull();
	} );

	it( 'does NOT collapse or show no-revenue when a metric errored — the card surfaces its own error', () => {
		const current = metrics( {
			total_revenue: { value: null, computable: false, error: 'GAM report failed' },
		} );
		const { container } = render( <ReachRevenueSection current={ current } previous={ null } hasWindowActivity={ undefined } /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /but no revenue this timeframe/ ) ).not.toBeInTheDocument();
		// The errored revenue card shows the shared graceful-failure note.
		expect( screen.getByText( 'Data temporarily unavailable.' ) ).toBeInTheDocument();
	} );

	it( 'renders the normal scorecards when populated', () => {
		const { container } = render( <ReachRevenueSection current={ metrics() } previous={ null } hasWindowActivity /> );

		expect( container.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
		// 7-digit impressions abbreviate to the millions tier (NPPD-1684 rule).
		expect( cardValueByLabel( container, 'Impressions' ) ).toBe( '2.4M' );
		expect( screen.getByText( 'Revenue' ) ).toBeInTheDocument();
	} );
} );

describe( 'ReachRevenueSection cross-system scorecards (NPPD-1675)', () => {
	it( 'renders RPM (currency, cents) and impressions per session (whole number)', () => {
		const { container } = render( <ReachRevenueSection current={ metrics() } previous={ null } hasWindowActivity /> );

		expect( screen.getByText( 'RPM' ) ).toBeInTheDocument();
		expect( cardValueByLabel( container, 'RPM' ) ).toBe( '$5.25' );

		expect( screen.getByText( 'Impressions per session' ) ).toBeInTheDocument();
		expect( cardValueByLabel( container, 'Impressions per session' ) ).toBe( '3' );
	} );

	it( 'shows period deltas on the derived scorecards under comparison', () => {
		const previous = metrics( {
			rpm: { value: 5.0, computable: true, type: 'currency', numerator: 4000, denominator: 800000 },
			avg_impressions_per_session: { value: 2.5, computable: true, type: 'count', numerator: 2000000, denominator: 800000 },
		} );
		const { container } = render( <ReachRevenueSection current={ metrics() } previous={ previous } hasWindowActivity /> );

		const deltaByLabel = ( label: string ) =>
			Array.from( container.querySelectorAll( '.newspack-insights__metric-card-label' ) )
				.find( el => el.textContent === label )
				?.closest( '.newspack-insights__metric-card' )
				?.querySelector( '.newspack-insights__metric-card-delta' );

		expect( deltaByLabel( 'RPM' ) ).not.toBeNull();
		expect( deltaByLabel( 'Impressions per session' ) ).not.toBeNull();
	} );

	it( 'renders the data-unavailable overlay when sessions are missing', () => {
		const current = metrics( {
			rpm: { value: null, computable: false, overlay: { type: 'data_unavailable' } },
			avg_impressions_per_session: { value: null, computable: false, overlay: { type: 'data_unavailable' } },
		} );
		const { container } = render( <ReachRevenueSection current={ current } previous={ null } hasWindowActivity /> );

		// The RPM label still renders; its value is not a misleading zero.
		expect( screen.getByText( 'RPM' ) ).toBeInTheDocument();
		expect( cardValueByLabel( container, 'RPM' ) ).not.toBe( '$0.00' );
	} );
} );
