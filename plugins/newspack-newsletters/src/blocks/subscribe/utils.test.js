/**
 * Internal dependencies
 */
import { hasGatedLists, isListGated, moveItem, toggleList } from './utils';

describe( 'moveItem', () => {
	const lists = [ 'a', 'b', 'c', 'd' ];

	it( 'moves an item down', () => {
		expect( moveItem( lists, 0, 2 ) ).toEqual( [ 'b', 'c', 'a', 'd' ] );
	} );

	it( 'moves an item up', () => {
		expect( moveItem( lists, 3, 1 ) ).toEqual( [ 'a', 'd', 'b', 'c' ] );
	} );

	it( 'moves an item to the end', () => {
		expect( moveItem( lists, 1, 3 ) ).toEqual( [ 'a', 'c', 'd', 'b' ] );
	} );

	it( 'does not mutate its input', () => {
		moveItem( lists, 0, 3 );
		expect( lists ).toEqual( [ 'a', 'b', 'c', 'd' ] );
	} );

	it( 'returns the input unchanged for a no-op or out-of-range move', () => {
		expect( moveItem( lists, 1, 1 ) ).toBe( lists );
		expect( moveItem( lists, -1, 2 ) ).toBe( lists );
		expect( moveItem( lists, 2, -1 ) ).toBe( lists );
		expect( moveItem( lists, 0, 4 ) ).toBe( lists );
		expect( moveItem( lists, 4, 0 ) ).toBe( lists );
		expect( moveItem( lists, 9, 0 ) ).toBe( lists );
	} );
} );

describe( 'toggleList', () => {
	it( 'appends a list that is not enabled', () => {
		expect( toggleList( [ 'a', 'b' ], 'c' ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'removes a list that is enabled, keeping the order of the rest', () => {
		expect( toggleList( [ 'a', 'b', 'c' ], 'b' ) ).toEqual( [ 'a', 'c' ] );
	} );
} );

describe( 'isListGated', () => {
	const listGates = { a: [ { id: 1, title: 'Members' } ], b: [] };

	it( 'is true for a list with at least one gate', () => {
		expect( isListGated( listGates, 'a' ) ).toBe( true );
	} );

	it( 'is false for a list with no gates, a missing list, or no map', () => {
		expect( isListGated( listGates, 'b' ) ).toBe( false );
		expect( isListGated( listGates, 'c' ) ).toBe( false );
		expect( isListGated( undefined, 'a' ) ).toBe( false );
		expect( isListGated( [], 'a' ) ).toBe( false );
	} );
} );

describe( 'hasGatedLists', () => {
	it( 'is true when any list has a gate', () => {
		expect( hasGatedLists( { a: [], b: [ { id: 1, title: 'Members' } ] } ) ).toBe( true );
	} );

	it( 'is false for an empty or missing map', () => {
		expect( hasGatedLists( {} ) ).toBe( false );
		expect( hasGatedLists( { a: [] } ) ).toBe( false );
		expect( hasGatedLists( undefined ) ).toBe( false );
		expect( hasGatedLists( [] ) ).toBe( false );
	} );
} );
