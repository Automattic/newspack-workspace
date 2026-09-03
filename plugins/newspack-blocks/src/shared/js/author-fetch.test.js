import apiFetch from '@wordpress/api-fetch';
import { getQueryArgs } from '@wordpress/url';

import { fetchAuthorById, fetchAuthorList, resetAuthorFetchCache } from './author-fetch';

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ) );

const FIELDS = 'id,name,bio';

const user = ( id, name ) => ( { id, is_guest: false, name } );
const guest = ( id, name ) => ( { id, is_guest: true, name } );

/**
 * Let the batching window elapse and the resulting promises settle.
 */
const flush = async () => {
	await jest.advanceTimersByTimeAsync( 100 );
};

const argsOf = call => getQueryArgs( call[ 0 ].path );

beforeEach( () => {
	jest.useFakeTimers();
	apiFetch.mockReset();
	resetAuthorFetchCache();
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'fetchAuthorById', () => {
	it( 'coalesces lookups made in the same window into one request', async () => {
		apiFetch.mockResolvedValue( [ user( 1, 'One' ), user( 2, 'Two' ), guest( 5, 'Five' ) ] );

		const lookups = Promise.all( [
			fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: FIELDS } ),
			fetchAuthorById( { authorId: 2, isGuestAuthor: false, fields: FIELDS } ),
			fetchAuthorById( { authorId: 5, isGuestAuthor: true, fields: FIELDS } ),
		] );
		await flush();
		await lookups;

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( argsOf( apiFetch.mock.calls[ 0 ] ) ).toEqual( {
			author_ids: '1,2',
			guest_author_ids: '5',
			fields: FIELDS,
		} );
	} );

	it( 'resolves each caller with its own author', async () => {
		apiFetch.mockResolvedValue( [ user( 1, 'One' ), guest( 1, 'Guest One' ) ] );

		const lookups = Promise.all( [
			fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: FIELDS } ),
			fetchAuthorById( { authorId: 1, isGuestAuthor: true, fields: FIELDS } ),
		] );
		await flush();
		const [ asUser, asGuest ] = await lookups;

		expect( asUser ).toEqual( user( 1, 'One' ) );
		expect( asGuest ).toEqual( guest( 1, 'Guest One' ) );
	} );

	it( 'resolves undefined for an author the endpoint did not return', async () => {
		apiFetch.mockResolvedValue( [ user( 1, 'One' ) ] );

		const lookup = fetchAuthorById( { authorId: 404, isGuestAuthor: false, fields: FIELDS } );
		await flush();

		await expect( lookup ).resolves.toBeUndefined();
	} );

	it( 'keeps lookups with different fields or avatar options in separate requests', async () => {
		apiFetch.mockResolvedValue( [ user( 1, 'One' ) ] );

		const lookups = Promise.all( [
			fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: FIELDS } ),
			fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: 'id,name' } ),
			fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: FIELDS, avatarHideDefault: true } ),
		] );
		await flush();
		await lookups;

		expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		expect( argsOf( apiFetch.mock.calls[ 2 ] ) ).toMatchObject( { avatar_hide_default: '1' } );
	} );

	it( 'serves a repeated lookup from cache without a new request', async () => {
		apiFetch.mockResolvedValue( [ user( 1, 'One' ) ] );

		const first = fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: FIELDS } );
		await flush();
		await first;

		const second = fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: FIELDS } );
		await flush();

		await expect( second ).resolves.toEqual( user( 1, 'One' ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'rejects every caller when the request fails and retries on the next lookup', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'The response is not a valid JSON response.' ) );

		const first = fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: FIELDS } );
		const second = fetchAuthorById( { authorId: 2, isGuestAuthor: false, fields: FIELDS } );
		first.catch( () => {} );
		second.catch( () => {} );
		await flush();

		await expect( first ).rejects.toThrow( 'not a valid JSON response' );
		await expect( second ).rejects.toThrow( 'not a valid JSON response' );

		apiFetch.mockResolvedValue( [ user( 1, 'One' ) ] );
		const retry = fetchAuthorById( { authorId: 1, isGuestAuthor: false, fields: FIELDS } );
		await flush();

		await expect( retry ).resolves.toEqual( user( 1, 'One' ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );
} );

describe( 'fetchAuthorList', () => {
	const response = ( authors, total ) => ( {
		headers: { get: header => ( header === 'x-wp-total' ? String( total ) : null ) },
		json: async () => authors,
	} );

	it( 'returns the authors and the total from the response headers', async () => {
		apiFetch.mockResolvedValue( response( [ user( 1, 'One' ) ], 42 ) );

		await expect( fetchAuthorList( { search: '', offset: 0, fields: 'id,name' } ) ).resolves.toEqual( {
			authors: [ user( 1, 'One' ) ],
			total: 42,
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( { parse: false } );
		expect( argsOf( apiFetch.mock.calls[ 0 ] ) ).toEqual( { search: '', offset: '0', fields: 'id,name' } );
	} );

	it( 'shares one request between identical concurrent lookups', async () => {
		apiFetch.mockResolvedValue( response( [ user( 1, 'One' ) ], 1 ) );

		const [ a, b ] = await Promise.all( [
			fetchAuthorList( { search: '', offset: 0, fields: 'id,name' } ),
			fetchAuthorList( { search: '', offset: 0, fields: 'id,name' } ),
		] );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( a ).toEqual( b );
	} );

	it( 'requests again when the search differs', async () => {
		apiFetch.mockResolvedValue( response( [], 0 ) );

		await fetchAuthorList( { search: '', offset: 0, fields: 'id,name' } );
		await fetchAuthorList( { search: 'jo', offset: 0, fields: 'id,name' } );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );
} );
