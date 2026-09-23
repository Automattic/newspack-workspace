/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const PATH = '/newspack/v1/wizard/newspack-subscribers/plans';

/**
 * Fetch every plan name the subscriber list can be filtered by.
 *
 * The group list derives its options from the groups it has already loaded, because
 * it loads them all. This list is server-paginated, so the plans on the current page
 * are not the plans on the site; the whole set comes from the endpoint instead.
 *
 * Unlike the list hooks there is no loading state to return: these names only
 * populate a filter dropdown, and the table is fully usable without them. A failure
 * degrades to an empty option list rather than blocking the screen, and is reported
 * as `failed` because DataViews drops a filter with no options — leaving nothing on
 * screen to tell a failed read from a site that sells no plans.
 *
 * @return {{plans: string[], failed: boolean}} Plan names, alphabetised by the
 *                                              endpoint, and whether the read failed.
 */
export function usePlans() {
	const [ plans, setPlans ] = useState( [] );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: PATH } )
			.then( response => {
				if ( ! cancelled ) {
					setPlans( response?.items || [] );
					setFailed( false );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setFailed( true );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return { plans, failed };
}
