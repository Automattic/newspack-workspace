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
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const PATH = '/newspack/v1/wizard/newspack-subscribers/groups';

/**
 * Fetch every group subscription on the site, hydrated for the group list.
 *
 * @return {{ groups: Array, loading: boolean }} The full group set plus loading state.
 */
export function useGroups() {
	const [ groups, setGroups ] = useState( [] );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: PATH } )
			.then( response => {
				if ( ! cancelled ) {
					setGroups( response?.items || [] );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setGroups( [] );
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
	}, [] );

	return { groups, loading };
}
