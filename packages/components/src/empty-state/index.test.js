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

	// grid/style.scss matches on these attributes, so they are a contract.
	it( 'gives the inner stack the data attributes the Grid stylesheet matches on', () => {
		const { container } = render(
			<EmptyState.Root>
				<p>body</p>
			</EmptyState.Root>
		);
		const stack = container.querySelector( '.newspack-empty-state' ).firstElementChild;
		expect( stack ).toHaveAttribute( 'data-start', '2' );
		expect( stack ).toHaveAttribute( 'data-end', '4' );
	} );

	// The width cap hangs off this class, so losing it silently widens the block.
	it( 'carries the stack class hook', () => {
		const { container } = render(
			<EmptyState.Root>
				<p>body</p>
			</EmptyState.Root>
		);
		const stack = container.querySelector( '.newspack-empty-state' ).firstElementChild;
		expect( stack ).toHaveClass( 'newspack-empty-state__stack' );
		expect( stack ).toHaveStyle( { gap: 'var(--wpds-dimension-gap-2xl, 32px)' } );
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
	it( 'renders the title and description in the header', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" description="Compose and send." />
			</EmptyState.Root>
		);
		expect( container.querySelector( '.newspack-empty-state__title' ) ).toHaveTextContent( 'Get started with newsletters' );
		expect( container.querySelector( '.newspack-empty-state__description' ) ).toHaveTextContent( 'Compose and send.' );
	} );

	// Spacing comes from the stacks, so a margin creeping back onto the heading or
	// paragraph would silently widen every gap.
	it( 'spaces the header from its stacks rather than from margins', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" description="Compose and send." />
			</EmptyState.Root>
		);
		const header = container.querySelector( '.newspack-empty-state__header' );
		expect( header ).toHaveStyle( { flexDirection: 'column', alignItems: 'center', gap: 'var(--wpds-dimension-gap-sm, 8px)' } );
		expect( header.firstElementChild ).toHaveStyle( {
			flexDirection: 'column',
			alignItems: 'center',
			gap: 'var(--wpds-dimension-gap-lg, 16px)',
		} );
	} );

	it( 'wraps the icon in the disc', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" icon={ <svg /> } />
			</EmptyState.Root>
		);
		expect( container.querySelector( '.newspack-empty-state__icon svg' ) ).toBeInTheDocument();
	} );

	it( 'omits the description and the icon when they are not given', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" />
			</EmptyState.Root>
		);
		expect( container.querySelector( '.newspack-empty-state__description' ) ).not.toBeInTheDocument();
		expect( container.querySelector( '.newspack-empty-state__icon' ) ).not.toBeInTheDocument();
	} );

	it( 'renders an h2 and a 48px icon at the default size', () => {
		const { container } = render(
			<EmptyState.Root>
				<EmptyState.Header title="Get started with newsletters" icon={ <svg /> } />
			</EmptyState.Root>
		);
		expect( screen.getByRole( 'heading', { level: 2, name: 'Get started with newsletters' } ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-empty-state__header--small' ) ).not.toBeInTheDocument();
		expect( container.querySelector( '.newspack-empty-state__icon svg' ) ).toHaveAttribute( 'width', '48' );
	} );

	it( 'drops to an h3, the small modifier and a 24px icon when the root is small', () => {
		const { container } = render(
			<EmptyState.Root size="small">
				<EmptyState.Header title="No products match this rule" icon={ <svg /> } />
			</EmptyState.Root>
		);
		expect( container.querySelector( '.newspack-empty-state__header--small' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { level: 3, name: 'No products match this rule' } ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-empty-state__icon svg' ) ).toHaveAttribute( 'width', '24' );
		expect( container.querySelector( '.newspack-empty-state__header' ).firstElementChild ).toHaveStyle( {
			gap: 'var(--wpds-dimension-gap-md, 12px)',
		} );
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

	it( 'throws outside Root in development', () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		try {
			expect( () => render( <EmptyState.Header title="Orphan" /> ) ).toThrow(
				'EmptyState subcomponents must be rendered inside EmptyState.Root.'
			);
		} finally {
			consoleError.mockRestore();
		}
	} );

	// Header is the visible half, so a misplaced one blanking an admin screen costs more
	// than the default size it falls back to.
	it( 'falls back to the default size outside Root in production', () => {
		const previous = process.env.NODE_ENV;
		process.env.NODE_ENV = 'production';
		try {
			const { container } = render( <EmptyState.Header title="Orphan" icon={ <svg /> } /> );
			expect( screen.getByRole( 'heading', { level: 2, name: 'Orphan' } ) ).toBeInTheDocument();
			expect( container.querySelector( '.newspack-empty-state__header--small' ) ).not.toBeInTheDocument();
			expect( container.querySelector( '.newspack-empty-state__icon svg' ) ).toHaveAttribute( 'width', '48' );
		} finally {
			process.env.NODE_ENV = previous;
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
		expect( container.querySelector( '.newspack-empty-state__actions' ) ).toHaveStyle( {
			justifyContent: 'center',
			gap: 'var(--wpds-dimension-gap-sm, 8px)',
		} );
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
		// A stack's own default is `stretch`, so without align="center" the buttons
		// would go full-bleed while flexDirection stayed correct.
		expect( actions ).toHaveStyle( { alignItems: 'center' } );
	} );

	it( 'throws outside Root in development', () => {
		const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		try {
			expect( () => render( <EmptyState.Actions>x</EmptyState.Actions> ) ).toThrow(
				'EmptyState subcomponents must be rendered inside EmptyState.Root.'
			);
		} finally {
			consoleError.mockRestore();
		}
	} );

	// Actions reads nothing from context, so the invariant is a development aid. A
	// stray one must not blank an admin screen in production over a layout hint.
	it( 'renders outside Root in production', () => {
		const previous = process.env.NODE_ENV;
		process.env.NODE_ENV = 'production';
		try {
			const { container } = render( <EmptyState.Actions>x</EmptyState.Actions> );
			expect( container.querySelector( '.newspack-empty-state__actions' ) ).toBeInTheDocument();
		} finally {
			process.env.NODE_ENV = previous;
		}
	} );
} );
