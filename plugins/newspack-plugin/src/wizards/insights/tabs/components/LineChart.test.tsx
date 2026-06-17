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
} );
