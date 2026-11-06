import { render, screen } from '@testing-library/react';
import { DataViews } from '@wordpress/dataviews/wp';

import InlineFiltersLayout from '.';

const fields = [
	{ id: 'title', label: 'Title', enableGlobalSearch: true },
	{
		id: 'status',
		label: 'Status',
		elements: [
			{ value: 'draft', label: 'Draft' },
			{ value: 'sent', label: 'Sent' },
		],
		filterBy: { operators: [ 'isAny' ], isPrimary: true },
	},
	{
		id: 'author',
		label: 'Author',
		elements: [ { value: '1', label: 'Admin' } ],
		filterBy: { operators: [ 'isAny' ], isPrimary: true },
	},
];

const view = { type: 'table', page: 1, perPage: 10, search: '', fields: [ 'status', 'author' ], filters: [], titleField: 'title' };

const renderList = layoutProps =>
	render(
		<DataViews
			data={ [ { id: 1, title: 'One', status: 'draft', author: '1' } ] }
			fields={ fields }
			view={ view }
			onChangeView={ () => {} }
			paginationInfo={ { totalItems: 1, totalPages: 1 } }
			defaultLayouts={ { table: {} } }
			getItemId={ item => String( item.id ) }
		>
			<InlineFiltersLayout { ...layoutProps } />
		</DataViews>
	);

const filterChips = container => [ ...container.querySelectorAll( '.dataviews-filters__summary-chip' ) ].map( chip => chip.textContent );

describe( 'InlineFiltersLayout', () => {
	beforeAll( () => {
		global.ResizeObserver =
			global.ResizeObserver ||
			class {
				observe() {}
				unobserve() {}
				disconnect() {}
			};
	} );

	it( 'shows the filter chips beside the search, with no filter toggle', () => {
		const { container } = renderList();
		const searchRow = container.querySelector( '.dataviews__search' );

		expect( searchRow.querySelector( 'input[type="search"]' ) ).toBeInTheDocument();
		expect( filterChips( searchRow ) ).toEqual( [ 'Author', 'Status' ] );
		expect( container.querySelector( '.dataviews-filters__visibility-toggle' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the header content beside the view options', () => {
		const { container } = renderList( { header: <button type="button">Per page</button> } );

		expect( container.querySelector( '.newspack-newsletters-list__view-config' ) ).toContainElement(
			screen.getByRole( 'button', { name: 'Per page' } )
		);
	} );
} );
