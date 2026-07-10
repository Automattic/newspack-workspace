/**
 * Tests for the Funnel viz: the pure helpers (clamped drop-off) and render smoke
 * tests covering the hover/focus-revealed label Popovers, the stepped section
 * widths and trapezoidal separators, the primary-scale fill/separator colors, and
 * the edge cases.
 */

/**
 * External dependencies
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Funnel, { dropFromPrevious, type FunnelStage } from './Funnel';

describe( 'Funnel helpers', () => {
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

	it( 'keeps descriptors available to assistive tech and reveals the visual Popover on hover', () => {
		const { container } = render( <Funnel stages={ stages( [ 'Impressions', 25000 ], [ 'Engaged', 2000 ] ) } /> );
		const funnel = container.querySelector( '.newspack-insights__funnel' ) as HTMLElement;
		// The drop-off text is always in the DOM as a screen-reader copy, even unhovered.
		const srText = container.querySelector( '.screen-reader-text' );
		expect( srText?.textContent ).toMatch( /of Impressions/ );
		expect( srText?.textContent ).toMatch( /drop-off/ );
		// The floating visual Popover (portaled to body) appears only on hover…
		expect( document.querySelector( '.newspack-insights__funnel-labels' ) ).toBeNull();
		fireEvent.mouseEnter( funnel );
		expect( document.querySelector( '.newspack-insights__funnel-labels' ) ).not.toBeNull();
		// …and hides again once the pointer leaves.
		fireEvent.mouseLeave( funnel );
		expect( document.querySelector( '.newspack-insights__funnel-labels' ) ).toBeNull();
	} );

	it( 'reveals the visual Popover on focus and hides it on blur', () => {
		const { container } = render( <Funnel stages={ stages( [ 'Impressions', 25000 ], [ 'Engaged', 2000 ] ) } /> );
		const funnel = container.querySelector( '.newspack-insights__funnel' ) as HTMLElement;
		fireEvent.focus( funnel );
		expect( document.querySelector( '.newspack-insights__funnel-labels' ) ).not.toBeNull();
		fireEvent.blur( funnel );
		expect( document.querySelector( '.newspack-insights__funnel-labels' ) ).toBeNull();
	} );

	it( "reveals every section's Popover at once on hover", () => {
		const { container } = render( <Funnel stages={ stages( [ 'A', 1000 ], [ 'B', 500 ], [ 'C', 100 ] ) } /> );
		// Both post-first stages carry an always-present screen-reader descriptor…
		expect( container.querySelectorAll( '.screen-reader-text' ) ).toHaveLength( 2 );
		// …and reveal their visual Popovers together on a single hover.
		fireEvent.mouseEnter( container.querySelector( '.newspack-insights__funnel' ) as HTMLElement );
		expect( document.querySelectorAll( '.newspack-insights__funnel-labels' ) ).toHaveLength( 2 );
	} );

	it( 'renders a single-stage funnel without descriptors even on hover', () => {
		const { container } = render( <Funnel stages={ stages( [ 'Only', 100 ] ) } /> );
		expect( screen.getByText( 'Only' ) ).toBeInTheDocument();
		expect( container.querySelector( '.screen-reader-text' ) ).toBeNull();
		fireEvent.mouseEnter( container.querySelector( '.newspack-insights__funnel' ) as HTMLElement );
		expect( document.querySelector( '.newspack-insights__funnel-labels' ) ).toBeNull();
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
		// One separator per adjacent pair (n − 1). The trapezoid clip-path lives in
		// CSS; the per-band bottom inset is supplied inline as a percentage.
		const separators = container.querySelectorAll< HTMLElement >( '.newspack-insights__funnel-separator' );
		expect( separators ).toHaveLength( 2 );
		separators.forEach( separator => {
			expect( separator.style.getPropertyValue( '--separator-inset' ) ).toMatch( /%$/ );
		} );
	} );

	it( 'sets a primary-scale fill on every section and a separator color on every connector', () => {
		// These drive the SCSS `background-color`; assert they are emitted so a
		// rename drift between the component and the stylesheet is caught here.
		const { container } = render( <Funnel stages={ stages( [ 'A', 1000 ], [ 'B', 500 ], [ 'C', 100 ] ) } /> );
		const steps = container.querySelectorAll< HTMLElement >( '.newspack-insights__funnel-step' );
		expect( steps ).toHaveLength( 3 );
		steps.forEach( step => {
			expect( step.style.getPropertyValue( '--band-fill' ) ).not.toBe( '' );
		} );
		const separators = container.querySelectorAll< HTMLElement >( '.newspack-insights__funnel-separator' );
		expect( separators ).toHaveLength( 2 );
		separators.forEach( separator => {
			expect( separator.style.getPropertyValue( '--band-separator' ) ).not.toBe( '' );
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

	it( 'never widens a band beyond the one above, even for long funnels or data drift', () => {
		// A 10-stage funnel (MINIMUM_BAND_PROPORTION * 10 > 1, so the floor would
		// otherwise flare) that also contains a drift stage (S2 > S1). Widths must
		// still stay ≤ 100% and strictly decrease band to band.
		const { container } = render(
			<Funnel
				stages={ stages(
					[ 'S0', 1000 ],
					[ 'S1', 900 ],
					[ 'S2', 950 ],
					[ 'S3', 300 ],
					[ 'S4', 250 ],
					[ 'S5', 200 ],
					[ 'S6', 150 ],
					[ 'S7', 120 ],
					[ 'S8', 100 ],
					[ 'S9', 90 ]
				) }
			/>
		);
		const widths = Array.from( container.querySelectorAll< HTMLElement >( '.newspack-insights__funnel-step' ) ).map( step =>
			parseFloat( step.style.maxWidth )
		);
		expect( widths[ 0 ] ).toBe( 100 );
		widths.forEach( width => expect( width ).toBeLessThanOrEqual( 100 ) );
		for ( let i = 1; i < widths.length; i++ ) {
			expect( widths[ i ] ).toBeLessThan( widths[ i - 1 ] );
		}
	} );

	it( 'keeps text full size above the width threshold, then eases it down gradually', () => {
		const { container } = render( <Funnel stages={ stages( [ 'A', 1000 ], [ 'B', 700 ], [ 'C', 450 ], [ 'D', 100 ] ) } /> );
		const steps = container.querySelectorAll< HTMLElement >( '.newspack-insights__funnel-step' );
		const scale = ( step: HTMLElement ) => parseFloat( step.style.getPropertyValue( '--funnel-font-scale' ) );
		// At or above half the top width, text stays full size.
		expect( scale( steps[ 0 ] ) ).toBe( 1 );
		expect( scale( steps[ 1 ] ) ).toBe( 1 );
		// Just past the threshold the reduction is slight — a ramp, not a hard step to
		// the floor — and it keeps easing down as sections get narrower.
		expect( scale( steps[ 2 ] ) ).toBeLessThan( 1 );
		expect( scale( steps[ 2 ] ) ).toBeGreaterThan( 0.9 );
		expect( scale( steps[ 3 ] ) ).toBeLessThan( scale( steps[ 2 ] ) );
	} );
} );
