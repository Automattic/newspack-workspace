/**
 * Site-wide group list.
 *
 * The group/team subscriptions on a site are few relative to readers, so the
 * endpoint returns them all in one response and the list filters, sorts and
 * paginates client-side (mirroring the prototype). This hook fetches that full
 * set once and returns it with a loading flag.
 */

/**
 * WordPress dependencies.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const PATH = '/newspack/v1/wizard/newspack-subscribers/groups';

/**
 * Fetch every group subscription on the site, hydrated for the group list.
 *
 * A failed request is reported as `error` rather than collapsing into an empty
 * set, so the screen can tell "this site has no groups" apart from "we could not
 * read them" and offer `reload` as a retry.
 *
 * @return {{ groups: Array, loading: boolean, error: string, reload: Function }} The full group set plus loading/error state.
 */
export function useGroups() {
	const [ groups, setGroups ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ attempt, setAttempt ] = useState( 0 );

	const reload = useCallback( () => setAttempt( n => n + 1 ), [] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		apiFetch( { path: PATH } )
			.then( response => {
				if ( ! cancelled ) {
					setGroups( response?.items || [] );
					setError( '' );
				}
			} )
			.catch( e => {
				if ( ! cancelled ) {
					setGroups( [] );
					setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ attempt ] );

	return { groups, loading, error, reload };
}
