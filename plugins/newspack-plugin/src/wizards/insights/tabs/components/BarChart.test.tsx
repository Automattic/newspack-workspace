/**
 * Tests for the shared BarChart viz: render smoke tests covering bars with
 * labels, the formatValue callback, and the empty state.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import BarChart, { type Bar } from './BarChart';

const bar = ( label: string, value: number ): Bar => ( { label, value } );

describe( 'BarChart render', () => {
	it( 'renders bars and their labels', () => {
		render( <BarChart bars={ [ bar( 'Mon', 100 ), bar( 'Tue', 200 ), bar( 'Wed', 150 ) ] } /> );
		expect( screen.getByRole( 'img', { name: 'Bar chart' } ) ).toBeInTheDocument();
		// Each label appears in both the bar-label div and the tooltip span — use getAllByText.
		expect( screen.getAllByText( 'Mon' ).length ).toBeGreaterThan( 0 );
		expect( screen.getAllByText( 'Tue' ).length ).toBeGreaterThan( 0 );
		expect( screen.getAllByText( 'Wed' ).length ).toBeGreaterThan( 0 );
	} );

	it( 'applies a passed formatValue to bar tooltip values', () => {
		const formatSecs = ( v: number ) => `${ v }s`;
		render( <BarChart bars={ [ bar( 'Desktop', 120 ), bar( 'Mobile', 85 ) ] } formatValue={ formatSecs } /> );
		// Tooltip value spans carry the formatted text.
		expect( screen.getByText( '120s' ) ).toBeInTheDocument();
		expect( screen.getByText( '85s' ) ).toBeInTheDocument();
	} );

	it( 'renders the default empty state when the bars array is empty', () => {
		render( <BarChart bars={ [] } /> );
		expect( screen.getByText( 'No data in this timeframe.' ) ).toBeInTheDocument();
	} );
} );
