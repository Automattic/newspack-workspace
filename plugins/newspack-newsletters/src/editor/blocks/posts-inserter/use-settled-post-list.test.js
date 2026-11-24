import { renderHook } from '@testing-library/react';

import useSettledPostList from './use-settled-post-list';

const FIRST_POSTS = [ { id: 1 }, { id: 2 } ];
const SECOND_POSTS = [ { id: 3 } ];

const renderSettledPostList = initialProps =>
	renderHook( ( { postList, isLoading } ) => useSettledPostList( postList, isLoading ), { initialProps } );

describe( 'useSettledPostList', () => {
	it( 'returns the posts of a loaded query', () => {
		const { result } = renderSettledPostList( { postList: FIRST_POSTS, isLoading: false } );

		expect( result.current ).toBe( FIRST_POSTS );
	} );

	it( 'returns no posts while the first query loads', () => {
		const { result } = renderSettledPostList( { postList: [], isLoading: true } );

		expect( result.current ).toEqual( [] );
	} );

	it( 'keeps the loaded posts while a changed query loads, so the preview is not rebuilt', () => {
		const { result, rerender } = renderSettledPostList( { postList: FIRST_POSTS, isLoading: false } );

		rerender( { postList: [], isLoading: true } );
		expect( result.current ).toBe( FIRST_POSTS );

		rerender( { postList: SECOND_POSTS, isLoading: false } );
		expect( result.current ).toBe( SECOND_POSTS );
	} );

	it( 'returns no posts once a changed query loads empty', () => {
		const { result, rerender } = renderSettledPostList( { postList: FIRST_POSTS, isLoading: false } );

		rerender( { postList: [], isLoading: true } );
		rerender( { postList: [], isLoading: false } );

		expect( result.current ).toEqual( [] );
	} );
} );
