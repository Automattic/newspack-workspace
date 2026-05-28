// @jest-environment jsdom

import setupEngagement from './engagement';
import { createMockRAS } from './mocks/ras';

describe( 'setupEngagement', () => {
	let mock;

	beforeEach( () => {
		mock = createMockRAS();
	} );

	afterEach( () => {
		mock.reset();
	} );

	it( 'should register a merge strategy for first_visit_date', () => {
		setupEngagement( mock.ras );
		expect( mock.ras.store.register ).toHaveBeenCalledWith( 'first_visit_date', {
			merge: expect.any( Function ),
		} );
	} );

	it( 'should register a merge strategy for last_active', () => {
		setupEngagement( mock.ras );
		expect( mock.ras.store.register ).toHaveBeenCalledWith( 'last_active', {
			merge: expect.any( Function ),
		} );
	} );

	describe( 'first_visit_date merge', () => {
		function getFirstVisitMerge() {
			setupEngagement( mock.ras );
			const call = mock.ras.store.register.mock.calls.find( ( [ key ] ) => key === 'first_visit_date' );
			return call[ 1 ].merge;
		}

		it( 'should return the older of two values', () => {
			const merge = getFirstVisitMerge();
			expect( merge( 1000, 9999 ) ).toBe( 1000 );
			expect( merge( 9999, 1000 ) ).toBe( 1000 );
		} );

		it( 'should return the existing value when only one is present', () => {
			const merge = getFirstVisitMerge();
			expect( merge( 1000, null ) ).toBe( 1000 );
			expect( merge( null, 1000 ) ).toBe( 1000 );
		} );

		it( 'should return Date.now() when neither value exists', () => {
			const merge = getFirstVisitMerge();
			const before = Date.now();
			const result = merge( null, null );
			const after = Date.now();
			expect( result ).toBeGreaterThanOrEqual( before );
			expect( result ).toBeLessThanOrEqual( after );
		} );
	} );

	describe( 'last_active merge', () => {
		function getLastActiveMerge() {
			setupEngagement( mock.ras );
			const call = mock.ras.store.register.mock.calls.find( ( [ key ] ) => key === 'last_active' );
			return call[ 1 ].merge;
		}

		it( 'should return the newer of two values', () => {
			const merge = getLastActiveMerge();
			expect( merge( 1000, 9999 ) ).toBe( 9999 );
			expect( merge( 9999, 1000 ) ).toBe( 9999 );
		} );
	} );

	it( 'should set first_visit_date default on first visit', () => {
		setupEngagement( mock.ras );
		const before = Date.now();
		expect( mock.storeData.first_visit_date ).toBeGreaterThanOrEqual( before - 10 );
	} );

	it( 'should not overwrite existing client first_visit_date', () => {
		mock.storeData.first_visit_date = 1000;
		setupEngagement( mock.ras );
		expect( mock.storeData.first_visit_date ).toBe( 1000 );
	} );

	it( 'should set last_active to a recent timestamp', () => {
		const before = Date.now();
		setupEngagement( mock.ras );
		const after = Date.now();
		expect( mock.storeData.last_active ).toBeGreaterThanOrEqual( before );
		expect( mock.storeData.last_active ).toBeLessThanOrEqual( after );
	} );
} );
