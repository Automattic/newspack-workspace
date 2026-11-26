/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { getQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import CategoryAutocomplete from './';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const TAGS = [
	{ id: 3, name: 'Politics' },
	{ id: 7, name: 'Elections' },
	{ id: 9, name: 'Weather' },
];

const SPINNER = '.newspack-category-autocomplete__suggestions-spinner';

const requestedQueries = () => apiFetch.mock.calls.map( ( [ { path } ] ) => getQueryArgs( path ) );

// The spinner goes once the pending requests have settled.
const waitForRequests = () => waitFor( () => expect( document.querySelector( SPINNER ) ).toBeNull() );

const renderTags = value => render( <CategoryAutocomplete taxonomy="tags" value={ value } onChange={ jest.fn() } label="Tags" /> );

describe( 'CategoryAutocomplete', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		// Answers like the terms endpoint: only the terms named in `include`, or every term without it.
		apiFetch.mockImplementation( ( { path } ) => {
			const { include } = getQueryArgs( path );
			const ids = include ? include.split( ',' ).map( Number ) : TAGS.map( tag => tag.id );
			return Promise.resolve( TAGS.filter( tag => ids.includes( tag.id ) ) );
		} );
	} );

	it( 'requests only the saved IDs and shows their names', async () => {
		renderTags( [ 3, 7 ] );
		expect( await screen.findByText( 'Politics' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Elections' ) ).toBeInTheDocument();
		await waitForRequests();
		expect( screen.queryByText( 'Weather' ) ).not.toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toMatch( /^\/wp\/v2\/tags\?/ );
		expect( requestedQueries()[ 0 ] ).toMatchObject( { include: '3,7', per_page: '100' } );
	} );

	it( 'sends no request when no numeric ID is saved', () => {
		render( <CategoryAutocomplete value={ [] } onChange={ jest.fn() } label="Categories" /> );
		render( <CategoryAutocomplete value={ [ { id: 5, name: 'Sports' } ] } onChange={ jest.fn() } label="Segments" /> );
		expect( screen.getByText( 'Sports' ) ).toBeInTheDocument();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'requests only IDs it has not resolved yet', async () => {
		const { rerender } = renderTags( [ 3 ] );
		await screen.findByText( 'Politics' );
		await waitForRequests();

		rerender( <CategoryAutocomplete taxonomy="tags" value={ [ 3 ] } onChange={ jest.fn() } label="Tags" /> );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		rerender( <CategoryAutocomplete taxonomy="tags" value={ [ 3, 9 ] } onChange={ jest.fn() } label="Tags" /> );
		expect( await screen.findByText( 'Weather' ) ).toBeInTheDocument();
		await waitForRequests();
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( requestedQueries()[ 1 ] ).toMatchObject( { include: '9' } );
	} );

	it( 'does not request a missing term again', async () => {
		const { rerender } = renderTags( [ 404 ] );
		await waitForRequests();

		rerender( <CategoryAutocomplete taxonomy="tags" value={ [ 404, 3 ] } onChange={ jest.fn() } label="Tags" /> );
		await screen.findByText( 'Politics' );
		await waitForRequests();
		expect( requestedQueries().map( query => query.include ) ).toEqual( [ '404', '3' ] );
	} );

	it( 'splits more than 100 saved IDs across requests', async () => {
		renderTags( Array.from( { length: 150 }, ( _, index ) => index + 1 ) );
		await waitForRequests();
		expect( requestedQueries().map( query => query.include.split( ',' ).length ) ).toEqual( [ 100, 50 ] );
	} );
} );
