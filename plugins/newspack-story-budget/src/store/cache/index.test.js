import { encode, decode, getCache, setCache, deleteCache, STORAGE_KEYS } from '.';

describe( 'Cache functionality', () => {
	beforeEach( () => {
		// Clear sessionStorage before each test
		sessionStorage.clear();

		// Add test key to STORAGE_KEYS for testing
		STORAGE_KEYS.testCache = {};
	} );

	afterEach( () => {
		// Clean up after tests
		delete STORAGE_KEYS.testCache;
	} );

	describe( 'encode and decode', () => {
		it( 'should encode and decode objects correctly', () => {
			const testObject = { name: 'test', value: 123 };
			const encoded = encode( testObject );
			expect( typeof encoded ).toBe( 'string' );
			expect( encoded ).toBe( JSON.stringify( testObject ) );

			const decoded = decode( encoded );
			expect( decoded ).toEqual( testObject );
		} );

		it( 'should handle non-string input in decode', () => {
			expect( decode( null ) ).toBeNull();
			expect( decode( undefined ) ).toBeUndefined();
			expect( decode( 123 ) ).toBe( 123 );
		} );
	} );

	describe( 'getCache', () => {
		it( 'should return null for non-existent cache', () => {
			expect( getCache( 'nonExistentCache' ) ).toBeNull();
		} );

		it( 'should use correct storage key prefix', () => {
			const testData = { test: 'value' };
			setCache( 'stories', testData );
			expect( sessionStorage.getItem( 'newspack-story-budget-stories' ) ).toBeDefined();
		} );

		it( 'should return null for expired cache', () => {
			const oldTimestamp = Date.now() - 2 * 60 * 60 * 1000;
			sessionStorage.setItem(
				'testCache',
				encode( {
					data: { test: 'value' },
					timestamp: oldTimestamp,
				} )
			);
			expect( getCache( 'testCache' ) ).toBeNull();
		} );

		it( 'should return cached data for valid cache', () => {
			const testData = { test: 'value' };
			setCache( 'testCache', testData );
			const cache = getCache( 'testCache' );
			expect( cache.data ).toEqual( testData );
			expect( cache.timestamp ).toBeDefined();
		} );
	} );

	describe( 'setCache', () => {
		it( 'should store data with timestamp', () => {
			const testData = { test: 'value' };
			setCache( 'testCache', testData );
			const stored = getCache( 'testCache' );
			expect( stored.data ).toEqual( testData );
			expect( stored.timestamp ).toBeDefined();
		} );
	} );

	describe( 'deleteCache', () => {
		it( 'should delete existing cache', () => {
			const testData = { test: 'value' };
			setCache( 'stories', testData );
			expect( getCache( 'stories' ) ).not.toBeNull();

			deleteCache( 'stories' );
			expect( getCache( 'stories' ) ).toBeNull();
		} );

		it( 'should do nothing for non-existent cache key', () => {
			deleteCache( 'nonExistentKey' );
			// Should not throw error
		} );

		it( 'should use correct storage key prefix when deleting', () => {
			const testData = { test: 'value' };
			setCache( 'stories', testData );
			deleteCache( 'stories' );
			expect( sessionStorage.getItem( 'newspack-story-budget-stories' ) ).toBeNull();
		} );
	} );
} );
