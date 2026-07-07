/**
 * Tests for the Newsletter Ads TrendSection (NPPD-1861): the split
 * impressions/clicks ChartCards and the previous-period compare overlay.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import TrendSection from './TrendSection';
import type { InsightsWindow } from '../../../api/newsletter_ads';

const metrics = (): InsightsWindow => ( {
	performance_by_day: {
		type: 'timeseries',
		computable: true,
		rows: [
			{ date: '2026-06-01', impressions: 3200, clicks: 48 },
			{ date: '2026-06-02', impressions: 2800, clicks: 41 },
			{ date: '2026-06-03', impressions: 3600, clicks: 55 },
		],
	},
} );

describe( 'TrendSection', () => {
	it( 'renders impressions and clicks as two separate charts', () => {
		const { container } = render( <TrendSection current={ metrics() } previous={ null } /> );

		expect( screen.getByText( 'Performance trend' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Impressions' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Clicks' ) ).toBeInTheDocument();
		// One series per chart without comparison → two line strokes total.
		expect( container.querySelectorAll( '.newspack-insights__line-stroke' ) ).toHaveLength( 2 );
	} );

	it( 'overlays the previous period on each chart when comparing', () => {
		const { container } = render( <TrendSection current={ metrics() } previous={ metrics() } /> );

		// Two series per chart with comparison → four line strokes total.
		expect( container.querySelectorAll( '.newspack-insights__line-stroke' ) ).toHaveLength( 4 );
		expect( screen.getAllByText( 'Previous period' ).length ).toBeGreaterThan( 0 );
	} );
} );
