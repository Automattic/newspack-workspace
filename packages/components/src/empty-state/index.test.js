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
		const { container } = render(
			<EmptyState.Root>
				<p>body</p>
			</EmptyState.Root>
		);
		const grid = container.querySelector( '.newspack-empty-state' );
		expect( grid ).toHaveClass( 'newspack-grid' );
		expect( grid ).toHaveClass( 'newspack-grid__columns-4' );
		expect( grid ).toHaveClass( 'newspack-grid--no-margin' );
	} );

	// grid/style.scss matches on these as plain attributes, so they are a contract.
	it( 'gives the inner stack the start and end attributes the Grid stylesheet matches on', () => {
		const { container } = render(
			<EmptyState.Root>
				<p>body</p>
			</EmptyState.Root>
		);
		const stack = container.querySelector( '.newspack-empty-state' ).firstElementChild;
		expect( stack ).toHaveAttribute( 'start', '2' );
		expect( stack ).toHaveAttribute( 'end', '4' );
	} );

	// Consumers key `:has()` selectors off this class, so losing it changes their
	// layout without failing anything.
	it( 'merges className onto the grid', () => {
		const { container } = render(
			<EmptyState.Root className="consumer-empty-state">
				<p>body</p>
			</EmptyState.Root>
		);
		expect( container.querySelector( '.newspack-empty-state' ) ).toHaveClass( 'consumer-empty-state' );
	} );

	// Elements, not a bare string: the stack keeps a lone string but drops one sitting
	// beside an element.
	it( 'renders its children', () => {
		render(
			<EmptyState.Root>
				<p>body</p>
			</EmptyState.Root>
		);
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
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" />
			</EmptyState.Root>
		);
		expect( screen.getByRole( 'heading', { level: 2, name: 'Get started with newsletters' } ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-section-header--small' ) ).not.toBeInTheDocument();
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

	it( 'carries its own class hook alongside any className', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" className="consumer-header" />
			</EmptyState.Root>
		);
		const header = container.querySelector( '.newspack-empty-state__header' );
		expect( header ).toBeInTheDocument();
		expect( header ).toHaveClass( 'consumer-header' );
	} );

	it( 'throws outside Root', () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		try {
			expect( () => render( <EmptyState.Header title="Orphan" /> ) ).toThrow(
				'EmptyState subcomponents must be rendered inside EmptyState.Root.'
			);
		} finally {
			consoleError.mockRestore();
		}
	} );
} );

describe( 'EmptyState.Actions', () => {
	it( 'renders its children in a centred row', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Actions>
					<button type="button">Add Newsletter</button>
				</EmptyState.Actions>
			</EmptyState.Root>
		);
		expect( container.querySelector( '.newspack-empty-state__actions' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-empty-state__actions' ) ).toHaveStyle( { justifyContent: 'center' } );
		expect( screen.getByRole( 'button', { name: 'Add Newsletter' } ) ).toBeInTheDocument();
	} );

	it( 'stacks into a column while keeping the hook class', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Actions orientation="column">
					<button type="button">Set up Audience Management</button>
					<a href="https://example.com">Learn more</a>
				</EmptyState.Actions>
			</EmptyState.Root>
		);
		const actions = container.querySelector( '.newspack-empty-state__actions' );
		expect( actions ).toBeInTheDocument();
		expect( actions ).toHaveStyle( { flexDirection: 'column' } );
		// VStack's own default is `stretch`, so without alignment="center" the buttons
		// would go full-bleed while flexDirection stayed correct.
		expect( actions ).toHaveStyle( { alignItems: 'center' } );
	} );

	it( 'throws outside Root', () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		try {
			expect( () => render( <EmptyState.Actions>x</EmptyState.Actions> ) ).toThrow(
				'EmptyState subcomponents must be rendered inside EmptyState.Root.'
			);
		} finally {
			consoleError.mockRestore();
		}
	} );
} );
