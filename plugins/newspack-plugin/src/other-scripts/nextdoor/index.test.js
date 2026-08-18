/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor, act, within } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import NextdoorPostSidebarPlugin from './index';

let mockPostId = 1;
let mockPostStatus = 'publish';

// Only the editor selector is stood in for: the rest of the module is what
// `@wordpress/components` builds its own stores on. Proxied rather than spread, since
// spreading reads every export while the module is still initialising.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );
	return new Proxy( actual, {
		get: ( target, prop ) => ( 'useSelect' === prop ? () => ( { postId: mockPostId, postStatus: mockPostStatus } ) : target[ prop ] ),
	} );
} );

jest.mock( '@wordpress/plugins', () => ( { registerPlugin: jest.fn() } ) );

jest.mock( '@wordpress/editor', () => {
	const { createElement } = require( '@wordpress/element' );
	return { PluginSidebar: ( { children } ) => createElement( 'div', null, children ) };
} );

jest.mock( '@wordpress/date', () => ( {
	dateI18n: () => '1 January 2026',
	getSettings: () => ( { formats: { datetimeAbbreviated: 'Y-m-d g:i a' } } ),
} ) );

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ) );

const UNSHARED = {
	is_shared: false,
	is_deleted: false,
	can_publish: true,
	needs_reconnect: false,
	needs_setup: false,
	is_unreachable: false,
	is_published: true,
	ingestion_errors: [],
};

/**
 * A promise the test resolves when it wants the request to come back.
 *
 * @return {{promise: Promise, resolve: Function}} The promise and its resolver.
 */
const pending = () => {
	let resolve;
	const promise = new Promise( r => {
		resolve = r;
	} );
	return { promise, resolve };
};

/**
 * Render the sidebar on a post whose status has already arrived.
 *
 * @return {Object} The render result.
 */
const openOnAPublishedPost = async () => {
	apiFetch.mockResolvedValue( UNSHARED );
	const result = render( <NextdoorPostSidebarPlugin /> );
	await waitFor( () => expect( screen.getByRole( 'button', { name: 'Publish on Nextdoor' } ) ).toBeInTheDocument() );
	return result;
};

beforeEach( () => {
	jest.clearAllMocks();
	mockPostId = 1;
	mockPostStatus = 'publish';
} );

describe( 'Nextdoor post sidebar', () => {
	it( 'publishes the post it was pressed on', async () => {
		const { container } = await openOnAPublishedPost();

		apiFetch.mockResolvedValueOnce( { success: true, message: 'Published to Nextdoor.' } );
		// A success is followed by a fresh status read, which is what the panel renders.
		apiFetch.mockResolvedValue( { ...UNSHARED, is_shared: true, guid: 'guid-1', ingestion_status: 'valid' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Publish on Nextdoor' } ) );

		await waitFor( () => expect( within( container ).getByText( 'Published to Nextdoor.' ) ).toBeInTheDocument() );
		expect( apiFetch ).toHaveBeenCalledWith( { path: '/newspack/v1/nextdoor/publish-post/1', method: 'POST' } );
	} );

	it( 'releases the action when the editor moved on mid-request', async () => {
		const { rerender } = await openOnAPublishedPost();

		const publish = pending();
		apiFetch.mockReturnValueOnce( publish.promise );
		fireEvent.click( screen.getByRole( 'button', { name: 'Publish on Nextdoor' } ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Publishing…' } ) ).toBeInTheDocument() );

		// The editor moves to another post while the request is still out.
		mockPostId = 2;
		apiFetch.mockResolvedValue( UNSHARED );
		rerender( <NextdoorPostSidebarPlugin /> );

		await act( async () => {
			publish.resolve( { success: true, message: 'Published to Nextdoor.' } );
		} );

		// The action was set before the request went out and nothing else clears it, so a
		// discarded answer that also skipped releasing it would wedge the new post's
		// button as busy for the life of the editor.
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Publish on Nextdoor' } ) ).toBeInTheDocument() );
		expect( screen.queryByRole( 'button', { name: 'Publishing…' } ) ).not.toBeInTheDocument();
	} );

	it( 'does not show one post its answer for another', async () => {
		const { rerender, container } = await openOnAPublishedPost();

		const publish = pending();
		apiFetch.mockReturnValueOnce( publish.promise );
		fireEvent.click( screen.getByRole( 'button', { name: 'Publish on Nextdoor' } ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Publishing…' } ) ).toBeInTheDocument() );

		mockPostId = 2;
		apiFetch.mockResolvedValue( UNSHARED );
		rerender( <NextdoorPostSidebarPlugin /> );

		await act( async () => {
			publish.resolve( { success: true, message: 'Published to Nextdoor.' } );
		} );

		expect( within( container ).queryByText( 'Published to Nextdoor.' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps a failure for the post it describes', async () => {
		const { rerender, container } = await openOnAPublishedPost();

		const publish = pending();
		apiFetch.mockReturnValueOnce( publish.promise );
		fireEvent.click( screen.getByRole( 'button', { name: 'Publish on Nextdoor' } ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Publishing…' } ) ).toBeInTheDocument() );

		mockPostId = 2;
		apiFetch.mockResolvedValue( UNSHARED );
		rerender( <NextdoorPostSidebarPlugin /> );

		await act( async () => {
			publish.resolve( { success: false, message: 'Failed to publish.' } );
		} );

		expect( within( container ).queryByText( 'Failed to publish.' ) ).not.toBeInTheDocument();
	} );

	it( 'tells the publisher the post has to be published first', async () => {
		mockPostStatus = 'draft';

		apiFetch.mockResolvedValue( UNSHARED );
		const { container } = render( <NextdoorPostSidebarPlugin /> );

		await waitFor( () => expect( within( container ).getByText( 'Post must be published before sharing to Nextdoor.' ) ).toBeInTheDocument() );
	} );

	it( 'offers a reconnection when the connection has stopped working', async () => {
		apiFetch.mockResolvedValue( { ...UNSHARED, needs_reconnect: true, can_reconnect: true } );
		const { container } = render( <NextdoorPostSidebarPlugin /> );

		await waitFor( () =>
			expect( within( container ).getByText( 'Sharing to Nextdoor is unavailable until you reconnect.' ) ).toBeInTheDocument()
		);
		expect( screen.getByRole( 'link', { name: 'Reconnect Nextdoor' } ) ).toBeInTheDocument();
	} );
} );
