/**
 * The editor's impact preview: what the publisher sees as the debounced preview
 * request is pending, resolves, or comes back with nothing to price.
 */

/**
 * External dependencies
 */
import { render, screen, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import RulePreview from './rule-preview';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const DEBOUNCE_MS = 500;

const product = ( over = {} ) => ( {
	product_id: 1,
	name: 'Monthly',
	edit_link: '',
	regular: 10,
	adjusted: 5,
	is_subscription: true,
	changed: false,
	segments: [],
	...over,
} );

const response = ( over = {} ) => ( {
	supported: true,
	total_matching: 3,
	count_limited: false,
	preview_limited: false,
	sample_count: 1,
	currency: { code: 'USD', symbol: '$', decimals: 2 },
	sample: [ product() ],
	segment_groups: [],
	...over,
} );

const pending = () => new Promise( () => {} );

const settle = async () => {
	await act( async () => {
		jest.advanceTimersByTime( DEBOUNCE_MS );
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();
	} );
};

describe( 'RulePreview', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'asks for a price before one is typed, and asks the engine for nothing', async () => {
		apiFetch.mockResolvedValue( response() );
		render( <RulePreview body={ {} } hasPrice={ false } /> );
		expect( screen.getByRole( 'heading', { name: 'No price set yet' } ) ).toBeInTheDocument();
		await settle();
		expect( screen.getByRole( 'heading', { name: 'No price set yet' } ) ).toBeInTheDocument();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'previews a deliberate 0 price, whose body is identical to the blank one', async () => {
		apiFetch.mockResolvedValue( response( { total_matching: 36 } ) );
		const body = { simple: { value: 0 } };
		const { rerender } = render( <RulePreview body={ body } hasPrice={ false } /> );
		await settle();
		expect( apiFetch ).not.toHaveBeenCalled();

		rerender( <RulePreview body={ body } hasPrice /> );
		await settle();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByText( 'Products affected' ) ).toBeInTheDocument();
	} );

	it( 'spins rather than showing an empty card while the first request is in flight', async () => {
		apiFetch.mockReturnValue( pending() );
		const { container } = render( <RulePreview body={ {} } hasPrice /> );
		expect( container.querySelector( '.components-spinner' ) ).toBeInTheDocument();
		await settle();
		expect( container.querySelector( '.components-spinner' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'heading', { name: 'No products match this rule' } ) ).not.toBeInTheDocument();
	} );

	it( 'leads with the stats and follows with the table', async () => {
		apiFetch.mockResolvedValue( response( { total_matching: 36 } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByText( '36' ) ).toBeInTheDocument();
		expect( screen.getByText( '$5.00' ) ).toBeInTheDocument();

		const stats = screen.getByText( 'Products affected' );
		const table = screen.getByRole( 'region', { name: 'Resulting prices by product and reader segment' } );
		// eslint-disable-next-line no-bitwise
		expect( stats.compareDocumentPosition( table ) & Node.DOCUMENT_POSITION_FOLLOWING ).toBeTruthy();
	} );

	it( 'renders a capped total as a ceiling, since the engine counts the tail unchecked', async () => {
		apiFetch.mockResolvedValue( response( { total_matching: 480, count_limited: true } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByText( 'Up to 480' ) ).toBeInTheDocument();
	} );

	it( 'renders the stats and table for a partial preview', async () => {
		apiFetch.mockResolvedValue( response( { preview_limited: true, sample_count: 1, total_matching: 3 } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByText( 'Products affected' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'region', { name: 'Resulting prices by product and reader segment' } ) ).toBeInTheDocument();
	} );

	// The stat strip reports the total, so without this the table reads as the whole set.
	it( 'says the table is a sample when the preview was capped', async () => {
		apiFetch.mockResolvedValue( response( { preview_limited: true, sample_count: 50, total_matching: 120 } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByText( 'Showing a sample of 50 products.' ) ).toBeInTheDocument();
	} );

	it( 'says nothing about sampling when the whole set is shown', async () => {
		apiFetch.mockResolvedValue( response( { preview_limited: false, sample_count: 1, total_matching: 1 } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.queryByText( /Showing a sample of/ ) ).not.toBeInTheDocument();
	} );

	// The engine subtracts skipped products from the total, so a short sample below the
	// cap is a genuinely cut walk rather than the old false positive.
	it( 'says the table is a sample even when it stopped short of the cap', async () => {
		apiFetch.mockResolvedValue( response( { preview_limited: true, sample_count: 33, total_matching: 36 } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByText( 'Showing a sample of 33 products.' ) ).toBeInTheDocument();
	} );

	it( 'points at the scope when the rule matches no products', async () => {
		apiFetch.mockResolvedValue( response( { total_matching: 0, sample: [] } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByRole( 'heading', { name: 'No products match this rule' } ) ).toBeInTheDocument();
		expect( screen.queryByText( 'No products to show.' ) ).not.toBeInTheDocument();
	} );

	it( 'points at the scope when a matched sample comes back empty', async () => {
		apiFetch.mockResolvedValue( response( { total_matching: 480, sample: [] } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByRole( 'heading', { name: 'No products match this rule' } ) ).toBeInTheDocument();
		expect( screen.queryByText( 'No products to show.' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Products affected' ) ).not.toBeInTheDocument();
	} );

	it( 'says so when the engine returns no preview', async () => {
		apiFetch.mockResolvedValue( response( { supported: false } ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByRole( 'heading', { name: 'Preview unavailable' } ) ).toBeInTheDocument();
	} );

	it( 'says so when the request fails outright', async () => {
		apiFetch.mockRejectedValue( new Error( 'nope' ) );
		render( <RulePreview body={ {} } hasPrice /> );
		await settle();
		expect( screen.getByRole( 'heading', { name: 'Preview unavailable' } ) ).toBeInTheDocument();
	} );

	it( 'explains itself when a later edit fails instead of blanking the section', async () => {
		apiFetch.mockResolvedValueOnce( response( { total_matching: 36 } ) ).mockRejectedValue( new Error( 'nope' ) );
		const { rerender } = render( <RulePreview body={ { value: 5 } } hasPrice /> );
		await settle();
		expect( screen.getByText( '36' ) ).toBeInTheDocument();

		rerender( <RulePreview body={ { value: 6 } } hasPrice /> );
		await settle();
		expect( screen.getByRole( 'heading', { name: 'Preview unavailable' } ) ).toBeInTheDocument();
	} );

	it( 'keeps the resolved preview on screen while a later edit is in flight', async () => {
		apiFetch.mockResolvedValueOnce( response( { total_matching: 36 } ) ).mockReturnValue( pending() );
		const { rerender } = render( <RulePreview body={ { value: 5 } } hasPrice /> );
		await settle();
		expect( screen.getByText( '36' ) ).toBeInTheDocument();

		rerender( <RulePreview body={ { value: 6 } } hasPrice /> );
		await settle();
		expect( screen.getByText( '36' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'heading', { name: 'Preview unavailable' } ) ).not.toBeInTheDocument();
	} );

	it( 'swaps to the no-price card when the price is cleared after a preview lands', async () => {
		apiFetch.mockResolvedValue( response( { total_matching: 36 } ) );
		const { rerender } = render( <RulePreview body={ { value: 5 } } hasPrice /> );
		await settle();
		expect( screen.getByText( '36' ) ).toBeInTheDocument();

		rerender( <RulePreview body={ { value: '' } } hasPrice={ false } /> );
		expect( screen.getByRole( 'heading', { name: 'No price set yet' } ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Products affected' ) ).not.toBeInTheDocument();
	} );
} );
