/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import StatCard, { STAT_CARD_NULL_GLYPH } from '.';

const renderOrphan = node => {
	const consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	try {
		expect( () => render( node ) ).toThrow( 'StatCard subcomponents must be rendered inside StatCard.Root.' );
	} finally {
		consoleError.mockRestore();
	}
};

describe( 'StatCard.Root', () => {
	// The hero scale is a container query against this class, so losing it
	// silently resizes the figure.
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

	// A wrapper in another repo needs the node to anchor a popover or measure the tile.
	it( 'forwards a ref and passes other props to the card', () => {
		const ref = { current: null };
		const { container } = render(
			<StatCard.Root ref={ ref } id="tile-1" data-testid="tile">
				<p>body</p>
			</StatCard.Root>
		);
		const card = container.querySelector( '.newspack-stat-card' );
		expect( ref.current ).toBe( card );
		expect( card ).toHaveAttribute( 'id', 'tile-1' );
		expect( card ).toHaveAttribute( 'data-testid', 'tile' );
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

	it( 'falls back to h3 for a level outside 2-6', () => {
		const consoleWarn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		render(
			<StatCard.Root>
				<StatCard.Label heading={ 7 }>Subscribers reached</StatCard.Label>
			</StatCard.Root>
		);
		expect( screen.getByRole( 'heading', { level: 3, name: 'Subscribers reached' } ) ).toBeInTheDocument();
		expect( consoleWarn ).toHaveBeenCalled();
		consoleWarn.mockRestore();
	} );

	// Inside the heading, the control's text would join its accessible name.
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

	it( 'merges className onto the body', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Body className="consumer-body">
					<p>body</p>
				</StatCard.Body>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__body' ) ).toHaveClass( 'consumer-body' );
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

	it( 'merges className onto the value', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value="1,284" className="consumer-value" />
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__value' ) ).toHaveClass( 'consumer-value' );
	} );

	it( 'renders the null glyph with an accessible name for a null value', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ null } />
			</StatCard.Root>
		);
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveClass( 'screen-reader-text' );
	} );

	// `value={ data?.count }` before the data arrives must not read as a zero.
	it( 'treats undefined as no figure', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ undefined } />
			</StatCard.Root>
		);
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveClass( 'screen-reader-text' );
	} );

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

	// A label mapped from an empty field must not leave the glyph unnamed.
	it( 'falls back to the default name when valueLabel is empty', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ null } valueLabel="" />
			</StatCard.Root>
		);
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveClass( 'screen-reader-text' );
	} );

	it( 'drops the hero scale for a text variant', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value="0 of 17" variant="text" />
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__value' ) ).toHaveClass( 'newspack-stat-card__value--text' );
	} );

	it( 'keeps the null treatment in the text variant', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value={ null } variant="text" />
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__value' ) ).toHaveClass( 'newspack-stat-card__value--text' );
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveClass( 'screen-reader-text' );
	} );

	it( 'warns on an unknown variant and keeps the hero scale', () => {
		const consoleWarn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value="1,284" variant="headline" />
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__value' ) ).not.toHaveClass( 'newspack-stat-card__value--text' );
		expect( consoleWarn ).toHaveBeenCalled();
		consoleWarn.mockRestore();
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

	it( 'merges className onto the line', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Secondary className="consumer-secondary">Up from 1,190 last month</StatCard.Secondary>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__secondary' ) ).toHaveClass( 'consumer-secondary' );
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

	it( 'merges className onto the footer', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer className="consumer-footer">Readers who received at least one campaign.</StatCard.Footer>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__footer' ) ).toHaveClass( 'consumer-footer' );
	} );

	// An interpolated sentence arrives as several children and has to stay one sentence.
	it( 'keeps a run of text children in one paragraph', () => {
		const count = 12;
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer>Applies to { count } products.</StatCard.Footer>
			</StatCard.Root>
		);
		const descriptions = container.querySelectorAll( '.newspack-stat-card__description' );
		expect( descriptions ).toHaveLength( 1 );
		expect( descriptions[ 0 ] ).toHaveTextContent( 'Applies to 12 products.' );
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

	// An empty or whitespace-only child would otherwise leave a stray paragraph.
	it( 'renders nothing for an empty description', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer>{ '' }</StatCard.Footer>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__description' ) ).not.toBeInTheDocument();
	} );

	it( 'renders nothing for a whitespace-only description', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer>{ '   ' }</StatCard.Footer>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__description' ) ).not.toBeInTheDocument();
	} );

	// The documented escape hatch: inline markup would otherwise split into a block per child.
	it( 'passes a self-wrapped description through as one paragraph', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer>
					<p className="newspack-stat-card__description">
						Applies to <strong>12</strong> products.
					</p>
				</StatCard.Footer>
			</StatCard.Root>
		);
		const descriptions = container.querySelectorAll( '.newspack-stat-card__description' );
		expect( descriptions ).toHaveLength( 1 );
		expect( descriptions[ 0 ] ).toHaveTextContent( 'Applies to 12 products.' );
	} );

	it( 'throws outside Root', () => {
		renderOrphan( <StatCard.Footer>Orphan</StatCard.Footer> );
	} );
} );
