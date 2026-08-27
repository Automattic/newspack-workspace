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

// Covered by impact-table.test.jsx; here it only needs its props assertable.
jest.mock( './impact-table', () => ( { baseline, framed, collapsible } ) => (
	<div data-testid="impact-table" data-framed={ String( framed ) } data-collapsible={ String( collapsible ) }>
		{ baseline.length } rows
	</div>
) );

jest.mock( './impact-empty', () => ( { reason, headingLevel } ) => (
	<div data-testid="impact-empty" data-reason={ reason } data-heading-level={ String( headingLevel ) } />
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

const openModal = () => fireEvent.click( screen.getByRole( 'button', { name: 'View Affected Products' } ) );

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
		// The exit animation defers onRequestClose; until the dialog goes, the
		// trigger stays out of the accessibility tree.
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

	it( 'issues no second request when reopened before the sample lands', async () => {
		let land;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				land = resolve;
			} )
		);
		render( <CatalogImpact stats={ stats() } /> );
		openModal();

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		await waitForElementToBeRemoved( () => screen.queryByRole( 'dialog' ) );
		await act( async () => {
			openModal();
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			land( detail() );
		} );

		expect( screen.getByTestId( 'impact-table' ) ).toBeInTheDocument();
		expect( screen.queryByText( /Could not load the affected products/ ) ).not.toBeInTheDocument();
	} );

	it( 'stands down instead of rendering a table the engine did not supply', async () => {
		apiFetch.mockResolvedValue( { supported: false } );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );

		expect( screen.queryByTestId( 'impact-table' ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'impact-empty' ) ).toHaveAttribute( 'data-reason', 'unsupported' );
	} );

	it( 'stands down when the sample comes back empty', async () => {
		apiFetch.mockResolvedValue( detail( { sample: [] } ) );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );

		expect( screen.queryByTestId( 'impact-table' ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'impact-empty' ) ).toHaveAttribute( 'data-reason', 'no-products' );
	} );

	it( 'names the heading and announces the modal load for assistive technology', async () => {
		let land;
		apiFetch.mockReturnValue(
			new Promise( resolve => {
				land = resolve;
			} )
		);
		render( <CatalogImpact stats={ stats() } /> );

		expect( screen.getByRole( 'heading', { name: 'Catalog impact', level: 2 } ) ).toBeInTheDocument();

		openModal();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Loading the affected products' );

		await act( async () => {
			land( detail() );
		} );
	} );

	it( 'says the table is a sample when the engine reports a partial preview', async () => {
		apiFetch.mockResolvedValue( detail( { preview_limited: true, sample_count: 3 } ) );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );

		expect( screen.getByText( 'Showing a sample of 3 products.' ) ).toBeInTheDocument();
	} );

	it( 'says nothing about sampling when the whole set is shown', async () => {
		apiFetch.mockResolvedValue( detail( { preview_limited: false, sample_count: 3 } ) );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );

		expect( screen.queryByText( /Showing a sample of/ ) ).not.toBeInTheDocument();
	} );

	it( 'places the trigger inside the affected-products tile', () => {
		const { container } = render( <CatalogImpact stats={ stats() } /> );

		const section = container.querySelector( '.newspack-pricing-rules__impact' );
		const button = screen.getByRole( 'button', { name: 'View Affected Products' } );
		const tile = screen.getByText( 'Products affected' ).closest( '.newspack-stat-card' );

		expect( section.tagName ).toBe( 'SECTION' );
		expect( button.tagName ).toBe( 'BUTTON' );
		expect( tile.contains( button ) ).toBe( true );
	} );

	it( 'hangs the trigger off the description rather than the number', () => {
		render( <CatalogImpact stats={ stats() } /> );

		const footer = screen.getByText( 'Rules currently price these products.' ).parentElement;

		expect( footer ).toHaveClass( 'newspack-stat-card__footer' );
		expect( footer.contains( screen.getByRole( 'button', { name: 'View Affected Products' } ) ) ).toBe( true );
	} );

	it( 'names the section with the heading it already carries', () => {
		render( <CatalogImpact stats={ stats() } /> );

		expect( screen.getByRole( 'region', { name: 'Catalog impact' } ) ).toBeInTheDocument();
	} );

	it( 'withholds the table button and explains itself when nothing is affected', () => {
		render( <CatalogImpact stats={ stats( { total_matching: 0 } ) } /> );

		expect( screen.queryByRole( 'button', { name: 'View Affected Products' } ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'No active pricing rules are affecting products yet.' ) ).toBeInTheDocument();
	} );

	it( 'keeps the table on offer and claims nothing when the count is missing', () => {
		render( <CatalogImpact stats={ stats( { total_matching: undefined } ) } /> );

		expect( screen.getByRole( 'button', { name: 'View Affected Products' } ) ).toBeInTheDocument();
		expect( screen.queryByText( 'No active pricing rules are affecting products yet.' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '—' ) ).toBeInTheDocument();
	} );

	it( 'drops the empty state a level inside the modal', async () => {
		apiFetch.mockResolvedValue( detail( { sample: [] } ) );
		render( <CatalogImpact stats={ stats() } /> );

		await act( async () => {
			openModal();
		} );

		expect( screen.getByTestId( 'impact-empty' ) ).toHaveAttribute( 'data-heading-level', '2' );
	} );
} );
