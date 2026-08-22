/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { drafts, published } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import StatusIndicator from '.';

describe( 'StatusIndicator', () => {
	it( 'renders the glyph alongside the label', () => {
		const { container } = render( <StatusIndicator icon={ published }>Active</StatusIndicator> );
		expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
		expect( container.querySelector( 'svg.newspack-status-indicator__icon' ) ).toBeInTheDocument();
	} );

	// The trim is what makes the 8px gap measure 8px, so it is styled through a
	// class rather than left to the consumer.
	it( 'carries the class the icon trim is scoped to', () => {
		const { container } = render( <StatusIndicator icon={ published }>Active</StatusIndicator> );
		expect( container.querySelector( '.newspack-status-indicator' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-status-indicator__icon' ) ).toBeInTheDocument();
	} );

	// The rule that no two statuses in one column share a glyph is pinned in the
	// status-map suites, where a collision could actually appear. All this checks
	// is that the prop reaches the SVG, so a map holding distinct glyphs draws them.
	it( 'draws the glyph it is given rather than a fixed one', () => {
		const { container: active } = render( <StatusIndicator icon={ published }>Active</StatusIndicator> );
		const { container: inactive } = render( <StatusIndicator icon={ drafts }>Inactive</StatusIndicator> );
		expect( active.querySelector( 'svg' ).innerHTML ).not.toBe( inactive.querySelector( 'svg' ).innerHTML );
	} );

	it( 'keeps the consumer class and passes the rest through', () => {
		const { container } = render(
			<StatusIndicator className="custom" data-testid="status" icon={ published }>
				Active
			</StatusIndicator>
		);
		const root = container.querySelector( '.newspack-status-indicator' );
		expect( root ).toHaveClass( 'custom' );
		expect( root ).toHaveAttribute( 'data-testid', 'status' );
	} );
} );
