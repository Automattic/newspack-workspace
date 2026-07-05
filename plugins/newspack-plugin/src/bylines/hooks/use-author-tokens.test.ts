/**
 * External dependencies
 */
import { renderHook } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { useAuthorTokens } from './use-author-tokens';
import { useCoAuthors } from '../../shared/hooks/use-coauthors';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/core-data', () => ( {
	store: 'core',
} ) );

jest.mock( '../../shared/hooks/use-coauthors', () => ( {
	useCoAuthors: jest.fn(),
} ) );

const mockUseCoAuthors = ( { authors = [], isCapAvailable = false, isLoading = false, hasCoauthorTermIds = false } = {} ) => {
	useCoAuthors.mockReturnValue( { authors, isCapAvailable, isLoading, hasCoauthorTermIds } );
};

/**
 * Mock `useSelect` for the hook's own post_author lookup.
 *
 * @param {Object}  options
 * @param {number}  options.authorId The author ID returned by `getEditedPostAttribute('author')`.
 * @param {?Object} options.user     User record returned by `getUser`, or null.
 */
const mockPostAuthorSelect = ( { authorId = 1, user = { id: 1, name: 'WP User' } } = {} ) => {
	useSelect.mockImplementation( callback => {
		return callback( storeName => {
			if ( storeName === 'core/editor' ) {
				return { getEditedPostAttribute: attr => ( attr === 'author' ? authorId : undefined ) };
			}
			if ( storeName === 'core' ) {
				return { getUser: id => ( id === authorId ? user : null ) };
			}
			return null;
		} );
	} );
};

describe( 'useAuthorTokens', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'should fall back to the post author when CAP is unavailable', () => {
		mockUseCoAuthors( { authors: [], isCapAvailable: false } );
		mockPostAuthorSelect( { authorId: 7, user: { id: 7, name: 'Post Author' } } );

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current ).toEqual( [ { id: 7, name: 'Post Author' } ] );
	} );

	it( 'should return an empty array when there is no coauthor and no post author', () => {
		mockUseCoAuthors( { authors: [], isCapAvailable: false } );
		mockPostAuthorSelect( { authorId: 0, user: null } );

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current ).toEqual( [] );
	} );

	it( 'should return WP user coauthors as tokens', () => {
		mockUseCoAuthors( {
			authors: [
				{ id: 1, display_name: 'Jane Doe', user_nicename: 'jane', isGuest: false },
				{ id: 2, display_name: 'John Smith', user_nicename: 'john', isGuest: false },
			],
			isCapAvailable: true,
		} );
		mockPostAuthorSelect();

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current ).toEqual( [
			{ id: 1, name: 'Jane Doe' },
			{ id: 2, name: 'John Smith' },
		] );
	} );

	it( 'should exclude guest authors from the token list', () => {
		mockUseCoAuthors( {
			authors: [
				{ id: 1, display_name: 'Jane Doe', user_nicename: 'jane', isGuest: false },
				{ id: 1591, display_name: 'Guest Writer', user_nicename: 'guest', isGuest: true },
			],
			isCapAvailable: true,
		} );
		mockPostAuthorSelect();

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current ).toEqual( [ { id: 1, name: 'Jane Doe' } ] );
	} );

	it( 'should return an empty list when the post is guest-only (no post_author fallback)', () => {
		// A guest-authored post must not seed the custom byline with the unrelated post_author.
		mockUseCoAuthors( {
			authors: [ { id: 1591, display_name: 'Guest', user_nicename: 'guest', isGuest: true } ],
			isCapAvailable: true,
		} );
		mockPostAuthorSelect( { authorId: 5, user: { id: 5, name: 'Post Author' } } );

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current ).toEqual( [] );
	} );

	it( 'should return empty while coauthor data is still loading (new-CAP async window)', () => {
		// During the term-ID resolution window, fall back to post_author would write a
		// truncated byline. Return empty so `insertDefaultByline` is a no-op.
		mockUseCoAuthors( { authors: [], isCapAvailable: true, isLoading: true, hasCoauthorTermIds: true } );
		mockPostAuthorSelect( { authorId: 5, user: { id: 5, name: 'Post Author' } } );

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current ).toEqual( [] );
	} );

	it( 'should return empty when CAP has term IDs assigned but resolution returned no authors', () => {
		// Mirrors the 403 / transient-error path: the `authors-by-term-ids` REST endpoint
		// requires `edit_others_posts`, so an author editing their own post sees an empty
		// resolved list. Falling back to `post_author` would credit the wrong person.
		mockUseCoAuthors( { authors: [], isCapAvailable: true, isLoading: false, hasCoauthorTermIds: true } );
		mockPostAuthorSelect( { authorId: 5, user: { id: 5, name: 'Post Author' } } );

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current ).toEqual( [] );
	} );

	it( 'should coerce string ids from CAP to integer', () => {
		// New CAP returns `id` as a string ("25"). Tokens must be integers for the shortcode.
		mockUseCoAuthors( {
			authors: [ { id: '25', display_name: 'Boba Fett', user_nicename: 'boba-fett', isGuest: false } ],
			isCapAvailable: true,
		} );
		mockPostAuthorSelect();

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current[ 0 ].id ).toBe( 25 );
		expect( typeof result.current[ 0 ].id ).toBe( 'number' );
	} );

	it( 'should dedupe duplicate WP user coauthors by id', () => {
		// Defensive: if the upstream store accidentally has duplicates (seen in a
		// legacy-CAP resolver race), the token list should still contain each id once.
		mockUseCoAuthors( {
			authors: [
				{ id: 1, display_name: 'wordpress', user_nicename: 'wordpress', isGuest: false },
				{ id: 25, display_name: 'Boba Fett', user_nicename: 'boba-fett', isGuest: false },
				{ id: 1, display_name: 'wordpress', user_nicename: 'wordpress', isGuest: false },
			],
			isCapAvailable: true,
		} );
		mockPostAuthorSelect();

		const { result } = renderHook( () => useAuthorTokens( 123 ) );

		expect( result.current ).toEqual( [
			{ id: 1, name: 'wordpress' },
			{ id: 25, name: 'Boba Fett' },
		] );
	} );
} );
