/**
 * Tests for the Funnel viz: the pure layout/geometry helpers (mode selection,
 * opacity interpolation, drop-off, highlight threshold) and a few render smoke
 * tests covering deltas, the high-drop highlight, and the edge cases.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Funnel, { DROP_HIGHLIGHT_THRESHOLD, isCompactMode, stepOpacity, dropFromPrevious, isHighDrop } from './Funnel';

const stage = ( label: string, count: number, pctOfTop: number ) => ( { label, count, pct_of_top: pctOfTop } );

describe( 'Funnel helpers', () => {
	describe( 'isCompactMode', () => {
		it( 'is side-label (false) for a small step count on a wide container', () => {
			expect( isCompactMode( 3, 800 ) ).toBe( false );
			expect( isCompactMode( 4, 480 ) ).toBe( false );
		} );
		it( 'is compact when there are 5+ steps regardless of width', () => {
			expect( isCompactMode( 5, 1200 ) ).toBe( true );
		} );
		it( 'is compact when the container is narrower than 480px', () => {
			expect( isCompactMode( 3, 479 ) ).toBe( true );
		} );
	} );

	describe( 'stepOpacity', () => {
		it( 'runs 1.0 at the first step to 0.6 at the last', () => {
			expect( stepOpacity( 0, 3 ) ).toBeCloseTo( 1.0, 5 );
			expect( stepOpacity( 1, 3 ) ).toBeCloseTo( 0.8, 5 );
			expect( stepOpacity( 2, 3 ) ).toBeCloseTo( 0.6, 5 );
		} );
		it( 'is full opacity for a single step', () => {
			expect( stepOpacity( 0, 1 ) ).toBe( 1 );
		} );
	} );

	describe( 'dropFromPrevious', () => {
		it( 'computes 1 - count/prev', () => {
			expect( dropFromPrevious( 2028, 26171 ) ).toBeCloseTo( 0.9225, 3 ); // Richland Engagement step.
			expect( dropFromPrevious( 431, 2028 ) ).toBeCloseTo( 0.7875, 3 ); // Richland Conversion step.
		} );
		it( 'is 0 for equal counts and 1 for a zero step', () => {
			expect( dropFromPrevious( 500, 500 ) ).toBe( 0 );
			expect( dropFromPrevious( 0, 500 ) ).toBe( 1 );
		} );
		it( 'is 0 when the previous step is 0 (avoids divide-by-zero)', () => {
			expect( dropFromPrevious( 0, 0 ) ).toBe( 0 );
		} );
	} );

	describe( 'isHighDrop', () => {
		it( `flags drops strictly greater than ${ DROP_HIGHLIGHT_THRESHOLD }`, () => {
			expect( isHighDrop( 0.21 ) ).toBe( true );
			expect( isHighDrop( 0.2 ) ).toBe( false );
			expect( isHighDrop( 0.05 ) ).toBe( false );
		} );
	} );
} );

describe( 'Funnel render', () => {
	it( 'renders step labels, counts and both deltas (high drops highlighted)', () => {
		const { container } = render(
			<Funnel stages={ [ stage( 'Impression', 1000, 1 ), stage( 'Engagement', 200, 0.2 ), stage( 'Conversion', 50, 0.05 ) ] } />
		);
		expect( screen.getByText( 'Impression' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Engagement' ) ).toBeInTheDocument();
		// Deltas: 200/1000 → 20% of top, 80% drop; 50/200 → 75% drop. Both > 20%.
		expect( screen.getByText( '20% of top' ) ).toBeInTheDocument();
		expect( screen.getByText( '80% from previous' ) ).toBeInTheDocument();
		expect( screen.getByText( '75% from previous' ) ).toBeInTheDocument();
		expect( container.querySelectorAll( '.newspack-insights__funnel-delta--prev.is-high-drop' ) ).toHaveLength( 2 );
	} );

	it( 'renders the empty state when the first step is 0', () => {
		render( <Funnel stages={ [ stage( 'Impression', 0, 0 ), stage( 'Conversion', 0, 0 ) ] } /> );
		expect( screen.getByText( 'Not enough data to chart the funnel.' ) ).toBeInTheDocument();
	} );

	it( 'renders a single stage with no deltas', () => {
		render( <Funnel stages={ [ stage( 'Impression', 1000, 1 ) ] } /> );
		expect( screen.getByText( 'Impression' ) ).toBeInTheDocument();
		expect( screen.queryByText( /from previous/ ) ).not.toBeInTheDocument();
	} );
} );
