// @jest-environment jsdom

import Store from './store';

describe( 'Store', () => {
	beforeEach( () => {
		window.newspack_reader_data = window.newspack_reader_data || {};
		localStorage.clear();
	} );
	it( 'should return an object with methods', () => {
		const store = Store();
		expect( typeof store ).toBe( 'object' );
		expect( typeof store.get ).toBe( 'function' );
		expect( typeof store.getAll ).toBe( 'function' );
		expect( typeof store.set ).toBe( 'function' );
		expect( typeof store.add ).toBe( 'function' );
		expect( typeof store.delete ).toBe( 'function' );
	} );
	it( 'should store json stringified data in localStorage', () => {
		const store = Store();
		store.set( 'string', 'foo' );
		store.set( 'array', [ 1, 2, 3 ] );
		store.set( 'object', { foo: 'bar' } );
		store.set( 'empty', '' );
		store.set( 'boolean', false );
		expect( localStorage.getItem( 'np_reader_string' ) ).toEqual( '"foo"' );
		expect( localStorage.getItem( 'np_reader_array' ) ).toEqual( '[1,2,3]' );
		expect( localStorage.getItem( 'np_reader_object' ) ).toEqual( '{"foo":"bar"}' );
		expect( localStorage.getItem( 'np_reader_empty' ) ).toEqual( '""' );
		expect( localStorage.getItem( 'np_reader_boolean' ) ).toEqual( 'false' );
	} );
	it( 'should not store undefined or null values', () => {
		const store = Store();
		const storeUndefined = () => store.set( 'undefined', undefined );
		const storeNull = () => store.set( 'null', null );
		expect( storeUndefined ).toThrow( Error );
		expect( storeNull ).toThrow( Error );
		expect( localStorage.getItem( 'np_reader_undefined' ) ).toBeNull();
		expect( localStorage.getItem( 'np_reader_null' ) ).toBeNull();
	} );
	it( 'should store data and return it', () => {
		const store = Store();
		store.set( 'foo', 'bar' );
		expect( store.get( 'foo' ) ).toEqual( 'bar' );
	} );
	it( 'should delete a key', () => {
		const store = Store();
		store.set( 'foo', 'bar' );
		store.delete( 'foo' );
		expect( localStorage.getItem( 'np_reader_foo' ) ).toBeNull();
		expect( store.get( 'foo' ) ).toBeNull();
	} );
	it( 'should add to a collection', () => {
		const store = Store();
		const item = { foo: 'bar' };
		store.add( 'my-collection', item );
		expect( store.get( 'my-collection' ) ).toEqual( [ item ] );
	} );
	it( 'should not store more than 1000 items in a collection', () => {
		const store = Store();
		for ( let i = 0; i < 1001; i++ ) {
			store.add( 'my-collection', { foo: 'bar' } );
		}
		expect( store.get( 'my-collection' ).length ).toEqual( 1000 );
	} );
	it( 'should clear added items older than 30 days in a collection', () => {
		const store = Store();
		const now = Date.now();
		store.add( 'my-collection', { timestamp: 1 } ); // Old timestamp.
		store.add( 'my-collection', { timestamp: now } ); // Setting new item clears old ones.
		expect( store.get( 'my-collection' ) ).toEqual( [ { timestamp: now } ] );
	} );
	it( 'should not add to collection if key value is not an array', () => {
		const store = Store();
		store.set( 'my-collection', 'not-an-array' );
		const storeNotArray = () => store.add( 'my-collection', { foo: 'bar' } );
		expect( storeNotArray ).toThrow( Error );
		expect( store.get( 'my-collection' ) ).toEqual( 'not-an-array' );
	} );
	it( 'should not add to collection if key is empty', () => {
		const store = Store();
		const storeEmptyKey = () => store.add( undefined, { foo: 'bar' } );
		expect( storeEmptyKey ).toThrow( Error );
		expect( store.get( 'my-collection' ) ).toBeNull();
	} );
	it( 'should not add to collection if value is empty', () => {
		const store = Store();
		const storeEmptyValue = () => store.add( 'my-collection', undefined );
		expect( storeEmptyValue ).toThrow( Error );
		expect( store.get( 'my-collection' ) ).toBeNull();
	} );
	it( 'should load store with initial data', () => {
		window.newspack_reader_data = {
			items: {
				foo: '"bar"',
			},
		};
		const store = Store();
		store.rehydrate();
		expect( store.get( 'foo' ) ).toEqual( 'bar' );
	} );
	it( 'should fall back to the server value when a merge strategy throws', () => {
		window.newspack_reader_data = {
			items: {
				flaky: '"server"',
			},
		};
		const store = Store();
		store.register( 'flaky', {
			merge: () => {
				throw new Error( 'boom' );
			},
		} );
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		expect( () => store.rehydrate() ).not.toThrow();
		expect( store.get( 'flaky' ) ).toEqual( 'server' );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'Unable to rehydrate flaky' ), expect.any( Error ) );
		warn.mockRestore();
	} );
	describe( 'getAll', () => {
		beforeEach( () => {
			window.newspack_reader_data = {};
		} );
		it( 'should return all store data as a plain object', () => {
			const store = Store();
			store.set( 'name', 'Leo' );
			store.set( 'prefs', { theme: 'dark' } );
			store.set( 'scores', [ 1, 2, 3 ] );
			const all = store.getAll();
			expect( all ).toEqual( {
				name: 'Leo',
				prefs: { theme: 'dark' },
				scores: [ 1, 2, 3 ],
			} );
		} );
		it( 'should not include internal keys in getAll', () => {
			const store = Store();
			store.set( 'visible', 'yes' );
			const all = store.getAll();
			expect( all.visible ).toEqual( 'yes' );
			expect( all ).not.toHaveProperty( 'unsynced' );
			// Verify internal key exists in storage but isn't surfaced.
			expect( localStorage.getItem( 'np_reader__unsynced' ) ).not.toBeNull();
		} );
		it( 'should return an empty object when store is empty', () => {
			const store = Store();
			expect( store.getAll() ).toEqual( {} );
		} );
		it( 'should include rehydrated server items in getAll', () => {
			window.newspack_reader_data = {
				items: {
					is_donor: 'true',
					active_memberships: '[1,2]',
				},
			};
			const store = Store();
			store.rehydrate();
			const all = store.getAll();
			expect( all.is_donor ).toEqual( true );
			expect( all.active_memberships ).toEqual( [ 1, 2 ] );
		} );
	} );
	describe( 'Read-only keys', () => {
		beforeEach( () => {
			window.newspack_reader_data = {
				read_only_keys: [ 'is_donor', 'active_memberships' ],
			};
		} );
		it( 'should throw when setting a read-only key', () => {
			const store = Store();
			expect( () => store.set( 'is_donor', true ) ).toThrow( "Key 'is_donor' is read-only." );
		} );
		it( 'should throw when deleting a read-only key', () => {
			const store = Store();
			expect( () => store.delete( 'active_memberships' ) ).toThrow( "Key 'active_memberships' is read-only." );
		} );
		it( 'should throw when adding to a read-only key', () => {
			const store = Store();
			expect( () => store.add( 'is_donor', { foo: 'bar' } ) ).toThrow( "Key 'is_donor' is read-only." );
		} );
		it( 'should allow getting read-only keys populated via rehydration', () => {
			window.newspack_reader_data = {
				read_only_keys: [ 'is_donor' ],
				items: {
					is_donor: true,
				},
			};
			const store = Store();
			store.rehydrate();
			expect( store.get( 'is_donor' ) ).toEqual( true );
		} );
		it( 'should not affect non-read-only keys', () => {
			const store = Store();
			store.set( 'custom_key', 'value' );
			expect( store.get( 'custom_key' ) ).toEqual( 'value' );
			store.delete( 'custom_key' );
			expect( store.get( 'custom_key' ) ).toBeNull();
		} );
		it( 'should prune read-only keys from the unsynced queue on init', () => {
			// Simulate a pre-upgrade state where a read-only key was queued for sync.
			localStorage.setItem( 'np_reader__unsynced', JSON.stringify( [ 'is_donor', 'custom_key' ] ) );
			localStorage.setItem( 'np_reader_is_donor', true );
			localStorage.setItem( 'np_reader_custom_key', '"value"' );
			Store();
			const unsynced = JSON.parse( localStorage.getItem( 'np_reader__unsynced' ) );
			expect( unsynced ).toEqual( [ 'custom_key' ] );
		} );
	} );
} );
