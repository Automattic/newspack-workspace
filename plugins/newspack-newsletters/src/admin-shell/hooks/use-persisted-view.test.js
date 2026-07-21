import { act, renderHook } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import usePersistedView from './use-persisted-view';
import { PER_PAGE_ALL } from '../utils/per-page';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const DEFAULT_VIEW = { type: 'table', page: 1, perPage: 25 };

describe( 'usePersistedView', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
		delete window.newspackNewslettersAdmin;
	} );

	afterEach( () => {
		jest.useRealTimers();
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

	it( 'persists a perPage change (debounced), including the All sentinel', () => {
		const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

		act( () => {
			result.current[ 1 ]( current => ( { ...current, perPage: PER_PAGE_ALL, page: 1 } ) );
		} );
		expect( apiFetch ).not.toHaveBeenCalled();

		act( () => {
			jest.runAllTimers();
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
		act( () => {
			jest.runAllTimers();
		} );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );
