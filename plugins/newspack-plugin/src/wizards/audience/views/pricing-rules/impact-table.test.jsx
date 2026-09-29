/**
 * The impact table, rendered against the real DataViews so the columns and the
 * segment reconciliation are asserted on the DOM.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitForElementToBeRemoved } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ImpactTable from './impact-table';

// A stand-in makes framing observable and still renders the `after` slot the
// See More button lives in.
jest.mock( '../../../../../packages/components/src', () => ( {
	TableCard: ( { children, after } ) => (
		<div data-testid="table-card">
			{ children }
			{ after }
		</div>
	),
} ) );

const CURRENCY = { code: 'USD', symbol: '$', decimals: 2 };

const row = ( over = {} ) => ( {
	product_id: 1,
	name: 'Monthly',
	edit_link: 'https://example.test/edit/1',
	regular: 10,
	adjusted: 5,
	is_subscription: true,
	changed: false,
	segments: [],
	...over,
} );

// Distinct regular prices so the sort order is deterministic and the slice is
// provably the top of the sorted set, not the head of the input.
const sample = count => Array.from( { length: count }, ( _, i ) => row( { product_id: i + 1, name: `Product ${ i + 1 }`, regular: count - i } ) );

const bodyRows = () => screen.getAllByRole( 'row' ).length - 1;
const toggle = () => screen.queryByRole( 'button', { name: /See (More|Less)/ } );

describe( 'ImpactTable', () => {
	it( 'shows the first ten of a longer sample behind a See More button', () => {
		render( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } /> );

		expect( bodyRows() ).toBe( 10 );
		expect( toggle() ).toHaveTextContent( 'See More' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'reveals the rest and flips the label when the button is used', () => {
		render( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } /> );
		fireEvent.click( toggle() );

		expect( bodyRows() ).toBe( 25 );
		expect( toggle() ).toHaveTextContent( 'See Less' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'collapses back to ten when the button is used again', () => {
		render( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } /> );
		fireEvent.click( toggle() );
		fireEvent.click( toggle() );

		expect( bodyRows() ).toBe( 10 );
		expect( toggle() ).toHaveTextContent( 'See More' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	// The point of slicing after the sort: a collapsed table shows the current top
	// ten, not the ten that happened to be first in the response.
	it( 'keeps the top ten of the sort, not the first ten of the sample', async () => {
		render( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Regular' } ) );
		const ascending = await screen.findByRole( 'menuitemradio', { name: 'Sort ascending' } );
		fireEvent.click( ascending );
		// The header menu renders over a backdrop that hides the table from the
		// accessibility tree, so the rows only read once it has gone.
		fireEvent.keyDown( ascending, { key: 'Escape' } );
		await waitForElementToBeRemoved( () => screen.queryByRole( 'menu' ) );

		expect( bodyRows() ).toBe( 10 );
		// Ascending by regular puts the last-listed products first.
		expect( screen.getByText( 'Product 25' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Product 1' ) ).not.toBeInTheDocument();
	} );

	it( 'offers no button when the sample fits the limit', () => {
		render( <ImpactTable baseline={ sample( 10 ) } segmentGroups={ [] } currency={ CURRENCY } /> );

		expect( bodyRows() ).toBe( 10 );
		expect( toggle() ).not.toBeInTheDocument();
	} );

	it( 'points the toggle at the table it expands', () => {
		render( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } /> );
		const region = screen.getByRole( 'region', { name: /Resulting prices/ } );

		expect( toggle() ).toHaveAttribute( 'aria-controls', region.id );
		expect( region.id ).not.toBe( '' );
	} );

	it( 'collapses a sample that widens past the limit', () => {
		const { rerender } = render( <ImpactTable baseline={ sample( 3 ) } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( toggle() ).not.toBeInTheDocument();

		rerender( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } /> );

		expect( bodyRows() ).toBe( 10 );
		expect( toggle() ).toHaveTextContent( 'See More' );
	} );

	it( 'returns to the collapsed view when a different set of products arrives', () => {
		const { rerender } = render( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } /> );
		fireEvent.click( toggle() );
		expect( bodyRows() ).toBe( 25 );

		rerender( <ImpactTable baseline={ sample( 30 ) } segmentGroups={ [] } currency={ CURRENCY } /> );

		expect( bodyRows() ).toBe( 10 );
		expect( toggle() ).toHaveTextContent( 'See More' );
	} );

	// The publisher watching their own edit reprice the same products has not asked
	// to be collapsed again.
	it( 'keeps the expansion when the same products come back repriced', () => {
		const { rerender } = render( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } /> );
		fireEvent.click( toggle() );
		expect( bodyRows() ).toBe( 25 );

		const repriced = sample( 25 ).map( item => ( { ...item, adjusted: item.adjusted + 1 } ) );
		rerender( <ImpactTable baseline={ repriced } segmentGroups={ [] } currency={ CURRENCY } /> );

		expect( bodyRows() ).toBe( 25 );
		expect( toggle() ).toHaveTextContent( 'See Less' );
	} );

	it( 'renders one row per product with its regular and resulting price', () => {
		render( <ImpactTable baseline={ [ row() ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( screen.getByRole( 'link', { name: 'Monthly' } ) ).toHaveAttribute( 'href', 'https://example.test/edit/1' );
		expect( screen.getByText( '$10.00' ) ).toBeInTheDocument();
		expect( screen.getByText( '$5.00' ) ).toBeInTheDocument();
	} );

	it( 'names the single price column plainly when there are no segments', () => {
		render( <ImpactTable baseline={ [ row() ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( screen.getByText( 'Resulting price' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Everyone else' ) ).not.toBeInTheDocument();
	} );

	it( 'adds a column per segment and renames the baseline', () => {
		render(
			<ImpactTable
				baseline={ [ row() ] }
				segmentGroups={ [ { segment_id: 7, segment_label: 'Lapsed', sample: [ row( { adjusted: 3 } ) ] } ] }
				currency={ CURRENCY }
			/>
		);
		expect( screen.getByText( 'Everyone else' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Lapsed' ) ).toBeInTheDocument();
		expect( screen.getByText( '$3.00' ) ).toBeInTheDocument();
	} );

	it( 'explains what the segment columns model', () => {
		render(
			<ImpactTable
				baseline={ [ row() ] }
				segmentGroups={ [ { segment_id: 7, segment_label: 'Lapsed', sample: [ row() ] } ] }
				currency={ CURRENCY }
			/>
		);
		expect( screen.getByText( /new sign-ups only/ ) ).toBeInTheDocument();
	} );

	it( 'leaves the caption off when no segment column is present', () => {
		render( <ImpactTable baseline={ [ row() ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( screen.queryByText( /new sign-ups only/ ) ).not.toBeInTheDocument();
	} );

	it( 'marks a changed price', () => {
		const { container } = render( <ImpactTable baseline={ [ row( { changed: true } ) ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( container.querySelector( '.is-changed' ) ).toBeInTheDocument();
	} );

	it( 'chains a stepped rule with an arrow', () => {
		const segments = [
			{ from_cycle: 1, amount: 5, rule_id: 'r', rule_title: 't', rule_edit_link: '', changed: false },
			{ from_cycle: 2, amount: 8, rule_id: 'r', rule_title: 't', rule_edit_link: '', changed: false },
		];
		const { container } = render( <ImpactTable baseline={ [ row( { segments } ) ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( container.textContent ).toContain( '→' );
		expect( container.textContent ).not.toContain( '·' );
	} );

	it( 'marks only the changed cycle of a stepped rule, not the whole cell', () => {
		const segments = [
			{ from_cycle: 1, amount: 5, rule_id: 'r', rule_title: 't', rule_edit_link: '', changed: false },
			{ from_cycle: 2, amount: 8, rule_id: 'r', rule_title: 't', rule_edit_link: '', changed: true },
		];
		const { container } = render(
			<ImpactTable baseline={ [ row( { changed: true, segments } ) ] } segmentGroups={ [] } currency={ CURRENCY } />
		);
		const marked = container.querySelectorAll( '.is-changed' );
		expect( marked ).toHaveLength( 1 );
		expect( marked[ 0 ] ).toHaveTextContent( 'c2 $8.00' );
	} );

	it( 'leads each cycle with its marker and explains the marker once', () => {
		const segments = [
			{ from_cycle: 1, amount: 5, rule_id: 'r', rule_title: 't', rule_edit_link: '', changed: false },
			{ from_cycle: 7, amount: 8, rule_id: 'r', rule_title: 't', rule_edit_link: '', changed: false },
		];
		render( <ImpactTable baseline={ [ row( { segments } ) ] } segmentGroups={ [] } currency={ CURRENCY } /> );

		expect( screen.getByText( /c1 \$5\.00/ ) ).toBeInTheDocument();
		expect( screen.getByText( /c7 \$8\.00/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /from cycle 7/ ) ).not.toBeInTheDocument();
		expect( screen.getByText( /c1 is the initial purchase/ ) ).toBeInTheDocument();
	} );

	it( 'says nothing about cycle markers when no cell is stepped', () => {
		render( <ImpactTable baseline={ [ row() ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( screen.queryByText( /c1 is the initial purchase/ ) ).not.toBeInTheDocument();
	} );

	it( 'leaves the marker note to its host when asked to', () => {
		const segments = [
			{ from_cycle: 1, amount: 5, rule_id: 'r', rule_title: 't', rule_edit_link: '', changed: false },
			{ from_cycle: 7, amount: 8, rule_id: 'r', rule_title: 't', rule_edit_link: '', changed: false },
		];
		render( <ImpactTable baseline={ [ row( { segments } ) ] } segmentGroups={ [] } currency={ CURRENCY } showCycleNote={ false } /> );

		expect( screen.getByText( /c1 \$5\.00/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /c1 is the initial purchase/ ) ).not.toBeInTheDocument();
	} );

	it( 'renders without the Newspack DataViews page wrapper', () => {
		const { container } = render( <ImpactTable baseline={ [ row() ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( container.querySelector( '.newspack-dataviews' ) ).toBeNull();
	} );

	it( 'offers no way to hide or move a column, but keeps sorting', async () => {
		render( <ImpactTable baseline={ [ row() ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Regular' } ) );

		expect( await screen.findByRole( 'menuitemradio', { name: 'Sort ascending' } ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Hide column' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Move left' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Move right' ) ).not.toBeInTheDocument();
	} );

	it( 'renders segment headers as plain text, not menu triggers', () => {
		render(
			<ImpactTable
				baseline={ [ row() ] }
				segmentGroups={ [ { segment_id: 7, segment_label: 'Lapsed', sample: [ row( { adjusted: 3 } ) ] } ] }
				currency={ CURRENCY }
			/>
		);

		expect( screen.getByText( 'Lapsed' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Lapsed' } ) ).not.toBeInTheDocument();
	} );

	it( 'adds a column when a segment group appears mid-edit', () => {
		const { rerender } = render( <ImpactTable baseline={ [ row() ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( screen.queryByText( 'Lapsed' ) ).not.toBeInTheDocument();

		rerender(
			<ImpactTable
				baseline={ [ row() ] }
				segmentGroups={ [ { segment_id: 7, segment_label: 'Lapsed', sample: [ row( { adjusted: 3 } ) ] } ] }
				currency={ CURRENCY }
			/>
		);

		expect( screen.getByText( 'Lapsed' ) ).toBeInTheDocument();
		expect( screen.getByText( '$3.00' ) ).toBeInTheDocument();
	} );

	it( 'gives each segment column a col element the tint can hook onto', () => {
		const { container } = render(
			<ImpactTable
				baseline={ [ row() ] }
				segmentGroups={ [ { segment_id: 7, segment_label: 'Lapsed', sample: [ row() ] } ] }
				currency={ CURRENCY }
			/>
		);
		expect( container.querySelector( 'col[class*="__col-seg-"]' ) ).toBeInTheDocument();
	} );

	it( 'names the table for assistive technology', () => {
		render( <ImpactTable baseline={ [ row() ] } segmentGroups={ [] } currency={ CURRENCY } /> );
		expect( screen.getByRole( 'region', { name: 'Resulting prices by product and reader segment' } ) ).toBeInTheDocument();
	} );

	it( 'frames itself in a card by default', () => {
		render( <ImpactTable baseline={ sample( 3 ) } segmentGroups={ [] } currency={ CURRENCY } /> );

		expect( screen.getByTestId( 'table-card' ) ).toBeInTheDocument();
	} );

	it( 'drops the card when unframed, so a modal supplies the frame', () => {
		render( <ImpactTable baseline={ sample( 3 ) } segmentGroups={ [] } currency={ CURRENCY } framed={ false } /> );

		expect( screen.queryByTestId( 'table-card' ) ).not.toBeInTheDocument();
		expect( bodyRows() ).toBe( 3 );
	} );

	it( 'renders every row and no See More when collapsing is off', () => {
		render( <ImpactTable baseline={ sample( 25 ) } segmentGroups={ [] } currency={ CURRENCY } collapsible={ false } /> );

		expect( bodyRows() ).toBe( 25 );
		expect( toggle() ).toBeNull();
	} );
} );
