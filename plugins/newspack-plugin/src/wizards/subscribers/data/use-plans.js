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
 * Unlike the list hooks there is no error or loading state to return: these
 * names only populate a filter dropdown, and the table is fully usable without
 * them. A failure degrades to an empty option list rather than blocking or
 * erroring the screen — the subscribers read raises its own notice for the same
 * failure mode, so a second one would only duplicate it.
 *
 * @return {string[]} Plan names, alphabetised by the endpoint. Empty until loaded.
 */
export function usePlans() {
	const [ plans, setPlans ] = useState( [] );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: PATH } )
			.then( response => {
				if ( ! cancelled ) {
					setPlans( response?.items || [] );
				}
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return plans;
}
