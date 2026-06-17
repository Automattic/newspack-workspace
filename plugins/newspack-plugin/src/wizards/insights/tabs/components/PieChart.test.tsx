/**
 * Tests for the shared PieChart viz: render smoke tests covering segments with
 * legend (label/value/percent), centerLabel, and empty state with a custom
 * emptyMessage.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PieChart, { type PieSegment } from './PieChart';

const seg = ( label: string, value: number ): PieSegment => ( { label, value } );

describe( 'PieChart render', () => {
	it( 'renders segments and shows label, value and percent in the legend', () => {
		render( <PieChart segments={ [ seg( 'Gate', 60 ), seg( 'Prompt', 40 ) ] } /> );
		// Both legend labels appear.
		expect( screen.getByText( 'Gate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Prompt' ) ).toBeInTheDocument();
		// The SVG donut role.
		expect( screen.getByRole( 'img', { name: 'Breakdown chart' } ) ).toBeInTheDocument();
		// Values appear somewhere in the rendered output.
		expect( screen.getByText( /60/ ) ).toBeInTheDocument();
		expect( screen.getByText( /40/ ) ).toBeInTheDocument();
		// Percents appear (60% and 40%).
		expect( screen.getByText( /60%/ ) ).toBeInTheDocument();
		expect( screen.getByText( /40%/ ) ).toBeInTheDocument();
	} );

	it( 'renders a centerLabel text element when provided', () => {
		render( <PieChart segments={ [ seg( 'Gate', 75 ), seg( 'Direct', 25 ) ] } centerLabel="100" /> );
		// The center label SVG text renders.
		expect( screen.getByText( '100' ) ).toBeInTheDocument();
	} );

	it( 'does not render a center text when centerLabel is omitted', () => {
		const { container } = render( <PieChart segments={ [ seg( 'A', 50 ), seg( 'B', 50 ) ] } /> );
		expect( container.querySelector( '.newspack-insights__pie-center' ) ).toBeNull();
	} );

	it( 'renders a custom emptyMessage when all segment values are zero', () => {
		render( <PieChart segments={ [ seg( 'Gate', 0 ), seg( 'Prompt', 0 ) ] } emptyMessage="Source data will appear once registrations occur." /> );
		expect( screen.getByText( 'Source data will appear once registrations occur.' ) ).toBeInTheDocument();
	} );

	it( 'renders the default empty message when segments sum to zero and no emptyMessage prop', () => {
		render( <PieChart segments={ [] } /> );
		expect( screen.getByText( 'No data in this timeframe.' ) ).toBeInTheDocument();
	} );
} );
