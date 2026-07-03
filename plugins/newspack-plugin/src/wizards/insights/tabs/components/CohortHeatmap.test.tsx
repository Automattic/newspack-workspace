/**
 * Tests for the CohortHeatmap grid: rows/columns from the cohort union, percent
 * formatting, blank cells for missing periods, caption + target callout, empty state.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CohortHeatmap, { type CohortHeatmapRow } from './CohortHeatmap';

const cohorts: CohortHeatmapRow[] = [
	{
		label: '2025-07',
		points: [
			{ period: 0, value: 1 },
			{ period: 3, value: 0.8 },
			{ period: 6, value: 0.68 },
		],
	},
	{
		label: '2025-08',
		points: [
			{ period: 0, value: 1 },
			{ period: 3, value: 0.84 },
		],
	},
];

describe( 'CohortHeatmap', () => {
	it( 'renders a row per cohort and a column per period in the union', () => {
		render( <CohortHeatmap cohorts={ cohorts } /> );
		expect( screen.getByRole( 'rowheader', { name: '2025-07' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'rowheader', { name: '2025-08' } ) ).toBeInTheDocument();
		// Period-6 column exists even though only one cohort reached it.
		expect( screen.getByRole( 'columnheader', { name: '6' } ) ).toBeInTheDocument();
	} );

	it( 'formats cell values as percentages by default', () => {
		render( <CohortHeatmap cohorts={ cohorts } /> );
		expect( screen.getByText( '80%' ) ).toBeInTheDocument();
		expect( screen.getByText( '68%' ) ).toBeInTheDocument();
		expect( screen.getByText( '84%' ) ).toBeInTheDocument();
	} );

	it( 'leaves a blank cell where a cohort has no point for a period', () => {
		const { container } = render( <CohortHeatmap cohorts={ cohorts } /> );
		// 2025-08 has no period-6 value → exactly one is-empty cell.
		expect( container.querySelectorAll( '.newspack-insights__cohort-heatmap-cell.is-empty' ) ).toHaveLength( 1 );
	} );

	it( 'renders the columns caption and the target callout', () => {
		render( <CohortHeatmap cohorts={ cohorts } columnsLabel="Months since cohort start" referenceLabel="70% at 12 months" /> );
		expect( screen.getByText( 'Months since cohort start' ) ).toBeInTheDocument();
		expect( screen.getByText( /70% at 12 months/ ) ).toBeInTheDocument();
	} );

	it( 'renders the empty message when no cohort carries any points', () => {
		render( <CohortHeatmap cohorts={ [ { label: '2025-07', points: [] } ] } emptyMessage="No cohort data available yet." /> );
		expect( screen.getByText( 'No cohort data available yet.' ) ).toBeInTheDocument();
	} );
} );
