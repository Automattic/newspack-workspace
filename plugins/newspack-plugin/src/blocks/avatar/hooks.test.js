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
import { usePostAuthors } from './hooks';
import { useCoAuthors } from '../../shared/hooks/use-coauthors';
import { useCustomByline } from '../../shared/hooks/use-custom-byline';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/core-data', () => ( {
	store: 'core',
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: str => str,
	sprintf: ( str, ...args ) => str.replace( /%s/g, () => args.shift() ),
} ) );

jest.mock( '../../shared/hooks/use-coauthors', () => ( {
	useCoAuthors: jest.fn(),
} ) );

jest.mock( '../../shared/hooks/use-custom-byline', () => ( {
	useCustomByline: jest.fn(),
	extractAuthorIdsFromByline: jest.requireActual( '../../shared/hooks/use-custom-byline' ).extractAuthorIdsFromByline,
} ) );

const DEFAULT_AVATAR_URL = 'https://example.com/default-avatar.png';

describe( 'usePostAuthors', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	const mockUserData = {
		1: { name: 'Jane Doe', avatar_urls: { 96: 'https://example.com/jane.jpg' } },
		2: { name: 'John Smith', avatar_urls: { 96: 'https://example.com/john.jpg' } },
	};

	/**
	 * Create a store-level mock for useSelect that dispatches based on the
	 * store being selected rather than relying on call order.
	 */
	const setupMocks = ( userData = mockUserData ) => {
		const stores = {
			'core/block-editor': {
				getSettings: () => ( {
					__experimentalDiscussionSettings: { avatarURL: DEFAULT_AVATAR_URL },
				} ),
			},
			core: {
				getUser: id => userData[ id ] || null,
			},
		};

		useSelect.mockImplementation( callback => {
			return callback( storeName => stores[ storeName ] || {} );
		} );
	};

	describe( 'custom byline with author shortcodes', () => {
		it( 'should return only authors from the byline when custom byline is active', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: true,
				bylineContent: 'By [Author id=1]Jane Doe[/Author]',
			} );
			useCoAuthors.mockReturnValue( {
				authors: [
					{ id: 1, display_name: 'Jane Doe' },
					{ id: 2, display_name: 'John Smith' },
				],
			} );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toHaveLength( 1 );
			expect( result.current[ 0 ].id ).toBe( 1 );
			expect( result.current[ 0 ].name ).toBe( 'Jane Doe' );
			expect( result.current[ 0 ].avatar_urls ).toEqual( { 96: 'https://example.com/jane.jpg' } );
			expect( result.current[ 0 ].avatarSrc ).toBe( 'https://example.com/jane.jpg' );
		} );

		it( 'should return multiple authors when byline has multiple shortcodes', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: true,
				bylineContent: 'By [Author id=1]Jane[/Author] and [Author id=2]John[/Author]',
			} );
			useCoAuthors.mockReturnValue( { authors: [] } );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toHaveLength( 2 );
			expect( result.current[ 0 ].id ).toBe( 1 );
			expect( result.current[ 1 ].id ).toBe( 2 );
		} );

		it( 'should show one avatar for mixed byline with one author shortcode and text', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: true,
				bylineContent: 'By [Author id=1]Jane[/Author] and the editorial team',
			} );
			useCoAuthors.mockReturnValue( { authors: [] } );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toHaveLength( 1 );
			expect( result.current[ 0 ].id ).toBe( 1 );
			expect( result.current[ 0 ].avatarSrc ).toBe( 'https://example.com/jane.jpg' );
		} );
	} );

	describe( 'custom byline without author shortcodes (plain text)', () => {
		it( 'should return empty array when byline is active but has no author shortcodes', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: true,
				bylineContent: 'By the editorial team',
			} );
			useCoAuthors.mockReturnValue( {
				authors: [
					{ id: 1, display_name: 'Jane Doe', user_nicename: 'jane-doe' },
					{ id: 2, display_name: 'John Smith', user_nicename: 'john-smith' },
				],
			} );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toEqual( [] );
		} );

		it( 'should return empty array for text-only byline like "By Staff Reporter"', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: true,
				bylineContent: 'By Staff Reporter',
			} );
			useCoAuthors.mockReturnValue( { authors: [] } );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toEqual( [] );
		} );
	} );

	describe( 'no custom byline', () => {
		it( 'should use CAP authors when custom byline is inactive', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: false,
				bylineContent: '',
			} );
			useCoAuthors.mockReturnValue( {
				authors: [ { id: 1, display_name: 'Jane Doe', author_link: '/author/jane/' } ],
			} );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toHaveLength( 1 );
			expect( result.current[ 0 ].display_name ).toBe( 'Jane Doe' );
			expect( result.current[ 0 ].author_link ).toBe( '/author/jane/' );
		} );

		it( 'should return empty array when no CAP authors and no custom byline', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: false,
				bylineContent: '',
			} );
			useCoAuthors.mockReturnValue( { authors: [] } );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toEqual( [] );
		} );
	} );

	describe( 'avatar data handling', () => {
		it( 'should resolve avatarSrc from avatar_urls for byline authors', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: true,
				bylineContent: '[Author id=1]Jane[/Author]',
			} );
			useCoAuthors.mockReturnValue( { authors: [] } );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current[ 0 ].avatar_urls ).toEqual( { 96: 'https://example.com/jane.jpg' } );
			expect( result.current[ 0 ].avatarSrc ).toBe( 'https://example.com/jane.jpg' );
		} );

		it( 'should filter out deleted users from byline authors', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: true,
				bylineContent: '[Author id=999]Unknown[/Author]',
			} );
			useCoAuthors.mockReturnValue( { authors: [] } );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toEqual( [] );
		} );

		it( 'should resolve avatarSrc from avatar_urls for CAP authors', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: false,
				bylineContent: '',
			} );
			useCoAuthors.mockReturnValue( {
				authors: [ { id: 1, display_name: 'Jane Doe' } ],
			} );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current[ 0 ].avatar_urls ).toEqual( { 96: 'https://example.com/jane.jpg' } );
			expect( result.current[ 0 ].avatarSrc ).toBe( 'https://example.com/jane.jpg' );
		} );

		it( 'should fall back to default avatar for CAP guest authors without WP user data', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: false,
				bylineContent: '',
			} );
			// Guest author with no WP user ID (id is 0 or falsy).
			useCoAuthors.mockReturnValue( {
				authors: [ { id: 0, display_name: 'Guest Writer', user_nicename: 'guest-writer' } ],
			} );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toHaveLength( 1 );
			expect( result.current[ 0 ].display_name ).toBe( 'Guest Writer' );
			expect( result.current[ 0 ].avatar_urls ).toBeNull();
			expect( result.current[ 0 ].avatarSrc ).toBe( DEFAULT_AVATAR_URL );
		} );

		it( 'should use avatar_urls from useCoAuthors for guest authors', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: false,
				bylineContent: '',
			} );
			// Guest author with avatar_urls provided by useCoAuthors (fetched from CAP REST API).
			useCoAuthors.mockReturnValue( {
				authors: [
					{
						id: 1591,
						display_name: 'Guest Writer',
						user_nicename: 'guest-writer',
						isGuest: true,
						avatar_urls: { 96: 'https://example.com/guest-avatar.jpg' },
					},
				],
			} );
			setupMocks();

			const { result } = renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( result.current ).toHaveLength( 1 );
			expect( result.current[ 0 ].display_name ).toBe( 'Guest Writer' );
			expect( result.current[ 0 ].avatarSrc ).toBe( 'https://example.com/guest-avatar.jpg' );
		} );

		it( 'should not call getUser for guest authors', () => {
			useCustomByline.mockReturnValue( {
				bylineActive: false,
				bylineContent: '',
			} );
			useCoAuthors.mockReturnValue( {
				authors: [
					{
						id: 1591,
						display_name: 'Guest Writer',
						user_nicename: 'guest-writer',
						isGuest: true,
						avatar_urls: { 96: 'https://example.com/guest-avatar.jpg' },
					},
				],
			} );

			const getUserMock = jest.fn( () => null );
			const stores = {
				'core/block-editor': {
					getSettings: () => ( {
						__experimentalDiscussionSettings: { avatarURL: DEFAULT_AVATAR_URL },
					} ),
				},
				core: {
					getUser: getUserMock,
				},
			};
			useSelect.mockImplementation( callback => {
				return callback( storeName => stores[ storeName ] || {} );
			} );

			renderHook( () => usePostAuthors( { postId: 123 } ) );

			expect( getUserMock ).not.toHaveBeenCalled();
		} );
	} );
} );
