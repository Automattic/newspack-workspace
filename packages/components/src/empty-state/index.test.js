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

describe( 'EmptyState.Header', () => {
	it( 'renders a page-header section header with no margin', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" description="Compose and send." />
			</EmptyState.Root>
		);
		expect( container.querySelector( '.newspack-section-header--page-header' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-section-header--no-margin' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Compose and send.' ) ).toBeInTheDocument();
	} );

	it( 'renders an h2 at the default size', () => {
		render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" />
			</EmptyState.Root>
		);
		expect( screen.getByRole( 'heading', { level: 2, name: 'Get started with newsletters' } ) ).toBeInTheDocument();
	} );

	it( 'drops to an h3 and the small section header when the root is small', () => {
		const { container } = render(
			<EmptyState.Root size="small">
				<EmptyState.Header title="No products match this rule" />
			</EmptyState.Root>
		);
		expect( container.querySelector( '.newspack-section-header--small' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { level: 3, name: 'No products match this rule' } ) ).toBeInTheDocument();
	} );

	it( 'lets heading override the level the size implies', () => {
		render(
			<EmptyState.Root size="small">
				<EmptyState.Header title="No products match this rule" heading={ 1 } />
			</EmptyState.Root>
		);
		expect( screen.getByRole( 'heading', { level: 1, name: 'No products match this rule' } ) ).toBeInTheDocument();
	} );

	it( 'throws outside Root', () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		expect( () => render( <EmptyState.Header title="Orphan" /> ) ).toThrow(
			'EmptyState subcomponents must be rendered inside EmptyState.Root.'
		);
		consoleError.mockRestore();
	} );
} );
