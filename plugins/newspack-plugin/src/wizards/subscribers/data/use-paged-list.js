/**
 * One server-paginated page of a wizard list.
 *
 * Shared by the subscriber and group lists, which both send their DataViews
 * view to the server as query params and render the `{ items, total, pages }`
 * envelope that comes back.
 */

/**
 * WordPress dependencies.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const EMPTY = { items: [], total: 0, pages: 0 };

/**
 * Fetch one page from `path` for the given query params.
 *
 * A failed request is reported as `error` rather than collapsing into an empty
 * page, so the screen can tell "there is nothing here" apart from "we could not
 * read it" and offer `reload` as a retry.
 *
 * `settled` turns true after the first response of either kind and never goes
 * back, so the caller can reserve the screen-blanking spinner for the load that
 * has nothing to show yet. Row count can't stand in for it: a filter matching
 * nobody empties `items`, and the next refetch would blank a screen the reader
 * is still interacting with.
 *
 * @param {string} path   REST path.
 * @param {Object} params Query params. Only a change in their serialized value refetches.
 * @return {{ response: Object, loading: boolean, settled: boolean, error: string, reload: Function }} The page.
 */
export function usePagedList( path, params ) {
	const [ response, setResponse ] = useState( EMPTY );
	const [ loading, setLoading ] = useState( true );
	const [ settled, setSettled ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ attempt, setAttempt ] = useState( 0 );

	const reload = useCallback( () => setAttempt( n => n + 1 ), [] );

	// Callers rebuild the params object on every render, so the effect keys on its
	// serialized value and refetches only when a query-affecting value changes.
	const key = JSON.stringify( params );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		apiFetch( { path: addQueryArgs( path, JSON.parse( key ) ) } )
			.then( result => {
				if ( cancelled ) {
					return;
				}
				setResponse( { ...EMPTY, ...result, items: result?.items || [], total: result?.total || 0, pages: result?.pages || 0 } );
				setError( '' );
			} )
			.catch( e => {
				if ( ! cancelled ) {
					setResponse( EMPTY );
					setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
					setSettled( true );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ path, key, attempt ] );

	return { response, loading, settled, error, reload };
}
