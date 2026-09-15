/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { drafts, gift, published } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import StatusIndicator, { statusGlyph, STATUS_NAMES } from '.';

describe( 'StatusIndicator', () => {
	it( 'renders the glyph alongside the label', () => {
		const { container } = render( <StatusIndicator status="active">Active</StatusIndicator> );
		expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
		expect( container.querySelector( 'svg.newspack-status-indicator__icon' ) ).toBeInTheDocument();
	} );

	// The trim is what makes the 8px gap measure 8px, so it is styled through a
	// class rather than left to the consumer.
	it( 'carries the class the icon trim is scoped to', () => {
		const { container } = render( <StatusIndicator status="active">Active</StatusIndicator> );
		expect( container.querySelector( '.newspack-status-indicator' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-status-indicator__icon' ) ).toBeInTheDocument();
	} );

	it( 'draws a named status the way the vocabulary says', () => {
		const { container: named } = render( <StatusIndicator status="draft">Draft</StatusIndicator> );
		const { container: given } = render( <StatusIndicator icon={ drafts }>Draft</StatusIndicator> );
		expect( named.querySelector( 'svg' ).innerHTML ).toBe( given.querySelector( 'svg' ).innerHTML );
	} );

	it( 'draws the glyph it is given when there is no name for it', () => {
		const { container: free } = render( <StatusIndicator icon={ gift }>Free</StatusIndicator> );
		const { container: active } = render( <StatusIndicator icon={ published }>Active</StatusIndicator> );
		expect( free.querySelector( 'svg' ).innerHTML ).not.toBe( active.querySelector( 'svg' ).innerHTML );
	} );

	it( 'keeps the consumer class and passes the rest through', () => {
		const { container } = render(
			<StatusIndicator className="custom" data-testid="status" status="active">
				Active
			</StatusIndicator>
		);
		const root = container.querySelector( '.newspack-status-indicator' );
		expect( root ).toHaveClass( 'custom' );
		expect( root ).toHaveAttribute( 'data-testid', 'status' );
	} );
} );

describe( 'statusGlyph', () => {
	it( 'draws every name in the vocabulary', () => {
		STATUS_NAMES.forEach( name => expect( statusGlyph( name ) ).toBeTruthy() );
	} );

	// Pinning the complete list is what lets a column assert its own distinctness:
	// any pair not named here reads apart.
	it( 'shares a mark only where two names mean the same to a reader', () => {
		const byGlyph = new Map();
		STATUS_NAMES.forEach( name => byGlyph.set( statusGlyph( name ), [ ...( byGlyph.get( statusGlyph( name ) ) || [] ), name ] ) );
		expect( [ ...byGlyph.values() ].filter( names => names.length > 1 ) ).toEqual( [
			[ 'active', 'done' ],
			[ 'cancelled', 'ended' ],
		] );
	} );
} );
