/**
 * The impact table, rendered against the real DataViews so the columns and the
 * segment reconciliation are asserted on the DOM.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ImpactTable from './impact-table';

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

describe( 'ImpactTable', () => {
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
} );
