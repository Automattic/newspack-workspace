/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import EmptyState from '.';

describe( 'EmptyState.Root', () => {
	it( 'renders the four-column grid spine', () => {
		const { container } = render( <EmptyState.Root>body</EmptyState.Root> );
		const grid = container.querySelector( '.newspack-empty-state' );
		expect( grid ).toHaveClass( 'newspack-grid' );
		expect( grid ).toHaveClass( 'newspack-grid__columns-4' );
		expect( grid ).toHaveClass( 'newspack-grid--no-margin' );
	} );

	// grid/style.scss matches on these as plain attributes, so they are a contract.
	it( 'gives the inner stack the start and end attributes the Grid stylesheet matches on', () => {
		const { container } = render( <EmptyState.Root>body</EmptyState.Root> );
		const stack = container.querySelector( '.newspack-empty-state' ).firstElementChild;
		expect( stack ).toHaveAttribute( 'start', '2' );
		expect( stack ).toHaveAttribute( 'end', '4' );
	} );

	// admin-shell/style.scss:80-91 hides the shell header and constrains the main
	// region via :has( .newspack-newsletters-admin__empty-state ). Losing the class
	// restores both, silently.
	it( 'merges className onto the grid', () => {
		const { container } = render( <EmptyState.Root className="newspack-newsletters-admin__empty-state">body</EmptyState.Root> );
		expect( container.querySelector( '.newspack-empty-state' ) ).toHaveClass( 'newspack-newsletters-admin__empty-state' );
	} );

	it( 'renders its children', () => {
		render( <EmptyState.Root>body</EmptyState.Root> );
		expect( screen.getByText( 'body' ) ).toBeInTheDocument();
	} );
} );
