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
import { useDefaultAuthor } from './use-default-author';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/core-data', () => ( {
	store: 'core',
} ) );

describe( 'useDefaultAuthor', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'should return null author when post has no author ID', () => {
		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => ( { author: null } ),
				getUser: jest.fn(),
				hasFinishedResolution: jest.fn(),
			} ) )
		);

		const { result } = renderHook( () => useDefaultAuthor( 123, 'post' ) );

		expect( result.current.authorDetails ).toBeNull();
		expect( result.current.isLoading ).toBe( false );
	} );

	it( 'should return loading true while fetching user', () => {
		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => ( { author: 5 } ),
				getUser: () => null,
				hasFinishedResolution: () => false,
			} ) )
		);

		const { result } = renderHook( () => useDefaultAuthor( 123, 'post' ) );

		expect( result.current.authorDetails ).toBeNull();
		expect( result.current.isLoading ).toBe( true );
	} );

	it( 'should return author details when resolved', () => {
		const mockUser = { id: 5, name: 'Jane Doe', slug: 'jane-doe' };

		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => ( { author: 5 } ),
				getUser: () => mockUser,
				hasFinishedResolution: () => true,
			} ) )
		);

		const { result } = renderHook( () => useDefaultAuthor( 123, 'post' ) );

		expect( result.current.authorDetails ).toEqual( mockUser );
		expect( result.current.isLoading ).toBe( false );
	} );

	it( 'should pass postType and postId to getEditedEntityRecord', () => {
		const getEditedEntityRecord = jest.fn( () => ( { author: 10 } ) );

		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord,
				getUser: () => ( { id: 10, name: 'Author' } ),
				hasFinishedResolution: () => true,
			} ) )
		);

		renderHook( () => useDefaultAuthor( 456, 'page' ) );

		expect( getEditedEntityRecord ).toHaveBeenCalledWith( 'postType', 'page', 456 );
	} );

	it( 'should handle null entity record gracefully', () => {
		const getUser = jest.fn();
		const hasFinishedResolution = jest.fn();

		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => null,
				getUser,
				hasFinishedResolution,
			} ) )
		);

		const { result } = renderHook( () => useDefaultAuthor( 123, 'post' ) );

		expect( result.current.authorDetails ).toBeNull();
		expect( result.current.isLoading ).toBe( false );
		expect( getUser ).not.toHaveBeenCalled();
		expect( hasFinishedResolution ).not.toHaveBeenCalled();
	} );

	it( 'should handle undefined entity record gracefully', () => {
		const getUser = jest.fn();
		const hasFinishedResolution = jest.fn();

		useSelect.mockImplementation( callback =>
			callback( () => ( {
				getEditedEntityRecord: () => undefined,
				getUser,
				hasFinishedResolution,
			} ) )
		);

		const { result } = renderHook( () => useDefaultAuthor( 123, 'post' ) );

		expect( result.current.authorDetails ).toBeNull();
		expect( result.current.isLoading ).toBe( false );
		expect( getUser ).not.toHaveBeenCalled();
		expect( hasFinishedResolution ).not.toHaveBeenCalled();
	} );
} );
