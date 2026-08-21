/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

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
