/**
 * The site's plan names, for the subscriber list's plan filter.
 *
 * The group list derives its plan options from the groups it has already loaded,
 * because it loads them all. The subscriber list can't: it is server-paginated,
 * so the plans on the current page are not the plans on the site. This hook
 * fetches the whole set from the endpoint instead.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const PATH = '/newspack/v1/wizard/newspack-subscribers/plans';

/**
 * Fetch every plan name the subscriber list can be filtered by.
 *
 * Unlike the list hooks there is no loading state to return: these names only
 * populate a filter dropdown, and the table is fully usable without them. A
 * failure degrades to an empty option list rather than blocking or erroring the
 * screen, but it is reported as `failed` so the caller can distinguish it from a
 * site that simply sells no plans — an empty dropdown means two very different
 * things, and only one of them is worth telling the admin about.
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
				// The two routes fail independently, so the subscribers notice does not
				// necessarily cover this one: /plans can fail on its own and leave the
				// filter offering nothing, with no way for the admin to tell "no plans
				// configured" from "the read failed". The caller uses this to say which.
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
