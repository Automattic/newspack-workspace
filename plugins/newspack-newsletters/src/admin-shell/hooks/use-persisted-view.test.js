import { act, renderHook } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import usePersistedView from './use-persisted-view';
import { PER_PAGE_ALL } from '../utils/per-page';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const PATH = '/newspack-newsletters/v1/admin-shell/preferences';
const DEFAULT_VIEW = { type: 'table', page: 1, perPage: 25 };
const FIELDS = [ 'status', 'date', 'author' ];

// Longer than the hook's debounce.
const settle = async () => {
	await act( async () => {
		jest.advanceTimersByTime( 1000 );
	} );
};

const saved = ( prefs, screen = 'newsletters-list' ) => ( {
	path: PATH,
	method: 'POST',
	data: { screen, prefs },
	// Carried so a `pagehide` flush can cancel the write it supersedes.
	signal: expect.any( AbortSignal ),
} );

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

	describe( 'seeding', () => {
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

		it( 'seeds density, column widths, field order and sort', () => {
			window.newspackNewslettersAdmin = {
				viewPrefs: {
					'newsletters-list': {
						fields: [ 'author', 'status' ],
						sort: { field: 'author', direction: 'asc' },
						layout: { density: 'compact', styles: { status: { width: '120px' } } },
					},
				},
			};
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW, { fieldIds: FIELDS } ) );

			expect( result.current[ 0 ] ).toMatchObject( {
				fields: [ 'author', 'status' ],
				sort: { field: 'author', direction: 'asc' },
				layout: { density: 'compact', styles: { status: { width: '120px' } } },
			} );
		} );

		it( 'drops stored values for columns the screen no longer defines', () => {
			window.newspackNewslettersAdmin = {
				viewPrefs: {
					'newsletters-list': {
						fields: [ 'author', 'retired_column' ],
						sort: { field: 'retired_column', direction: 'asc' },
						layout: { styles: { retired_column: { width: '120px' } } },
					},
				},
			};
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW, { fieldIds: FIELDS } ) );

			expect( result.current[ 0 ].fields ).toEqual( [ 'author' ] );
			expect( result.current[ 0 ].sort ).toBeUndefined();
			expect( result.current[ 0 ].layout ).toBeUndefined();
		} );

		it( 'keeps a stored layout type only while the screen still offers it', () => {
			window.newspackNewslettersAdmin = { viewPrefs: { 'layouts-list': { type: 'grid' } } };

			const offered = renderHook( () => usePersistedView( 'layouts-list', DEFAULT_VIEW, { layoutTypes: [ 'table', 'grid' ] } ) );
			expect( offered.result.current[ 0 ].type ).toBe( 'grid' );

			const notOffered = renderHook( () => usePersistedView( 'layouts-list', DEFAULT_VIEW, { layoutTypes: [ 'table' ] } ) );
			expect( notOffered.result.current[ 0 ].type ).toBe( 'table' );
		} );

		it( 'lets a forwarded legacy link override the saved view', () => {
			window.newspackNewslettersAdmin = { viewPrefs: { 'newsletters-list': { sort: { field: 'author', direction: 'asc' } } } };
			const { result } = renderHook( () =>
				usePersistedView( 'newsletters-list', DEFAULT_VIEW, {
					fieldIds: FIELDS,
					urlPatch: { sort: { field: 'date', direction: 'desc' } },
				} )
			);
			expect( result.current[ 0 ].sort ).toEqual( { field: 'date', direction: 'desc' } );
		} );

		it( 'writes nothing on mount', async () => {
			window.newspackNewslettersAdmin = { viewPrefs: { 'newsletters-list': { perPage: 100 } } };
			renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW, { urlPatch: { sort: { field: 'date', direction: 'desc' } } } ) );
			await settle();
			expect( apiFetch ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'persisting', () => {
		it( 'persists a perPage change, including the All sentinel', async () => {
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: PER_PAGE_ALL, page: 1 } ) );
			} );
			await settle();

			expect( apiFetch ).toHaveBeenCalledWith( saved( { perPage: PER_PAGE_ALL, type: 'table' } ) );
		} );

		it( 'persists density, field order and column widths', async () => {
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW, { fieldIds: FIELDS } ) );

			act( () => {
				result.current[ 1 ]( current => ( {
					...current,
					fields: [ 'author', 'status' ],
					layout: { density: 'compact', styles: { status: { width: '120px' } } },
				} ) );
			} );
			await settle();

			expect( apiFetch ).toHaveBeenCalledWith(
				saved( {
					perPage: 25,
					type: 'table',
					fields: [ 'author', 'status' ],
					layout: { density: 'compact', styles: { status: { width: '120px' } } },
				} )
			);
		} );

		it( 'does not persist page, search or filters', async () => {
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( {
					...current,
					page: 3,
					search: 'digest',
					filters: [ { field: 'status', operator: 'isAny', value: [ 'draft' ] } ],
				} ) );
			} );
			await settle();

			expect( apiFetch ).not.toHaveBeenCalled();
		} );

		it( 'collapses a burst of changes into one save', async () => {
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW, { fieldIds: FIELDS } ) );

			for ( const width of [ '100px', '110px', '120px' ] ) {
				act( () => {
					result.current[ 1 ]( current => ( { ...current, layout: { styles: { status: { width } } } } ) );
				} );
			}
			await settle();

			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( apiFetch ).toHaveBeenCalledWith( saved( { perPage: 25, type: 'table', layout: { styles: { status: { width: '120px' } } } } ) );
		} );

		it( 'flushes a pending save when the page goes away', async () => {
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
			} );
			expect( apiFetch ).not.toHaveBeenCalled();

			act( () => {
				window.dispatchEvent( new window.Event( 'pagehide' ) );
			} );

			expect( apiFetch ).toHaveBeenCalledWith( { ...saved( { perPage: 50, type: 'table' } ), keepalive: true } );
		} );

		it( 'flushes a pending save when the screen unmounts', () => {
			const { result, unmount } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
			} );
			expect( apiFetch ).not.toHaveBeenCalled();

			unmount();

			expect( apiFetch ).toHaveBeenCalledWith( saved( { perPage: 50, type: 'table' } ) );
		} );

		it( 'retries once when a save fails and nothing else would retrigger it', async () => {
			apiFetch.mockRejectedValueOnce( new Error( 'save failed' ) );

			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
			} );
			await settle();

			expect( apiFetch ).toHaveBeenCalledTimes( 2 );
			expect( apiFetch ).toHaveBeenLastCalledWith( saved( { perPage: 50, type: 'table' } ) );
		} );

		it( 'never has two saves in flight, so writes cannot reach the server out of order', async () => {
			const deferred = {};
			apiFetch.mockImplementationOnce( () => new Promise( resolve => ( deferred.resolve = resolve ) ) );
			apiFetch.mockResolvedValue( {} );

			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
			} );
			await settle();
			expect( apiFetch ).toHaveBeenCalledTimes( 1 );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 100 } ) );
			} );
			await settle();
			expect( apiFetch ).toHaveBeenCalledTimes( 1 );

			await act( async () => {
				deferred.resolve( {} );
			} );

			expect( apiFetch ).toHaveBeenCalledTimes( 2 );
			expect( apiFetch ).toHaveBeenLastCalledWith( saved( { perPage: 100, type: 'table' } ) );
		} );

		it( 'converges on the last chosen value when reverted while a save is in flight', async () => {
			const deferred = {};
			apiFetch.mockImplementationOnce( () => new Promise( resolve => ( deferred.resolve = resolve ) ) );
			apiFetch.mockResolvedValue( {} );

			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
			} );
			await settle();
			expect( apiFetch ).toHaveBeenLastCalledWith( saved( { perPage: 50, type: 'table' } ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 20 } ) );
			} );
			await settle();

			await act( async () => {
				deferred.resolve( {} );
			} );

			expect( apiFetch ).toHaveBeenLastCalledWith( saved( { perPage: 20, type: 'table' } ) );
		} );

		it( 'flushes the newest value on pagehide even while a save is in flight', async () => {
			const deferred = {};
			apiFetch.mockImplementationOnce( () => new Promise( resolve => ( deferred.resolve = resolve ) ) );
			apiFetch.mockResolvedValue( {} );

			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
			} );
			await settle();
			expect( apiFetch ).toHaveBeenLastCalledWith( saved( { perPage: 50, type: 'table' } ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 20 } ) );
			} );
			act( () => {
				window.dispatchEvent( new window.Event( 'pagehide' ) );
			} );

			expect( apiFetch ).toHaveBeenLastCalledWith( { ...saved( { perPage: 20, type: 'table' } ), keepalive: true } );
		} );

		it( 'reissues an in-flight save with keepalive when the page goes away', async () => {
			apiFetch.mockImplementationOnce( () => new Promise( () => {} ) );

			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, perPage: 50 } ) );
			} );
			await settle();
			expect( apiFetch ).toHaveBeenCalledTimes( 1 );

			act( () => {
				window.dispatchEvent( new window.Event( 'pagehide' ) );
			} );

			expect( apiFetch ).toHaveBeenCalledTimes( 2 );
			expect( apiFetch ).toHaveBeenLastCalledWith( { ...saved( { perPage: 50, type: 'table' } ), keepalive: true } );
		} );

		it( 'drops a non-finite column width rather than poisoning the payload', async () => {
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( {
					...current,
					layout: { styles: { status: { width: NaN, minWidth: 40 } } },
				} ) );
			} );
			await settle();

			expect( apiFetch ).toHaveBeenCalledWith( saved( { perPage: 25, type: 'table', layout: { styles: { status: { minWidth: 40 } } } } ) );
		} );

		it( 'sends only the column-style keys the server stores', async () => {
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( {
					...current,
					layout: { styles: { status: { width: '120px', minWidth: 40, align: 'center', resizable: true }, date: { align: 'diagonal' } } },
				} ) );
			} );
			await settle();

			expect( apiFetch ).toHaveBeenCalledWith(
				saved( { perPage: 25, type: 'table', layout: { styles: { status: { width: '120px', minWidth: 40, align: 'center' } } } } )
			);
		} );
	} );

	describe( 'property visibility toggles', () => {
		it( 'persists them alongside the field checkboxes', async () => {
			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			act( () => {
				result.current[ 1 ]( current => ( { ...current, showMedia: false } ) );
			} );
			await settle();

			expect( apiFetch ).toHaveBeenCalledWith( saved( { perPage: 25, type: 'table', showMedia: false } ) );
		} );

		it( 'seeds them from the stored preferences', () => {
			window.newspackNewslettersAdmin = {
				viewPrefs: { 'newsletters-list': { showMedia: false, showTitle: true } },
			};

			const { result } = renderHook( () => usePersistedView( 'newsletters-list', DEFAULT_VIEW ) );

			expect( result.current[ 0 ] ).toMatchObject( { showMedia: false, showTitle: true } );
		} );
	} );

	describe( 'reconciling a restored view', () => {
		it( 'applies the screen normalize to the restored view', () => {
			window.newspackNewslettersAdmin = { viewPrefs: { 'layouts-list': { type: 'table' } } };
			const normalize = view => ( 'table' === view.type ? { ...view, mediaField: undefined } : view );

			const { result } = renderHook( () =>
				usePersistedView(
					'layouts-list',
					{ type: 'grid', perPage: 24, mediaField: 'preview' },
					{ perPageOptions: [ 24 ], layoutTypes: [ 'grid', 'table' ], normalize }
				)
			);

			expect( result.current[ 0 ].type ).toBe( 'table' );
			expect( result.current[ 0 ].mediaField ).toBeUndefined();
		} );

		it( 'falls back to the screen default when every stored column has been renamed', () => {
			window.newspackNewslettersAdmin = { viewPrefs: { 'newsletters-list': { fields: [ 'legacy_one', 'legacy_two' ] } } };

			const { result } = renderHook( () => usePersistedView( 'newsletters-list', { ...DEFAULT_VIEW, fields: FIELDS }, { fieldIds: FIELDS } ) );

			expect( result.current[ 0 ].fields ).toEqual( FIELDS );
		} );

		it( 'still honours a stored empty field list', () => {
			window.newspackNewslettersAdmin = { viewPrefs: { 'newsletters-list': { fields: [] } } };

			const { result } = renderHook( () => usePersistedView( 'newsletters-list', { ...DEFAULT_VIEW, fields: FIELDS }, { fieldIds: FIELDS } ) );

			expect( result.current[ 0 ].fields ).toEqual( [] );
		} );
	} );
} );
