/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import StatCard, { NULL_GLYPH } from '.';

const renderOrphan = node => {
	const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	try {
		expect( () => render( node ) ).toThrow( 'StatCard subcomponents must be rendered inside StatCard.Root.' );
	} finally {
		consoleError.mockRestore();
	}
};

describe( 'StatCard.Root', () => {
	// style.scss puts container-type on this class, and the hero scale is a
	// container query against it, so losing the class silently resizes the figure.
	it( 'carries the class the container query is scoped to', () => {
		const { container } = render(
			<StatCard.Root>
				<p>body</p>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card' ) ).toBeInTheDocument();
		expect( container.querySelector( '.newspack-stat-card__content' ) ).toBeInTheDocument();
	} );

	it( 'merges className onto the card', () => {
		const { container } = render(
			<StatCard.Root className="consumer-tile">
				<p>body</p>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card' ) ).toHaveClass( 'consumer-tile' );
	} );

	it( 'renders its children', () => {
		render(
			<StatCard.Root>
				<p>body</p>
			</StatCard.Root>
		);
		expect( screen.getByText( 'body' ) ).toBeInTheDocument();
	} );
} );

describe( 'StatCard.Label', () => {
	it( 'renders an h3 by default', () => {
		render(
			<StatCard.Root>
				<StatCard.Label>Subscribers reached</StatCard.Label>
			</StatCard.Root>
		);
		expect( screen.getByRole( 'heading', { level: 3, name: 'Subscribers reached' } ) ).toBeInTheDocument();
	} );

	it( 'follows the level set on Root', () => {
		render(
			<StatCard.Root heading={ 4 }>
				<StatCard.Label>Subscribers reached</StatCard.Label>
			</StatCard.Root>
		);
		expect( screen.getByRole( 'heading', { level: 4, name: 'Subscribers reached' } ) ).toBeInTheDocument();
	} );

	it( 'lets its own heading override Root', () => {
		render(
			<StatCard.Root heading={ 4 }>
				<StatCard.Label heading={ 2 }>Subscribers reached</StatCard.Label>
			</StatCard.Root>
		);
		expect( screen.getByRole( 'heading', { level: 2, name: 'Subscribers reached' } ) ).toBeInTheDocument();
	} );

	// Inside the heading, the control's text would join the heading's accessible
	// name and the document outline.
	it( 'renders the suffix beside the heading rather than inside it', () => {
		render(
			<StatCard.Root>
				<StatCard.Label suffix={ <button type="button">About this metric</button> }>Subscribers reached</StatCard.Label>
			</StatCard.Root>
		);
		const heading = screen.getByRole( 'heading', { level: 3, name: 'Subscribers reached' } );
		expect( heading ).not.toContainElement( screen.getByRole( 'button', { name: 'About this metric' } ) );
	} );

	it( 'merges className onto the row, not the heading', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Label className="consumer-label">Subscribers reached</StatCard.Label>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__label' ) ).toHaveClass( 'consumer-label' );
		expect( screen.getByRole( 'heading', { level: 3 } ) ).not.toHaveClass( 'consumer-label' );
	} );

	it( 'throws outside Root', () => {
		renderOrphan( <StatCard.Label>Orphan</StatCard.Label> );
	} );
} );

describe( 'StatCard.Body', () => {
	it( 'renders its children', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Body>
					<p>body</p>
				</StatCard.Body>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__body' ) ).toBeInTheDocument();
		expect( screen.getByText( 'body' ) ).toBeInTheDocument();
	} );

	it( 'throws outside Root', () => {
		renderOrphan( <StatCard.Body>Orphan</StatCard.Body> );
	} );
} );

describe( 'StatCard.Value', () => {
	it( 'renders a formatted value as-is, with nothing spoken over it', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value="1,284" />
			</StatCard.Root>
		);
		expect( screen.getByText( '1,284' ) ).toBeInTheDocument();
		expect( container.querySelector( '.screen-reader-text' ) ).not.toBeInTheDocument();
		expect( container.querySelector( '[aria-hidden="true"]' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the null glyph with an accessible name for a null value', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ null } />
			</StatCard.Root>
		);
		expect( screen.getByText( NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveClass( 'screen-reader-text' );
	} );

	// role="img" makes NVDA and VoiceOver announce "graphic" for a typographic
	// placeholder, which is why the glyph is hidden and named in text instead.
	it( 'does not expose the glyph as an image', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ null } />
			</StatCard.Root>
		);
		expect( screen.queryByRole( 'img' ) ).not.toBeInTheDocument();
	} );

	it( 'speaks valueLabel instead of the visible value', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value="$1.2M" valueLabel="1.2 million dollars" />
			</StatCard.Root>
		);
		expect( screen.getByText( '$1.2M' ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( '1.2 million dollars' ) ).toHaveClass( 'screen-reader-text' );
	} );

	it( 'lets valueLabel replace the default name of the null glyph', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ null } valueLabel="No conversions in this timeframe" />
			</StatCard.Root>
		);
		expect( screen.getByText( 'No conversions in this timeframe' ) ).toHaveClass( 'screen-reader-text' );
		expect( screen.queryByText( 'Not applicable' ) ).not.toBeInTheDocument();
	} );

	it( 'drops the hero scale for a text variant', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value="0 of 17" variant="text" />
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__value' ) ).toHaveClass( 'newspack-stat-card__value--text' );
	} );

	it( 'keeps the hero scale by default', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value="1,284" />
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__value' ) ).not.toHaveClass( 'newspack-stat-card__value--text' );
	} );

	it( 'throws outside Root', () => {
		renderOrphan( <StatCard.Value value="1,284" /> );
	} );
} );

describe( 'StatCard.Secondary', () => {
	it( 'renders its children', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Secondary>Up from 1,190 last month</StatCard.Secondary>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__secondary' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Up from 1,190 last month' ) ).toBeInTheDocument();
	} );

	it( 'throws outside Root', () => {
		renderOrphan( <StatCard.Secondary>Orphan</StatCard.Secondary> );
	} );
} );

describe( 'StatCard.Footer', () => {
	it( 'wraps a bare description in the description styling', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer>Readers who received at least one campaign.</StatCard.Footer>
			</StatCard.Root>
		);
		const description = container.querySelector( '.newspack-stat-card__description' );
		expect( description.tagName ).toBe( 'P' );
		expect( description ).toHaveTextContent( 'Readers who received at least one campaign.' );
	} );

	it( 'passes elements through untouched', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer>
					<button type="button">See the products</button>
				</StatCard.Footer>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__description' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'See the products' } ) ).toBeInTheDocument();
	} );

	it( 'wraps only the text when an action sits beside it', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer>
					{ 'Products this rule applies to.' }
					<button type="button">See the products</button>
				</StatCard.Footer>
			</StatCard.Root>
		);
		expect( container.querySelectorAll( '.newspack-stat-card__description' ) ).toHaveLength( 1 );
		expect( screen.getByText( 'Products this rule applies to.' ) ).toHaveClass( 'newspack-stat-card__description' );
		expect( screen.getByRole( 'button', { name: 'See the products' } ) ).toBeInTheDocument();
	} );

	it( 'throws outside Root', () => {
		renderOrphan( <StatCard.Footer>Orphan</StatCard.Footer> );
	} );
} );
