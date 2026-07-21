import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { notifyError, notifyInfo } from '../notices';
import { FETCH_ALL_CHUNK_SIZE, FETCH_ALL_MAX_ITEMS } from '../utils/per-page';

// Modest parallelism for fetch-all walks — enough to hide latency
// without hammering the server.
const FETCH_ALL_CONCURRENCY = 3;

function parseHeaderInt( value ) {
	const parsed = parseInt( value, 10 );
	return Number.isNaN( parsed ) ? 0 : parsed;
}

function readPaginationInfo( response ) {
	return {
		totalItems: parseHeaderInt( response.headers.get( 'X-WP-Total' ) ),
		totalPages: parseHeaderInt( response.headers.get( 'X-WP-TotalPages' ) ),
	};
}

// A page can go out of range mid-walk if items are trashed/filtered away by
// someone else — the collection got shorter, retrying won't help.
function isOutOfRangePageError( error ) {
	if ( ! error ) {
		return false;
	}
	if ( error.code === 'rest_post_invalid_page_number' ) {
		return true;
	}
	return error.status === 400 || error.data?.status === 400;
}

/**
 * Server-side paginated fetch hook for DataView list screens.
 *
 * A falsy `path` defers the main fetch (used by layouts during the
 * parent's `view === null` latch). A falsy `trashCountPath` skips the
 * trash sub-fetch — `hasResolved` flips solely on the main resolution.
 *
 * When `fetchAll` is set, the first response's `X-WP-TotalPages` drives
 * a walk over the remaining pages (the REST API caps `per_page` at 100).
 * `data` commits once the walk finishes (or aborts); `progress` reports
 * the walk meanwhile (`{ loaded, total }`, `null` outside a walk) and
 * `totalPages` is clamped to 1 so the footer doesn't offer pagination.
 *
 * @param {Object}  options
 * @param {string}  options.path             Pre-computed REST path. Falsy ⇒ defer.
 * @param {string}  [options.trashCountPath] When set, sub-fetch for the trash banner.
 * @param {number}  [options.mutationKey]    Bump externally to refetch (alongside internal refresh).
 * @param {string}  [options.errorMessage]   notifyError message on fetch failure.
 * @param {string}  [options.errorNoticeId]  notifyError dedupe id.
 * @param {boolean} [options.fetchAll]       Walk every page of the collection.
 * @return {{ data: Array, paginationInfo: Object, isLoading: boolean, hasResolved: boolean, hasLoadedOnce: boolean, trashCount: number|null, progress: Object|null, refresh: () => void }} Hook state.
 */
export default function useCollectionData( { path, trashCountPath = null, mutationKey = 0, errorMessage, errorNoticeId, fetchAll = false } ) {
	const [ data, setData ] = useState( [] );
	const [ paginationInfo, setPaginationInfo ] = useState( { totalItems: 0, totalPages: 0 } );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ mainResolved, setMainResolved ] = useState( false );
	const [ trashResolved, setTrashResolved ] = useState( ! trashCountPath );
	const [ hasLoadedOnce, setHasLoadedOnce ] = useState( false );
	// `null` ⇒ unknown; failed trash fetch stays `null` so `=== 0` stays false and the banner stays hidden.
	const [ trashCount, setTrashCount ] = useState( null );
	const [ progress, setProgress ] = useState( null );

	const refresh = useCallback( () => setRefreshKey( key => key + 1 ), [] );

	useEffect( () => {
		if ( ! path ) {
			setData( [] );
			setPaginationInfo( { totalItems: 0, totalPages: 0 } );
			setIsLoading( false );
			return undefined;
		}
		let cancelled = false;
		setIsLoading( true );
		setProgress( null );

		apiFetch( { path, parse: false } )
			.then( async response => {
				const items = await response.json();
				if ( cancelled ) {
					return;
				}
				const pagination = readPaginationInfo( response );
				const firstPage = Array.isArray( items ) ? items : [];

				if ( ! fetchAll ) {
					setData( firstPage );
					setPaginationInfo( pagination );
					setHasLoadedOnce( true );
					return;
				}

				const all = [ ...firstPage ];
				setPaginationInfo( { totalItems: pagination.totalItems, totalPages: 1 } );
				setHasLoadedOnce( true );

				if ( pagination.totalPages <= 1 ) {
					setData( all );
					return;
				}

				// Caps the walk so a very large site can't hand DataViews tens of
				// thousands of non-virtualised rows.
				const maxPage = Math.min( pagination.totalPages, Math.ceil( FETCH_ALL_MAX_ITEMS / FETCH_ALL_CHUNK_SIZE ) );

				setProgress( { loaded: all.length, total: pagination.totalItems } );
				let endedEarly = false;
				let cappedByMax = false;
				for ( let page = 2; page <= maxPage && ! cancelled; page += FETCH_ALL_CONCURRENCY ) {
					const lastPage = Math.min( page + FETCH_ALL_CONCURRENCY - 1, maxPage );
					const fetchBatch = () => {
						const batch = [];
						for ( let p = page; p <= lastPage; p++ ) {
							batch.push( apiFetch( { path: addQueryArgs( path, { page: p } ), parse: false } ).then( r => r.json() ) );
						}
						return Promise.all( batch );
					};

					let pages;
					try {
						pages = await fetchBatch();
					} catch ( error ) {
						if ( isOutOfRangePageError( error ) ) {
							endedEarly = true;
							break;
						}
						try {
							pages = await fetchBatch();
						} catch ( retryError ) {
							if ( isOutOfRangePageError( retryError ) ) {
								endedEarly = true;
								break;
							}
							if ( ! cancelled ) {
								notifyError(
									__( 'Only some items could be loaded. Reload the page to try again.', 'newspack-newsletters' ),
									errorNoticeId ? { id: errorNoticeId } : undefined
								);
							}
							endedEarly = true;
							break;
						}
					}
					if ( cancelled ) {
						return;
					}
					pages.forEach( pageItems => {
						if ( Array.isArray( pageItems ) ) {
							all.push( ...pageItems );
						}
					} );
					setProgress( { loaded: all.length, total: pagination.totalItems } );
				}

				if ( cancelled ) {
					return;
				}

				if ( ! endedEarly && maxPage < pagination.totalPages ) {
					endedEarly = true;
					cappedByMax = true;
				}

				setData( all );

				if ( endedEarly ) {
					setPaginationInfo( { totalItems: all.length, totalPages: 1 } );
				}

				if ( cappedByMax ) {
					notifyInfo(
						sprintf(
							/* translators: %s: number of items shown */
							__( 'Showing the first %s items. Use search or filters to narrow the list.', 'newspack-newsletters' ),
							all.length.toLocaleString()
						)
					);
				}
			} )
			.catch( () => {
				if ( cancelled || ! errorMessage ) {
					return;
				}
				// Keep last-good data so a refetch error doesn't trip the strict-empty banner.
				notifyError( errorMessage, errorNoticeId ? { id: errorNoticeId } : undefined );
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
					setMainResolved( true );
					setProgress( null );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ path, mutationKey, refreshKey, errorMessage, errorNoticeId, fetchAll ] );

	useEffect( () => {
		if ( ! trashCountPath ) {
			return undefined;
		}
		let cancelled = false;
		// Back to "unknown" while the new count is in flight, or a freshly-trashed last item flashes EmptyState.
		setTrashCount( null );
		apiFetch( { path: trashCountPath, parse: false } )
			.then( response => {
				if ( ! cancelled ) {
					setTrashCount( parseHeaderInt( response.headers.get( 'X-WP-Total' ) ) );
				}
			} )
			.catch( () => {} )
			.finally( () => {
				if ( ! cancelled ) {
					setTrashResolved( true );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ trashCountPath, mutationKey, refreshKey ] );

	const hasResolved = mainResolved && trashResolved;

	return { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce, trashCount, progress, refresh };
}
