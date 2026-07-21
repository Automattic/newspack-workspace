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

// The collection got shorter mid-walk — retrying won't help.
const OUT_OF_RANGE_PAGE_CODES = [ 'rest_post_invalid_page_number', 'rest_term_invalid_page_number' ];

function isOutOfRangePageError( error ) {
	return OUT_OF_RANGE_PAGE_CODES.includes( error?.code );
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

				const maxPage = Math.min( pagination.totalPages, Math.ceil( FETCH_ALL_MAX_ITEMS / FETCH_ALL_CHUNK_SIZE ) );

				setProgress( { loaded: all.length, total: pagination.totalItems } );
				let endedEarly = false;
				let cappedByMax = false;
				// Settled, so one bad page doesn't discard its siblings.
				const fetchPages = ( from, to ) => {
					const batch = [];
					for ( let p = from; p <= to; p++ ) {
						// Parsed — an unparsed rejection is a bare `Response`, no error code.
						batch.push( apiFetch( { path: addQueryArgs( path, { page: p } ) } ) );
					}
					return Promise.allSettled( batch );
				};
				const keepFulfilledPrefix = results => {
					for ( const result of results ) {
						if ( result.status !== 'fulfilled' ) {
							break;
						}
						if ( Array.isArray( result.value ) ) {
							all.push( ...result.value );
						}
					}
					return results.findIndex( result => result.status === 'rejected' );
				};

				for ( let page = 2; page <= maxPage && ! cancelled; page += FETCH_ALL_CONCURRENCY ) {
					const lastPage = Math.min( page + FETCH_ALL_CONCURRENCY - 1, maxPage );

					let results = await fetchPages( page, lastPage );
					if ( cancelled ) {
						return;
					}
					let failedAt = keepFulfilledPrefix( results );

					if ( failedAt !== -1 && ! isOutOfRangePageError( results[ failedAt ].reason ) ) {
						results = await fetchPages( page + failedAt, lastPage );
						if ( cancelled ) {
							return;
						}
						failedAt = keepFulfilledPrefix( results );
						if ( failedAt !== -1 && ! isOutOfRangePageError( results[ failedAt ].reason ) ) {
							notifyError(
								__( 'Only some items could be loaded. Reload the page to try again.', 'newspack-newsletters' ),
								errorNoticeId ? { id: errorNoticeId } : undefined
							);
						}
					}

					setProgress( { loaded: all.length, total: pagination.totalItems } );
					if ( failedAt !== -1 ) {
						endedEarly = true;
						break;
					}
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
