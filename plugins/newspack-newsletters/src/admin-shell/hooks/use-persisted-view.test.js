import { act, renderHook } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import usePersistedView from './use-persisted-view';
import { PER_PAGE_ALL } from '../utils/per-page';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const DEFAULT_VIEW = { type: 'table', page: 1, perPage: 25 };

describe( 'usePersistedView', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
		delete window.newspackNewslettersAdmin;
	} );

	it( 'seeds perPage from the bootstrapped preferences', () => {
		window.newspackNewslettersAdmin = { viewPrefs: { 'newsletters-list': { perPage: 100 } } };
		const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );
		expect( result.current[ 0 ].perPage ).toBe( 100 );
	} );

	it( 'ignores invalid stored values and other screens', () => {
		window.newspackNewslettersAdmin = { viewPrefs: { 'newsletters-list': { perPage: 9999 }, 'ads-list': { perPage: 50 } } };
		const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );
		expect( result.current[ 0 ].perPage ).toBe( 25 );
	} );

	it( 'persists a perPage change, including the All sentinel', () => {
		const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

		act( () => {
			result.current[ 1 ]( current => ( { ...current, perPage: PER_PAGE_ALL, page: 1 } ) );
		} );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack-newsletters/v1/admin-shell/preferences',
			method: 'POST',
			data: { screen: 'newsletters-list', prefs: { perPage: PER_PAGE_ALL } },
		} );
	} );

	it( 'does not persist non-perPage view changes', () => {
		const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

		act( () => {
			result.current[ 1 ]( current => ( { ...current, page: 3, search: 'digest' } ) );
		} );

		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'retries once when a save fails and nothing else would retrigger it', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'save failed' ) );

		const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

		await act( async () => {
			result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/newspack-newsletters/v1/admin-shell/preferences',
			method: 'POST',
			data: { screen: 'newsletters-list', prefs: { perPage: 50 } },
		} );
	} );

	it( 'never has two saves in flight, so writes cannot reach the server out of order', async () => {
		const deferred = {};
		apiFetch.mockImplementationOnce( () => new Promise( resolve => ( deferred.resolve = resolve ) ) );
		apiFetch.mockResolvedValue( {} );

		const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

		act( () => {
			result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
		} );
		act( () => {
			result.current[ 1 ]( current => ( { ...current, perPage: 100 } ) );
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			deferred.resolve( {} );
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/newspack-newsletters/v1/admin-shell/preferences',
			method: 'POST',
			data: { screen: 'newsletters-list', prefs: { perPage: 100 } },
		} );
	} );

	it( 'converges on the last chosen value when reverted while a save is in flight', async () => {
		const DEFAULT_20 = { type: 'table', page: 1, perPage: 20 };
		const deferred = {};
		apiFetch.mockImplementationOnce( () => new Promise( resolve => ( deferred.resolve = resolve ) ) );
		apiFetch.mockResolvedValue( {} );

		const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_20 ) );

		act( () => {
			result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/newspack-newsletters/v1/admin-shell/preferences',
			method: 'POST',
			data: { screen: 'newsletters-list', prefs: { perPage: 50 } },
		} );

		act( () => {
			result.current[ 1 ]( current => ( { ...current, perPage: 20 } ) );
		} );

		await act( async () => {
			deferred.resolve( {} );
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/newspack-newsletters/v1/admin-shell/preferences',
			method: 'POST',
			data: { screen: 'newsletters-list', prefs: { perPage: 20 } },
		} );
	} );
} );
