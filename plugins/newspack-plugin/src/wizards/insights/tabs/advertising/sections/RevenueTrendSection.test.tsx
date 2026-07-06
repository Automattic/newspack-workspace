/**
 * Tests for RevenueTrendSection (NPPD-1674): the daily-revenue line chart and
 * its comparison overlay.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import RevenueTrendSection from './RevenueTrendSection';
import type { InsightsWindow } from '../../../api/advertising';
import type { MetricRow } from '../../components/metrics';

const metrics = (
	rows: MetricRow[] = [
		{ date: '2026-06-01', value: 100 },
		{ date: '2026-06-02', value: 120 },
		{ date: '2026-06-03', value: 140 },
	]
): InsightsWindow => ( {
	revenue_by_day: { type: 'timeseries', computable: true, rows },
} );

describe( 'RevenueTrendSection', () => {
	it( 'renders the revenue trend section with a chart', () => {
		const { container } = render( <RevenueTrendSection current={ metrics() } previous={ null } /> );

		expect( screen.getByText( 'Revenue trend' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Daily revenue across the selected period.' ) ).toBeInTheDocument();
		// One series → one line stroke.
		expect( container.querySelectorAll( '.newspack-insights__line-stroke' ) ).toHaveLength( 1 );
	} );

	it( 'overlays a second (prior-period) line under comparison', () => {
		const previous = metrics( [
			{ date: '2026-05-01', value: 80 },
			{ date: '2026-05-02', value: 90 },
			{ date: '2026-05-03', value: 95 },
		] );
		const { container } = render( <RevenueTrendSection current={ metrics() } previous={ previous } /> );

		expect( container.querySelectorAll( '.newspack-insights__line-stroke' ) ).toHaveLength( 2 );
	} );
} );
