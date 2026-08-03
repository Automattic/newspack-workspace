import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

import { notifyError } from '../notices';

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
 * @param {Object} options
 * @param {string} options.path             Pre-computed REST path. Falsy ⇒ defer.
 * @param {string} [options.trashCountPath] When set, sub-fetch for the trash banner.
 * @param {number} [options.mutationKey]    Bump externally to refetch (alongside internal refresh).
 * @param {string} [options.errorMessage]   notifyError message on fetch failure.
 * @param {string} [options.errorNoticeId]  notifyError dedupe id.
 * @return {{ data: Array, paginationInfo: Object, isLoading: boolean, hasResolved: boolean, hasLoadedOnce: boolean, trashCount: number|null, refresh: () => void }} Hook state.
 */
export default function useCollectionData( { path, trashCountPath = null, mutationKey = 0, errorMessage, errorNoticeId } ) {
	const [ data, setData ] = useState( [] );
	const [ paginationInfo, setPaginationInfo ] = useState( { totalItems: 0, totalPages: 0 } );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const [ mainResolved, setMainResolved ] = useState( false );
	const [ trashResolved, setTrashResolved ] = useState( ! trashCountPath );
	const [ hasLoadedOnce, setHasLoadedOnce ] = useState( false );
	// `null` ⇒ unknown; failed trash fetch stays `null` so `=== 0` stays false and the banner stays hidden.
	const [ trashCount, setTrashCount ] = useState( null );

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

		apiFetch( { path, parse: false } )
			.then( async response => {
				const items = await response.json();
				if ( cancelled ) {
					return;
				}
				setData( Array.isArray( items ) ? items : [] );
				setPaginationInfo( readPaginationInfo( response ) );
				setHasLoadedOnce( true );
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
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ path, mutationKey, refreshKey, errorMessage, errorNoticeId ] );

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

	return { data, paginationInfo, isLoading, hasResolved, hasLoadedOnce, trashCount, refresh };
}
