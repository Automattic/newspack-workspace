/**
 * External dependencies
 */
import { render, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { getQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import QueryControls from './query-controls';

jest.mock( '@wordpress/api-fetch' );

const fetchSavedPosts = ( postIDs, posts ) => {
	apiFetch.mockResolvedValue( posts );
	return new QueryControls( { postType: 'post' } ).fetchSavedPosts( postIDs );
};

describe( 'fetchSavedPosts', () => {
	beforeAll( () => {
		window.newspack_blocks_data = { posts_rest_url: 'https://example.test/wp-json/newspack-blocks/v1/newspack-blocks-posts' };
	} );

	beforeEach( () => {
		apiFetch.mockClear();
	} );

	it( 'labels the content the endpoint returned', async () => {
		await expect( fetchSavedPosts( [ 11 ], [ { id: 11, title: { rendered: 'Alpha' } } ] ) ).resolves.toEqual( [ { value: 11, label: 'Alpha' } ] );
	} );

	// Without an entry the token field renders no token for the ID, and the next
	// selection writes the attribute back without it.
	it( 'keeps an entry for an ID the endpoint did not return', async () => {
		await expect( fetchSavedPosts( [ 11, 22 ], [ { id: 11, title: { rendered: 'Alpha' } } ] ) ).resolves.toEqual( [
			{ value: 11, label: 'Alpha' },
			{ value: 22, label: 'Unavailable content (22)' },
		] );
	} );

	// A label is matched back to its ID, so two unavailable items sharing one
	// would collapse onto a single ID.
	it( 'tells two unavailable IDs apart', async () => {
		const posts = await fetchSavedPosts( [ 22, 33 ], [] );
		expect( posts.map( post => post.label ) ).toEqual( [ 'Unavailable content (22)', 'Unavailable content (33)' ] );
	} );

	it( 'still marks the content unavailable when the title is empty', async () => {
		const posts = await fetchSavedPosts( [ 11, 22 ], [ { id: 11, title: { rendered: '' } } ] );
		expect( posts.map( post => post.label ) ).toEqual( [ '(no title)', 'Unavailable content (22)' ] );
	} );

	it( 'asks for nothing when nothing is saved', async () => {
		await expect( fetchSavedPosts( [], [] ) ).resolves.toEqual( [] );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'Static mode lookups', () => {
	beforeAll( () => {
		window.newspack_blocks_data = {
			posts_rest_url: 'https://example.test/wp-json/newspack-blocks/v1/newspack-blocks-posts',
			specific_posts_rest_url: 'https://example.test/wp-json/newspack-blocks/v1/newspack-blocks-specific-posts',
		};
	} );

	it( 'asks for the statuses ticked on the block, so drafts can be found and reloaded', async () => {
		apiFetch.mockClear();
		apiFetch.mockResolvedValue( [] );
		const controls = new QueryControls( { postType: 'post', includedPostStatuses: [ 'publish', 'draft' ] } );

		await controls.fetchPostSuggestions( 'Alpha' );
		await controls.fetchSavedPosts( [ 11 ] );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		apiFetch.mock.calls.forEach( ( [ { url } ] ) => {
			expect( getQueryArgs( url ).includedPostStatuses ).toEqual( [ 'publish', 'draft' ] );
		} );
	} );

	it( 'passes the status of each post to the token field, to tell same-title posts apart', async () => {
		const controls = new QueryControls( { postType: 'post' } );

		apiFetch.mockResolvedValue( [ { id: 11, title: 'Alpha', post_status: 'draft' } ] );
		await expect( controls.fetchPostSuggestions( 'Alpha' ) ).resolves.toEqual( [ { value: 11, label: 'Alpha', clashNote: 'Draft' } ] );

		apiFetch.mockResolvedValue( [ { id: 11, title: { rendered: 'Alpha' }, post_status: 'future' } ] );
		await expect( controls.fetchSavedPosts( [ 11 ] ) ).resolves.toEqual( [ { value: 11, label: 'Alpha', clashNote: 'Scheduled' } ] );
	} );

	// Ticking Draft after a draft was selected must turn its "Unavailable content" token back into a title.
	it( 'reloads the saved content when the statuses change', async () => {
		apiFetch.mockClear();
		apiFetch.mockResolvedValue( [] );
		const controlsWith = includedPostStatuses => (
			<QueryControls specificMode postType="post" specificPosts={ [ 11 ] } includedPostStatuses={ includedPostStatuses } />
		);

		const { rerender } = render( controlsWith( [ 'publish' ] ) );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		rerender( controlsWith( [ 'publish', 'draft' ] ) );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );

		expect( getQueryArgs( apiFetch.mock.calls[ 1 ][ 0 ].url ).includedPostStatuses ).toEqual( [ 'publish', 'draft' ] );
	} );
} );
