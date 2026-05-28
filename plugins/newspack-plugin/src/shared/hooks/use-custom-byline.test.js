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
import { useCustomByline, extractAuthorIdsFromByline } from './use-custom-byline';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/core-data', () => ( {
	store: 'core',
} ) );

describe( 'useCustomByline', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'should return inactive byline when meta is not set', () => {
		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => ( {} ),
			} ) )
		);

		const { result } = renderHook( () => useCustomByline( 123, 'post' ) );

		expect( result.current.bylineActive ).toBe( false );
		expect( result.current.bylineContent ).toBe( '' );
	} );

	it( 'should return inactive byline when meta exists but byline is inactive', () => {
		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => ( {
					meta: {
						_newspack_byline_active: false,
						_newspack_byline: 'Some content',
					},
				} ),
			} ) )
		);

		const { result } = renderHook( () => useCustomByline( 123, 'post' ) );

		expect( result.current.bylineActive ).toBe( false );
		expect( result.current.bylineContent ).toBe( 'Some content' );
	} );

	it( 'should return active byline with content', () => {
		const bylineContent = 'By [Author id=5]Jane Doe[/Author]';

		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => ( {
					meta: {
						_newspack_byline_active: true,
						_newspack_byline: bylineContent,
					},
				} ),
			} ) )
		);

		const { result } = renderHook( () => useCustomByline( 123, 'post' ) );

		expect( result.current.bylineActive ).toBe( true );
		expect( result.current.bylineContent ).toBe( bylineContent );
	} );

	it( 'should handle null post record gracefully', () => {
		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => null,
			} ) )
		);

		const { result } = renderHook( () => useCustomByline( 123, 'post' ) );

		expect( result.current.bylineActive ).toBe( false );
		expect( result.current.bylineContent ).toBe( '' );
	} );
} );

describe( 'extractAuthorIdsFromByline', () => {
	it( 'should return empty array for empty content', () => {
		expect( extractAuthorIdsFromByline( '' ) ).toEqual( [] );
		expect( extractAuthorIdsFromByline( null ) ).toEqual( [] );
		expect( extractAuthorIdsFromByline( undefined ) ).toEqual( [] );
	} );

	it( 'should extract single author ID', () => {
		const byline = 'By [Author id=5]Jane Doe[/Author]';
		expect( extractAuthorIdsFromByline( byline ) ).toEqual( [ 5 ] );
	} );

	it( 'should extract multiple author IDs', () => {
		const byline = 'By [Author id=5]Jane Doe[/Author] and [Author id=12]John Smith[/Author]';
		expect( extractAuthorIdsFromByline( byline ) ).toEqual( [ 5, 12 ] );
	} );

	it( 'should handle content with no author shortcodes', () => {
		const byline = 'By Some Random Text';
		expect( extractAuthorIdsFromByline( byline ) ).toEqual( [] );
	} );

	it( 'should handle byline with HTML entities', () => {
		const byline = 'By&nbsp;[Author id=17]Darth Vader[/Author]&nbsp;with love';
		expect( extractAuthorIdsFromByline( byline ) ).toEqual( [ 17 ] );
	} );

	it( 'should return integers, not strings', () => {
		const byline = '[Author id=123]Test[/Author]';
		const result = extractAuthorIdsFromByline( byline );
		expect( result ).toEqual( [ 123 ] );
		expect( typeof result[ 0 ] ).toBe( 'number' );
	} );

	it( 'should tolerate whitespace and case variations in shortcode', () => {
		expect( extractAuthorIdsFromByline( '[author id=7]Name[/Author]' ) ).toEqual( [ 7 ] );
		expect( extractAuthorIdsFromByline( '[Author  id = 5]Name[/Author]' ) ).toEqual( [ 5 ] );
		expect( extractAuthorIdsFromByline( '[AUTHOR ID=3]Name[/Author]' ) ).toEqual( [ 3 ] );
	} );
} );
