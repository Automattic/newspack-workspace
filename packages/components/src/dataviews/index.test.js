/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import DataViews from '.';

const fields = [
	{ id: 'title', label: 'Title', enableGlobalSearch: true },
	{
		id: 'status',
		label: 'Status',
		elements: [
			{ value: 'active', label: 'Active' },
			{ value: 'cancelled', label: 'Cancelled' },
		],
		filterBy: { operators: [ 'isAny' ] },
	},
	{
		id: 'plan',
		label: 'Plan',
		elements: [ { value: 'digital', label: 'Digital' } ],
		filterBy: { operators: [ 'isAny' ] },
	},
];

const view = { type: 'table', page: 1, perPage: 10, search: '', fields: [ 'status', 'plan' ], filters: [], titleField: 'title' };

const renderDataViews = props =>
	render(
		<DataViews
			data={ [ { id: 1, title: 'One', status: 'active', plan: 'digital' } ] }
			fields={ fields }
			view={ view }
			onChangeView={ () => {} }
			paginationInfo={ { totalItems: 1, totalPages: 1 } }
			defaultLayouts={ { table: {} } }
			getItemId={ item => item.id }
			{ ...props }
		/>
	);

const filterChips = container => [ ...container.querySelectorAll( '.dataviews-filters__summary-chip' ) ].map( chip => chip.textContent );

describe( 'DataViews', () => {
	beforeAll( () => {
		global.ResizeObserver =
			global.ResizeObserver ||
			class {
				observe() {}
				unobserve() {}
				disconnect() {}
			};
	} );

	it( 'keeps filters behind the toggle by default', () => {
		const { container } = renderDataViews();

		expect( filterChips( container ) ).toEqual( [] );
		expect( screen.getByRole( 'button', { name: /filter/i } ) ).toBeInTheDocument();
	} );

	it( 'shows every filter as a chip beside the search with inlineFilters', () => {
		const { container } = renderDataViews( { inlineFilters: true } );
		const searchRow = container.querySelector( '.dataviews__search' );

		expect( searchRow.querySelector( 'input[type="search"]' ) ).toBeInTheDocument();
		expect( filterChips( searchRow ) ).toEqual( [ 'Plan', 'Status' ] );
		expect( screen.queryByRole( 'button', { name: /^filter/i } ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the header content beside the view settings with inlineFilters', () => {
		const { container } = renderDataViews( { inlineFilters: true, header: <button type="button">Settings</button> } );

		expect( container.querySelector( '.newspack-dataviews__view-config' ) ).toContainElement(
			screen.getByRole( 'button', { name: 'Settings' } )
		);
	} );

	it( 'leaves the search out when search is off', () => {
		const { container } = renderDataViews( { inlineFilters: true, search: false } );

		expect( container.querySelector( 'input[type="search"]' ) ).not.toBeInTheDocument();
		expect( filterChips( container ) ).toEqual( [ 'Plan', 'Status' ] );
	} );
} );
