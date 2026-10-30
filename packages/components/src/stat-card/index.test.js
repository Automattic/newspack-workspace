/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';

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

	// The footer only pins to the bottom while the content region is a column.
	it( 'lays the content region out as a column', () => {
		const { container } = render(
			<StatCard.Root>
				<p>body</p>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__content' ) ).toHaveStyle( { flexDirection: 'column' } );
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
		expect( container.querySelector( '[data-visually-hidden]' ) ).not.toBeInTheDocument();
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

	// The glyph exists to tell a missing figure from a zero, so a zero has to survive it.
	it( 'renders a zero as the figure it is', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value={ 0 } />
			</StatCard.Root>
		);
		expect( screen.getByText( '0' ) ).toBeInTheDocument();
		expect( screen.queryByText( STAT_CARD_NULL_GLYPH ) ).not.toBeInTheDocument();
		expect( container.querySelector( '[data-visually-hidden]' ) ).not.toBeInTheDocument();
	} );

	it( 'renders the null glyph with an accessible name for a null value', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ null } />
			</StatCard.Root>
		);
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	// `value={ data?.count }` before the data arrives must not read as a zero.
	it( 'treats undefined as no figure', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ undefined } />
			</StatCard.Root>
		);
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	// A field that reports "no data" as an empty string would otherwise leave a blank hero.
	it( 'treats an empty value as no figure', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value="" />
			</StatCard.Root>
		);
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	// A no-data sentinel padded by the field it came from is still no data.
	it( 'treats a whitespace-only value as no figure', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value="   " />
			</StatCard.Root>
		);
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
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
		expect( screen.getByText( '1.2 million dollars' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	it( 'lets valueLabel replace the default name of the null glyph', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ null } valueLabel="No conversions in this timeframe" />
			</StatCard.Root>
		);
		expect( screen.getByText( 'No conversions in this timeframe' ) ).toHaveAttribute( 'data-visually-hidden' );
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
		expect( screen.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	// Whitespace is truthy, so an unguarded label would hide the figure and name it nothing.
	it( 'falls back to the default name when valueLabel is only whitespace', () => {
		render(
			<StatCard.Root>
				<StatCard.Value value={ null } valueLabel="   " />
			</StatCard.Root>
		);
		expect( screen.getByText( STAT_CARD_NULL_GLYPH ) ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( screen.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
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
		expect( screen.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
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

describe( 'StatCard.Delta', () => {
	it( 'renders the change with the arrow named in text', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="up">2%</StatCard.Delta>
			</StatCard.Root>
		);
		expect( container.querySelector( '[aria-hidden="true"]' ) ).toHaveTextContent( '↑' );
		expect( screen.getByText( 'Up' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.getByText( '2%' ) ).toBeInTheDocument();
	} );

	// Hiding the span forms a box that speech linearises; braille and raw text get no such boundary.
	it( 'separates the spoken direction from the change in the raw text', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="up">2%</StatCard.Delta>
			</StatCard.Root>
		);
		expect( container.querySelector( '[data-visually-hidden]' ).textContent ).toBe( 'Up ' );
		expect( container.querySelector( '.newspack-stat-card__delta' ).textContent ).toBe( '↑Up 2%' );
	} );

	it( 'points the arrow down without changing the colour', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="down">2%</StatCard.Delta>
			</StatCard.Root>
		);
		expect( container.querySelector( '[aria-hidden="true"]' ) ).toHaveTextContent( '↓' );
		expect( screen.getByText( 'Down' ) ).toHaveAttribute( 'data-visually-hidden' );
		const delta = container.querySelector( '.newspack-stat-card__delta' );
		expect( delta ).not.toHaveClass( 'newspack-stat-card__delta--negative' );
		expect( delta ).not.toHaveClass( 'newspack-stat-card__delta--positive' );
	} );

	// A rise is not always good news, so the caller sets the tone separately.
	it( 'takes its tone from the caller, not from the direction', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="up" tone="negative">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__delta' ) ).toHaveClass( 'newspack-stat-card__delta--negative' );
	} );

	// Three sources compose here: the base class, the tone modifier and the consumer's.
	it( 'merges className onto the delta alongside the tone', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="down" tone="negative" className="custom-delta">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		const delta = container.querySelector( '.newspack-stat-card__delta' );
		expect( delta ).toHaveClass( 'newspack-stat-card__delta--negative' );
		expect( delta ).toHaveClass( 'custom-delta' );
	} );

	it( 'lets directionLabel replace the spoken direction', () => {
		render(
			<StatCard.Root>
				<StatCard.Delta direction="up" directionLabel="Increased by">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( screen.getByText( 'Increased by' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.queryByText( 'Up' ) ).not.toBeInTheDocument();
	} );

	// No arrow and the word "Down" would be worse than saying nothing at all.
	it( 'leaves an unrecognised direction unspoken', () => {
		const consoleWarn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="sideways">2%</StatCard.Delta>
			</StatCard.Root>
		);
		expect( container.querySelector( '[aria-hidden="true"]' ) ).not.toBeInTheDocument();
		expect( container.querySelector( '[data-visually-hidden]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '2%' ) ).toBeInTheDocument();
		expect( consoleWarn ).toHaveBeenCalled();
		consoleWarn.mockRestore();
	} );

	// A label mapped from an empty field must not leave the arrow unnamed.
	// The caller chose the words, so a missing arrow must not silence them.
	it( 'speaks a directionLabel where there is no arrow to name', () => {
		const consoleWarn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="sideways" directionLabel="Increased by">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( container.querySelector( '[aria-hidden="true"]' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Increased by' ) ).toHaveAttribute( 'data-visually-hidden' );
		consoleWarn.mockRestore();
	} );

	it( 'falls back to the spoken direction when directionLabel is empty', () => {
		render(
			<StatCard.Root>
				<StatCard.Delta direction="up" directionLabel="">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( screen.getByText( 'Up' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	it( 'falls back to the spoken direction when directionLabel is only whitespace', () => {
		render(
			<StatCard.Root>
				<StatCard.Delta direction="up" directionLabel="   ">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( screen.getByText( 'Up' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	// Word order and valence both live in the caller's sentence, not in DOM order.
	it( 'lets label name the whole delta and hides what it restates', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="up" tone="negative" label="2% more refunds than last month">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( container.querySelector( '[aria-hidden="true"]' ) ).toHaveTextContent( '↑2%' );
		expect( screen.getByText( '2% more refunds than last month' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.queryByText( 'Up' ) ).not.toBeInTheDocument();
	} );

	// The whole-delta sentence outranks the one-word swap, which only prose said until now.
	it( 'lets label win over directionLabel', () => {
		render(
			<StatCard.Root>
				<StatCard.Delta direction="up" label="2% more refunds than last month" directionLabel="Increased by">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( screen.getByText( '2% more refunds than last month' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.queryByText( 'Increased by' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Up' ) ).not.toBeInTheDocument();
	} );

	// Falling back past a blank label should land on the caller's word, not the built-in one.
	it( 'falls back from a blank label to directionLabel rather than the default', () => {
		render(
			<StatCard.Root>
				<StatCard.Delta direction="up" label="   " directionLabel="Increased by">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( screen.getByText( 'Increased by' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.queryByText( 'Up' ) ).not.toBeInTheDocument();
	} );

	// A blank sentence would hide the arrow and the change behind nothing at all.
	it( 'keeps the arrow and the change when label is only whitespace', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Delta direction="up" label="   ">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( container.querySelector( '[aria-hidden="true"]' ) ).toHaveTextContent( '↑' );
		expect( screen.getByText( 'Up' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.getByText( '2%' ) ).toBeInTheDocument();
	} );

	it( 'sits in a row beside the figure when passed as a Value suffix', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value
					value="1,284"
					suffix={
						<StatCard.Delta direction="up" tone="positive">
							2%
						</StatCard.Delta>
					}
				/>
			</StatCard.Root>
		);
		const figure = container.querySelector( '.newspack-stat-card__figure' );
		expect( figure ).toHaveStyle( { flexDirection: 'row' } );
		expect( figure.querySelector( '.newspack-stat-card__value' ) ).toBeInTheDocument();
		expect( figure.querySelector( '.newspack-stat-card__delta' ) ).toBeInTheDocument();
	} );

	it( 'adds no row when the value has no suffix', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value value="1,284" />
			</StatCard.Root>
		);
		expect( container.querySelector( '.newspack-stat-card__figure' ) ).not.toBeInTheDocument();
	} );

	it( 'throws outside Root', () => {
		renderOrphan( <StatCard.Delta direction="up">2%</StatCard.Delta> );
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

	// `createInterpolateElement` returns a Fragment, which has to stay part of the run.
	it( 'keeps an interpolated sentence in the description styling', () => {
		const { container } = render(
			<StatCard.Root>
				<StatCard.Footer>{ createInterpolateElement( 'Applies to <b>12</b> products.', { b: <strong /> } ) }</StatCard.Footer>
			</StatCard.Root>
		);
		const descriptions = container.querySelectorAll( '.newspack-stat-card__description' );
		expect( descriptions ).toHaveLength( 1 );
		expect( descriptions[ 0 ] ).toHaveTextContent( 'Applies to 12 products.' );
		expect( descriptions[ 0 ].querySelector( 'strong' ) ).toBeInTheDocument();
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

describe( 'StatCard leaf slots', () => {
	const leaves = [
		[ 'Label', props => <StatCard.Label { ...props }>Reach</StatCard.Label>, '.newspack-stat-card__label' ],
		[ 'Body', props => <StatCard.Body { ...props } />, '.newspack-stat-card__body' ],
		[ 'Value', props => <StatCard.Value value="1,284" { ...props } />, '.newspack-stat-card__value' ],
		[
			'Delta',
			props => (
				<StatCard.Delta direction="up" { ...props }>
					2%
				</StatCard.Delta>
			),
			'.newspack-stat-card__delta',
		],
		[ 'Secondary', props => <StatCard.Secondary { ...props } />, '.newspack-stat-card__secondary' ],
		[ 'Footer', props => <StatCard.Footer { ...props }>Note</StatCard.Footer>, '.newspack-stat-card__footer' ],
	];

	// Insights hangs a full amount off an abbreviated figure, which has nowhere
	// else to go without adding an element the body layout would have to carry.
	it.each( leaves )( '%s forwards a ref and passes other props through', ( name, renderLeaf, selector ) => {
		const ref = { current: null };
		const { container } = render( <StatCard.Root>{ renderLeaf( { ref, title: 'hint', 'data-testid': 'leaf' } ) }</StatCard.Root> );
		const element = container.querySelector( selector );
		expect( ref.current ).toBe( element );
		expect( element ).toHaveAttribute( 'title', 'hint' );
		expect( element ).toHaveAttribute( 'data-testid', 'leaf' );
	} );

	// The figure is what a wrapper needs to reach, not the row it may share.
	it( 'puts a Value ref on the figure rather than the row it shares with a suffix', () => {
		const ref = { current: null };
		const { container } = render(
			<StatCard.Root>
				<StatCard.Value ref={ ref } value="1,284" suffix={ <StatCard.Delta direction="up">2%</StatCard.Delta> } />
			</StatCard.Root>
		);
		expect( ref.current ).toBe( container.querySelector( '.newspack-stat-card__value' ) );
	} );
} );

describe( 'StatCard outside Root', () => {
	// Nothing above these cards catches an error, so a mistake in a branch only
	// some sites reach must not blank the whole screen.
	it( 'falls back to the default context in production rather than throwing', () => {
		const consoleWarn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const previous = process.env.NODE_ENV;
		process.env.NODE_ENV = 'production';

		try {
			render(
				<>
					<StatCard.Label>Reach</StatCard.Label>
					<StatCard.Value value="1,284" />
				</>
			);
			expect( screen.getByRole( 'heading', { level: 3 } ) ).toHaveTextContent( 'Reach' );
			expect( screen.getByText( '1,284' ) ).toBeInTheDocument();
			expect( consoleWarn ).toHaveBeenCalledWith( 'StatCard subcomponents must be rendered inside StatCard.Root.' );
		} finally {
			process.env.NODE_ENV = previous;
			consoleWarn.mockRestore();
		}
	} );
} );

describe( 'StatCard spoken labels', () => {
	// A bundle registered against another text domain never loads this package's strings.
	it( 'lets Root replace the name of the null glyph', () => {
		render(
			<StatCard.Root labels={ { notApplicable: 'Sans objet' } }>
				<StatCard.Value value={ null } />
			</StatCard.Root>
		);
		expect( screen.getByText( 'Sans objet' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.queryByText( 'Not applicable' ) ).not.toBeInTheDocument();
	} );

	it( 'lets Root replace the spoken directions', () => {
		render(
			<StatCard.Root labels={ { up: 'En hausse', down: 'En baisse' } }>
				<StatCard.Delta direction="up">2%</StatCard.Delta>
				<StatCard.Delta direction="down">4%</StatCard.Delta>
			</StatCard.Root>
		);
		expect( screen.getByText( 'En hausse' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.getByText( 'En baisse' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );

	// The per-instance props were always the override, and stay it.
	it( 'lets valueLabel and directionLabel win over Root labels', () => {
		render(
			<StatCard.Root labels={ { notApplicable: 'Sans objet', up: 'En hausse' } }>
				<StatCard.Value value={ null } valueLabel="Pas encore mesuré" />
				<StatCard.Delta direction="up" directionLabel="Progression de">
					2%
				</StatCard.Delta>
			</StatCard.Root>
		);
		expect( screen.getByText( 'Pas encore mesuré' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.getByText( 'Progression de' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.queryByText( 'Sans objet' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'En hausse' ) ).not.toBeInTheDocument();
	} );

	// A translation that came back empty must not leave the glyph or the arrow unnamed.
	it( 'falls back to the built-in default for a blank Root label', () => {
		render(
			<StatCard.Root labels={ { notApplicable: '   ', up: '' } }>
				<StatCard.Value value={ null } />
				<StatCard.Delta direction="up">2%</StatCard.Delta>
			</StatCard.Root>
		);
		expect( screen.getByText( 'Not applicable' ) ).toHaveAttribute( 'data-visually-hidden' );
		expect( screen.getByText( 'Up' ) ).toHaveAttribute( 'data-visually-hidden' );
	} );
} );
