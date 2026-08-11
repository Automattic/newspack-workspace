/**
 * The catalogue panel above the Pricing Rules list: headline numbers eagerly,
 * the product table only once someone asks for it.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, act, waitForElementToBeRemoved } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import CatalogImpact from './catalog-impact';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// The table itself is covered by impact-table.test.jsx; here it only needs to be
// identifiable, and its props assertable.
jest.mock( './impact-table', () => ( { baseline, framed, collapsible } ) => (
	<div data-testid="impact-table" data-framed={ String( framed ) } data-collapsible={ String( collapsible ) }>
		{ baseline.length } rows
	</div>
) );

const CURRENCY = { code: 'USD', symbol: '$', decimals: 2 };

const stats = ( over = {} ) => ( {
	supported: true,
	total_matching: 33,
	count_limited: false,
	preview_limited: true,
	sample_count: 1,
	currency: CURRENCY,
	sample: [],
	segment_groups: [],
	...over,
} );

const detail = ( over = {} ) => ( {
	...stats(),
	preview_limited: false,
	sample_count: 3,
	sample: [ 1, 2, 3 ].map( id => ( {
		product_id: id,
		name: `Product ${ id }`,
		edit_link: '',
		regular: 10,
		adjusted: 5,
		is_subscription: true,
		changed: false,
		segments: [],
	} ) ),
	...over,
} );

const openModal = () => fireEvent.click( screen.getByRole( 'button', { name: 'View affected products' } ) );

describe( 'CatalogImpact', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'leads with the affected-product count as a headline number', () => {
		render( <CatalogImpact stats={ stats() } /> );

		expect( screen.getByText( '33' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Products affected' ) ).toBeInTheDocument();
	} );

	it( 'fetches nothing until the table is asked for', () => {
		render( <CatalogImpact stats={ stats() } /> );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( screen.queryByTestId( 'impact-table' ) ).not.toBeInTheDocument();
	} );

	it( 'requests the full sample on open and shows the table unframed', async () => {
		apiFetch.mockResolvedValue( detail() );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );

		expect( apiFetch ).toHaveBeenCalledWith( { path: '/wc-dynamic-pricing/v1/impact-preview?limit=50' } );
		const table = screen.getByTestId( 'impact-table' );
		expect( table ).toHaveTextContent( '3 rows' );
		expect( table ).toHaveAttribute( 'data-framed', 'false' );
		expect( table ).toHaveAttribute( 'data-collapsible', 'false' );
	} );

	it( 'spins in the modal until the sample lands', async () => {
		let land;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				land = resolve;
			} )
		);
		render( <CatalogImpact stats={ stats() } /> );
		openModal();

		expect( screen.queryByTestId( 'impact-table' ) ).not.toBeInTheDocument();
		expect( document.querySelector( '.components-spinner' ) ).toBeInTheDocument();

		await act( async () => {
			land( detail() );
		} );

		expect( screen.getByTestId( 'impact-table' ) ).toBeInTheDocument();
	} );

	it( 'keeps the sample across a close and reopen', async () => {
		apiFetch.mockResolvedValue( detail() );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		// The modal's exit animation defers onRequestClose, and until the dialog
		// goes the trigger stays out of the accessibility tree.
		await waitForElementToBeRemoved( () => screen.queryByRole( 'dialog' ) );
		await act( async () => {
			openModal();
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'says so in the modal when the sample cannot be loaded', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );

		expect( screen.getByText( /Could not load the affected products/ ) ).toBeInTheDocument();
	} );

	it( 'tries again on reopen after a failed fetch', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'nope' ) ).mockResolvedValueOnce( detail() );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );

		expect( screen.getByText( /Could not load the affected products/ ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		await waitForElementToBeRemoved( () => screen.queryByRole( 'dialog' ) );
		await act( async () => {
			openModal();
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( screen.queryByText( /Could not load the affected products/ ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'impact-table' ) ).toBeInTheDocument();
	} );

	it( 'withholds the table button and explains itself when nothing is affected', () => {
		render( <CatalogImpact stats={ stats( { total_matching: 0 } ) } /> );

		expect( screen.queryByRole( 'button', { name: 'View affected products' } ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'No active pricing rules are affecting products yet.' ) ).toBeInTheDocument();
	} );
} );
