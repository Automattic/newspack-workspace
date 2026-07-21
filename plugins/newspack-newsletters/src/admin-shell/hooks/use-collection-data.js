import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

import { notifyError } from '../notices';

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

/**
 * Server-side paginated fetch hook for DataView list screens.
 *
 * A falsy `path` defers the main fetch (used by layouts during the
 * parent's `view === null` latch). A falsy `trashCountPath` skips the
 * trash sub-fetch — `hasResolved` flips solely on the main resolution.
 *
 * When `fetchAll` is set, the first response's `X-WP-TotalPages` drives
 * a walk over the remaining pages (the REST API caps `per_page` at 100).
 * Items render incrementally as pages land; `progress` reports the walk
 * (`{ loaded, total }`, `null` outside a walk) and `totalPages` is
 * clamped to 1 so the footer doesn't offer pagination.
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
				setData( all.slice() );
				setPaginationInfo( { totalItems: pagination.totalItems, totalPages: 1 } );
				setHasLoadedOnce( true );

				if ( pagination.totalPages <= 1 ) {
					return;
				}

				setProgress( { loaded: all.length, total: pagination.totalItems } );
				for ( let page = 2; page <= pagination.totalPages && ! cancelled; page += FETCH_ALL_CONCURRENCY ) {
					const batch = [];
					for ( let p = page; p <= Math.min( page + FETCH_ALL_CONCURRENCY - 1, pagination.totalPages ); p++ ) {
						batch.push( apiFetch( { path: addQueryArgs( path, { page: p } ), parse: false } ).then( r => r.json() ) );
					}
					const pages = await Promise.all( batch );
					if ( cancelled ) {
						return;
					}
					pages.forEach( pageItems => {
						if ( Array.isArray( pageItems ) ) {
							all.push( ...pageItems );
						}
					} );
					setData( all.slice() );
					setProgress( { loaded: all.length, total: pagination.totalItems } );
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
