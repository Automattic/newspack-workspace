/**
 * Internal dependencies
 */
import { enabledInOrder, hasGatedLists, isListGated, moveItem, syncListOrder } from './utils';

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

describe( 'syncListOrder', () => {
	it( 'builds the order on first load: lists that are on first, in form order, then the rest', () => {
		expect( syncListOrder( [], [ 'c', 'a' ], [ 'a', 'b', 'c' ] ) ).toEqual( [ 'c', 'a', 'b' ] );
	} );

	it( 'returns the same array when it agrees with the block', () => {
		const order = [ 'a', 'b', 'c' ];
		expect( syncListOrder( order, [ 'a', 'c' ], [ 'a', 'b', 'c' ] ) ).toBe( order );
	} );

	it( 'keeps a list that is off between two that are on', () => {
		expect( syncListOrder( [ 'a', 'b', 'c' ], [ 'a', 'c' ], [ 'a', 'b', 'c' ] ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'rebuilds when the lists that are on come in a different order', () => {
		expect( syncListOrder( [ 'a', 'b', 'c' ], [ 'c', 'a' ], [ 'a', 'b', 'c' ] ) ).toEqual( [ 'c', 'a', 'b' ] );
	} );

	it( 'rebuilds when a list was added', () => {
		expect( syncListOrder( [ 'a', 'b' ], [ 'a' ], [ 'a', 'b', 'c' ] ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'rebuilds when a list was removed', () => {
		expect( syncListOrder( [ 'a', 'b', 'c' ], [ 'a' ], [ 'a', 'c' ] ) ).toEqual( [ 'a', 'c' ] );
	} );

	it( 'ignores an enabled ID that no longer exists', () => {
		expect( syncListOrder( [], [ 'gone', 'a' ], [ 'a', 'b' ] ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'does not mutate its inputs', () => {
		const order = [ 'a', 'b' ];
		const lists = [ 'c', 'a' ];
		const allIds = [ 'a', 'b', 'c' ];
		syncListOrder( order, lists, allIds );
		expect( order ).toEqual( [ 'a', 'b' ] );
		expect( lists ).toEqual( [ 'c', 'a' ] );
		expect( allIds ).toEqual( [ 'a', 'b', 'c' ] );
	} );
} );

describe( 'enabledInOrder', () => {
	it( 'returns the enabled IDs in sidebar order', () => {
		expect( enabledInOrder( [ 'b', 'c', 'a' ], [ 'a', 'b' ] ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'drops an enabled ID the sidebar does not have', () => {
		expect( enabledInOrder( [ 'b', 'a' ], [ 'a', 'gone', 'b' ] ) ).toEqual( [ 'b', 'a' ] );
	} );

	it( 'returns an empty array when nothing is on', () => {
		expect( enabledInOrder( [ 'a', 'b' ], [] ) ).toEqual( [] );
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
