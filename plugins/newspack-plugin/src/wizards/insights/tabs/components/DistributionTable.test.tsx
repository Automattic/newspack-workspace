/**
 * Tests for the shared DistributionTable component (Task 6, viz-consolidation).
 *
 * Covers: renders all bucket rows (label / count / pct), renders a caption
 * when provided, and omits the caption element when the prop is absent.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import DistributionTable, { type DistributionBucket } from './DistributionTable';

const buckets: DistributionBucket[] = [
	{ label: '1', count: 50, pct: 0.5 },
	{ label: '2–3', count: 30, pct: 0.3 },
	{ label: '4–9', count: 15, pct: 0.15 },
	{ label: '10+', count: 5, pct: 0.05 },
];

describe( 'DistributionTable', () => {
	it( 'renders a row for each bucket with label, count, and pct', () => {
		render( <DistributionTable buckets={ buckets } /> );

		// Labels
		expect( screen.getByText( '1' ) ).toBeInTheDocument();
		expect( screen.getByText( '2–3' ) ).toBeInTheDocument();
		expect( screen.getByText( '4–9' ) ).toBeInTheDocument();
		expect( screen.getByText( '10+' ) ).toBeInTheDocument();

		// Counts (formatNumber renders integers without decimals)
		expect( screen.getByText( '50' ) ).toBeInTheDocument();
		expect( screen.getByText( '30' ) ).toBeInTheDocument();
		expect( screen.getByText( '15' ) ).toBeInTheDocument();
		expect( screen.getByText( '5' ) ).toBeInTheDocument();

		// Percents (formatPercent formats a fraction in [0,1] as "50%", "30%", etc.)
		expect( screen.getByText( '50%' ) ).toBeInTheDocument();
		expect( screen.getByText( '30%' ) ).toBeInTheDocument();
		expect( screen.getByText( '15%' ) ).toBeInTheDocument();
		expect( screen.getByText( '5%' ) ).toBeInTheDocument();
	} );

	it( 'renders the caption paragraph when caption is provided', () => {
		render( <DistributionTable buckets={ buckets } caption="Of readers who converted, this is how many gates they saw first." /> );
		expect( screen.getByText( 'Of readers who converted, this is how many gates they saw first.' ) ).toBeInTheDocument();
	} );

	it( 'omits the caption element when caption is not provided', () => {
		const { container } = render( <DistributionTable buckets={ buckets } /> );
		expect( container.querySelector( '.newspack-insights__distribution-caption' ) ).not.toBeInTheDocument();
	} );
} );
