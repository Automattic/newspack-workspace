/**
 * Internal dependencies
 */
import { hasGatedLists, isListGated, listsToKeep, syncListOrder } from './utils';

describe( 'syncListOrder', () => {
	it( 'builds the order on first load: lists that are on first, in form order, then the rest', () => {
		expect( syncListOrder( [], [ 'c', 'a' ], [ 'a', 'b', 'c' ] ) ).toEqual( [ 'c', 'a', 'b' ] );
	} );

	it( 'returns the same array when it agrees with the block', () => {
		const order = [ 'a', 'b', 'c' ];
		expect( syncListOrder( order, [ 'a', 'c' ], [ 'a', 'b', 'c' ] ) ).toBe( order );
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

	it( 'rebuilds when one list was swapped for another, with the count unchanged', () => {
		expect( syncListOrder( [ 'a', 'b' ], [ 'a' ], [ 'a', 'c' ] ) ).toEqual( [ 'a', 'c' ] );
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

describe( 'listsToKeep', () => {
	it( 'drops the saved lists the config lacks', () => {
		expect( listsToKeep( [ 'a', 'gone', 'b' ], { a: {}, b: {} } ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'keeps every saved list when the config holds none of them', () => {
		expect( listsToKeep( [ 'premium-1', 'premium-2' ], { a: {} } ) ).toEqual( [ 'premium-1', 'premium-2' ] );
	} );

	it.each( [
		[ 'an empty object', {} ],
		[ 'an empty array', [] ],
		[ 'nothing', undefined ],
	] )( 'keeps every saved list when the config is %s', ( _label, config ) => {
		expect( listsToKeep( [ 'premium-1' ], config ) ).toEqual( [ 'premium-1' ] );
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
