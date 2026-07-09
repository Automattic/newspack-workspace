/**
 * Tests for the Funnel viz: the pure helpers (opacity interpolation, clamped
 * drop-off) and render smoke tests covering the hover/focus-revealed label
 * Popovers, the stepped section widths and trapezoidal separators, and the edge
 * cases.
 */

/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Funnel, { stepOpacity, dropFromPrevious, type FunnelStage } from './Funnel';

describe( 'Funnel helpers', () => {
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
			expect( dropFromPrevious( 2000, 25000 ) ).toBeCloseTo( 0.92, 3 ); // Mid-size publisher engagement step.
			expect( dropFromPrevious( 400, 2000 ) ).toBeCloseTo( 0.8, 3 ); // Mid-size publisher conversion step.
		} );
		it( 'is 0 for equal counts and 1 for a zero step', () => {
			expect( dropFromPrevious( 500, 500 ) ).toBe( 0 );
			expect( dropFromPrevious( 0, 500 ) ).toBe( 1 );
		} );
		it( 'clamps to 0 when a later step exceeds the previous (no negative drop)', () => {
			expect( dropFromPrevious( 600, 400 ) ).toBe( 0 );
		} );
		it( 'is 0 when the previous step is 0 (avoids divide-by-zero)', () => {
			expect( dropFromPrevious( 0, 0 ) ).toBe( 0 );
		} );
	} );
} );

describe( 'Funnel render', () => {
	const stages = ( ...defs: Array< [ string, number ] > ): FunnelStage[] => {
		const top = defs[ 0 ]?.[ 1 ] ?? 0;
		return defs.map( ( [ label, count ] ) => ( { label, count, pct_of_top: top > 0 ? count / top : 0 } ) );
	};

	it( 'shows the label and count inside every stage', () => {
		render( <Funnel stages={ stages( [ 'Impressions', 25000 ], [ 'Engaged', 2000 ], [ 'Converted', 400 ] ) } /> );
		expect( screen.getByText( 'Impressions' ) ).toBeInTheDocument();
		expect( screen.getByText( '25,000' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Converted' ) ).toBeInTheDocument();
		expect( screen.getByText( '400' ) ).toBeInTheDocument();
	} );

	it( 'reveals drop-off descriptors only while the funnel is hovered', () => {
		const { container } = render( <Funnel stages={ stages( [ 'Impressions', 25000 ], [ 'Engaged', 2000 ] ) } /> );
		const funnel = container.querySelector( '.newspack-insights__funnel' ) as HTMLElement;
		// Hidden until the funnel is hovered.
		expect( screen.queryByText( /of Impressions/ ) ).toBeNull();
		fireEvent.mouseEnter( funnel );
		expect( screen.getByText( /of Impressions/ ) ).toBeInTheDocument();
		// Exactly one drop-off line: the first stage has none.
		expect( screen.getAllByText( /drop-off/ ) ).toHaveLength( 1 );
		// Hidden again once the pointer leaves.
		fireEvent.mouseLeave( funnel );
		expect( screen.queryByText( /of Impressions/ ) ).toBeNull();
	} );

	it( 'reveals descriptors on focus and hides them on blur', () => {
		const { container } = render( <Funnel stages={ stages( [ 'Impressions', 25000 ], [ 'Engaged', 2000 ] ) } /> );
		const funnel = container.querySelector( '.newspack-insights__funnel' ) as HTMLElement;
		fireEvent.focus( funnel );
		expect( screen.getByText( /drop-off/ ) ).toBeInTheDocument();
		fireEvent.blur( funnel );
		expect( screen.queryByText( /drop-off/ ) ).toBeNull();
	} );

	it( "reveals every section's descriptors at once on hover", () => {
		const { container } = render( <Funnel stages={ stages( [ 'A', 1000 ], [ 'B', 500 ], [ 'C', 100 ] ) } /> );
		fireEvent.mouseEnter( container.querySelector( '.newspack-insights__funnel' ) as HTMLElement );
		// Both post-first stages reveal their drop-off line together.
		expect( screen.getAllByText( /drop-off/ ) ).toHaveLength( 2 );
	} );

	it( 'renders a single-stage funnel without descriptors even on hover', () => {
		const { container } = render( <Funnel stages={ stages( [ 'Only', 100 ] ) } /> );
		expect( screen.getByText( 'Only' ) ).toBeInTheDocument();
		fireEvent.mouseEnter( container.querySelector( '.newspack-insights__funnel' ) as HTMLElement );
		expect( screen.queryByText( /drop-off/ ) ).toBeNull();
	} );

	it( 'shows the empty message with no stages', () => {
		render( <Funnel stages={ [] } /> );
		expect( screen.getByText( 'Not enough data to chart the funnel.' ) ).toBeInTheDocument();
	} );

	it( 'shows the empty message when the top count is zero', () => {
		render( <Funnel stages={ stages( [ 'Impressions', 0 ], [ 'Engaged', 0 ] ) } /> );
		expect( screen.getByText( 'Not enough data to chart the funnel.' ) ).toBeInTheDocument();
	} );

	it( 'renders no separate legend or SVG chart', () => {
		const { container } = render( <Funnel stages={ stages( [ 'A', 100 ], [ 'B', 50 ] ) } /> );
		expect( container.querySelector( '.newspack-insights__funnel-legend' ) ).toBeNull();
		expect( container.querySelector( '.newspack-insights__funnel-svg' ) ).toBeNull();
	} );

	it( 'renders rectangular section fills and a trapezoidal separator between each pair', () => {
		const { container } = render( <Funnel stages={ stages( [ 'A', 1000 ], [ 'B', 100 ], [ 'C', 5 ] ) } /> );
		// The sections themselves are rectangles — no clip-path on the fills.
		const fills = container.querySelectorAll< HTMLElement >( '.newspack-insights__funnel-fill' );
		expect( fills ).toHaveLength( 3 );
		fills.forEach( fill => {
			expect( fill.style.clipPath ).toBe( '' );
		} );
		// One separator per adjacent pair (n − 1), each clipped to a trapezoid.
		const separators = container.querySelectorAll< HTMLElement >( '.newspack-insights__funnel-separator' );
		expect( separators ).toHaveLength( 2 );
		separators.forEach( separator => {
			expect( separator.style.clipPath ).toMatch( /^polygon\(/ );
		} );
	} );

	it( 'steps section widths down from a full-width top section', () => {
		const { container } = render( <Funnel stages={ stages( [ 'A', 1000 ], [ 'B', 500 ], [ 'C', 100 ] ) } /> );
		const widths = Array.from( container.querySelectorAll< HTMLElement >( '.newspack-insights__funnel-step' ) ).map( step =>
			parseFloat( step.style.maxWidth )
		);
		expect( widths[ 0 ] ).toBe( 100 ); // top section pinned to the full width
		expect( widths[ 1 ] ).toBeLessThan( widths[ 0 ] );
		expect( widths[ 2 ] ).toBeLessThan( widths[ 1 ] );
	} );
} );
