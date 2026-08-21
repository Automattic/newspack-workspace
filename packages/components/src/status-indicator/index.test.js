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

	// A Status column offers its statuses as separate filters, so two of them
	// rendering the same glyph would make two different states read alike.
	it( 'draws a different glyph per status', () => {
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
