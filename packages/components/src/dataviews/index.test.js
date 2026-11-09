/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { _n, sprintf } from '@wordpress/i18n';

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

	it( 'gives the title column its width through a custom property', () => {
		const { container } = renderDataViews();
		const wrapper = container.querySelector( '.newspack-dataviews' );

		expect( wrapper.style.getPropertyValue( '--newspack-dataviews-title-width' ) ).toBe( '33.33%' );
	} );

	it( 'gives the other columns their share through a custom property', () => {
		const { container } = renderDataViews();

		expect( container.querySelector( '.newspack-dataviews' ).style.getPropertyValue( '--newspack-dataviews-column-width' ) ).toBe( '33.33cqi' );
	} );

	it( 'sets no title width for a grid', () => {
		const { container } = renderDataViews( { view: { ...view, type: 'grid' }, defaultLayouts: { grid: {} } } );

		expect( container.querySelector( '.newspack-dataviews' ).style.getPropertyValue( '--newspack-dataviews-title-width' ) ).toBe( '' );
	} );

	it( 'shows the title even when a stored view hid it', () => {
		renderDataViews( { view: { ...view, showTitle: false } } );

		expect( screen.getByText( 'One' ) ).toBeInTheDocument();
	} );

	it( 'leaves the search out when search is off', () => {
		const { container } = renderDataViews( { inlineFilters: true, search: false } );

		expect( container.querySelector( 'input[type="search"]' ) ).not.toBeInTheDocument();
		expect( filterChips( container ) ).toEqual( [ 'Plan', 'Status' ] );
	} );

	it( 'lays an embedded list out as inside a card, with no page gutter', () => {
		const { container } = renderDataViews( { embedded: true } );

		expect( container.querySelector( '.newspack-dataviews' ) ).toHaveClass( 'newspack-dataviews--in-card' );
	} );

	it( 'keeps the page gutter by default', () => {
		const { container } = renderDataViews();

		expect( container.querySelector( '.newspack-dataviews' ) ).not.toHaveClass( 'newspack-dataviews--in-card' );
	} );

	describe( 'countLabel', () => {
		const bulkActions = [ { id: 'remove', label: 'Remove', supportsBulk: true, callback: () => {} } ];
		const rows = count => Array.from( { length: count }, ( _, index ) => ( { id: index + 1, title: `Row ${ index + 1 }` } ) );
		const memberLabel = ( count, formatted ) => sprintf( _n( '%s member', '%s members', count ), formatted );
		const footerCount = container => container.querySelector( '.dataviews-footer__item-count' )?.textContent;

		it( 'names a single item in the singular', () => {
			const { container } = renderDataViews( { actions: bulkActions, countLabel: memberLabel } );

			expect( footerCount( container ) ).toBe( '1 member' );
		} );

		it( 'names several items in the plural', () => {
			const { container } = renderDataViews( {
				actions: bulkActions,
				countLabel: memberLabel,
				data: rows( 6 ),
				paginationInfo: { totalItems: 6, totalPages: 1 },
			} );

			expect( footerCount( container ) ).toBe( '6 members' );
		} );

		it( 'counts the page against the named total when the list spans pages', () => {
			const { container } = renderDataViews( {
				actions: bulkActions,
				countLabel: memberLabel,
				data: rows( 10 ),
				paginationInfo: { totalItems: 1200, totalPages: 120 },
			} );

			expect( footerCount( container ) ).toBe( '10 of 1,200 members' );
		} );

		it( 'keeps core’s count when no label is set', () => {
			const { container } = renderDataViews( { actions: bulkActions } );

			expect( footerCount( container ) ).toBe( '1 item' );
		} );

		it( 'shows no count when no row has a bulk action, as core does', () => {
			const { container } = renderDataViews( { countLabel: memberLabel } );

			expect( container.querySelector( '.dataviews-footer' ) ).not.toBeInTheDocument();
		} );

		it( 'names the count in a custom layout that renders DataViews.Footer', () => {
			const { container } = renderDataViews( {
				actions: bulkActions,
				countLabel: memberLabel,
				data: rows( 6 ),
				paginationInfo: { totalItems: 6, totalPages: 1 },
				children: <DataViews.Footer />,
			} );

			expect( footerCount( container ) ).toBe( '6 members' );
		} );

		it( 'keeps core’s count in a custom layout without a label', () => {
			const { container } = renderDataViews( { actions: bulkActions, children: <DataViews.Footer /> } );

			expect( footerCount( container ) ).toBe( '1 item' );
		} );
	} );
} );
