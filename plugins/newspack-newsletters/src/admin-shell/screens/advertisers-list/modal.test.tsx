/**
 * Tree-building helper tests — the only piece of the modal with non-trivial
 * logic worth covering in unit tests. The form behaviour itself is thin
 * glue around `apiFetch` and `@wordpress/components`; covered via manual
 * verification.
 */

import { buildAdvertiserTree } from './modal';

describe( 'buildAdvertiserTree', () => {
	const flat = [
		{ id: 1, name: 'A', parent: 0 },
		{ id: 2, name: 'B', parent: 0 },
		{ id: 3, name: 'A.1', parent: 1 },
		{ id: 4, name: 'A.2', parent: 1 },
		{ id: 5, name: 'A.1.1', parent: 3 },
	];

	it( 'builds a nested tree from flat term records', () => {
		const tree = buildAdvertiserTree( flat );

		expect( tree ).toHaveLength( 2 );
		expect( tree[ 0 ].name ).toBe( 'A' );
		expect( tree[ 0 ].id ).toBe( '1' );
		expect( tree[ 0 ].children ).toHaveLength( 2 );
		expect( tree[ 0 ].children[ 0 ].name ).toBe( 'A.1' );
		expect( tree[ 0 ].children[ 0 ].children ).toHaveLength( 1 );
		expect( tree[ 0 ].children[ 0 ].children[ 0 ].name ).toBe( 'A.1.1' );
		expect( tree[ 1 ].name ).toBe( 'B' );
	} );

	it( 'returns an empty array for non-array input', () => {
		expect( buildAdvertiserTree( null ) ).toEqual( [] );
		expect( buildAdvertiserTree( undefined ) ).toEqual( [] );
	} );

	it( 'omits the excluded id and its descendants — prevents picking a sub-tree as its own parent', () => {
		// Editing "A.1" should not see itself or "A.1.1" in the parent picker.
		const tree = buildAdvertiserTree( flat, 3 );

		expect( tree[ 0 ].name ).toBe( 'A' );
		// "A" still appears (it's the parent of "A.1"); "A.2" still appears.
		expect( tree[ 0 ].children.map( child => child.name ) ).toEqual( [ 'A.2' ] );
		// "A.1" itself is gone.
		expect( tree[ 0 ].children.find( child => child.id === '3' ) ).toBeUndefined();
	} );

	it( 'omitting a top-level term also drops its descendants', () => {
		const tree = buildAdvertiserTree( flat, 1 );

		// Only "B" remains — "A" and its sub-tree are excluded.
		expect( tree ).toHaveLength( 1 );
		expect( tree[ 0 ].name ).toBe( 'B' );
	} );

	it( 'ids are stringified for TreeSelect compatibility', () => {
		const tree = buildAdvertiserTree( [ { id: 42, name: 'X', parent: 0 } ] );
		expect( tree[ 0 ].id ).toBe( '42' );
	} );
} );
