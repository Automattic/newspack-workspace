/**
 * Tests for the shared LineChart viz: render smoke tests covering single-series,
 * multi-series with unequal lengths, referenceLine, yMax, and empty state with a
 * custom emptyMessage.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import LineChart, { type LinePoint, type LineSeries } from './LineChart';

const pt = ( label: string, value: number ): LinePoint => ( { label, value } );

describe( 'LineChart render', () => {
	it( 'renders a single-series chart and shows the first/last label in the meta row', () => {
		render( <LineChart points={ [ pt( 'Jan', 10 ), pt( 'Feb', 20 ), pt( 'Mar', 15 ) ] } /> );
		// Single-series shows the meta row (first + last label).
		expect( screen.getByText( 'Jan' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Mar' ) ).toBeInTheDocument();
	} );

	it( 'renders multi-series with unequal lengths without crashing (base = longest)', () => {
		const series: LineSeries[] = [
			{ name: 'Short', points: [ pt( 'A', 1 ), pt( 'B', 2 ) ] },
			{ name: 'Long', points: [ pt( 'A', 3 ), pt( 'B', 4 ), pt( 'C', 5 ) ] },
		];
		// Should render without throwing; the longest series drives the x-axis.
		render( <LineChart series={ series } /> );
		// Legend items for each series should appear.
		expect( screen.getByText( 'Short' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Long' ) ).toBeInTheDocument();
	} );

	it( 'renders with a referenceLine without crashing', () => {
		render( <LineChart points={ [ pt( 'Jan', 5 ), pt( 'Feb', 8 ), pt( 'Mar', 12 ) ] } referenceLine={ { value: 10, label: 'Target: 10' } } /> );
		expect( screen.getByText( 'Target: 10' ) ).toBeInTheDocument();
	} );

	it( 'renders with a yMax prop without crashing', () => {
		render( <LineChart points={ [ pt( 'Jan', 5 ), pt( 'Feb', 8 ) ] } yMax={ 100 } /> );
		// Just verify it renders; yMax is a rendering hint (no visible text to assert).
		expect( screen.getByRole( 'img', { name: 'Time-series chart' } ) ).toBeInTheDocument();
	} );

	it( 'renders a custom emptyMessage when every series is empty', () => {
		// Sample prop exercising the generic emptyMessage passthrough — NOT corpus
		// copy, so it is intentionally not part of the "window" → "timeframe"
		// normalization (NPPD-1698). Don't "sync" it.
		render(
			<LineChart
				series={ [
					{ name: 'A', points: [] },
					{ name: 'B', points: [] },
				] }
				emptyMessage="No conversions in this window."
			/>
		);
		expect( screen.getByText( 'No conversions in this window.' ) ).toBeInTheDocument();
	} );

	it( 'renders the default empty message when no data and no emptyMessage prop', () => {
		render( <LineChart points={ [] } /> );
		expect( screen.getByText( 'No data in this timeframe.' ) ).toBeInTheDocument();
	} );

	it( 'renders x- and y-axis titles when provided', () => {
		render( <LineChart points={ [ pt( '0', 0.1 ), pt( '30', 0.5 ) ] } xAxisLabel="Days" yAxisLabel="Percent registered" /> );
		expect( screen.getByText( 'Days' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Percent registered' ) ).toBeInTheDocument();
	} );

	it( 'formats the single-series peak readout with formatValue', () => {
		// A cumulative share of 1 must read as "100%", not a bare "1".
		const asPercent = ( v: number ) => `${ Math.round( v * 100 ) }%`;
		render( <LineChart points={ [ pt( '0', 0.25 ), pt( '30', 1 ) ] } formatValue={ asPercent } /> );
		expect( screen.getByText( 'peak: 100%' ) ).toBeInTheDocument();
	} );

	it( 'humanizes x-axis point labels through formatLabel in the meta row', () => {
		render( <LineChart points={ [ pt( '0', 0.1 ), pt( '90', 0.9 ) ] } formatLabel={ d => `Day ${ d }` } /> );
		expect( screen.getByText( 'Day 0' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Day 90' ) ).toBeInTheDocument();
	} );
} );
