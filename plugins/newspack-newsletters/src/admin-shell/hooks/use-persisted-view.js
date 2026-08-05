/**
 * `useState` for a DataViews `view`, seeded from and persisted to the
 * current user's saved preferences (user meta via
 * `Admin_Shell_Preferences`). Only `perPage` is persisted for now —
 * the saved value follows the user across browsers, matching classic
 * Screen Options behaviour.
 *
 * The save is not debounced: `perPage` is a discrete control, so a change
 * costs at most one request per click, and firing immediately means a
 * navigation right after the click can't drop the preference.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';

import { getViewPrefs } from '../admin-globals';
import { DEFAULT_PER_PAGE_OPTIONS, isValidPerPage } from '../utils/per-page';

const PREFERENCES_PATH = '/newspack-newsletters/v1/admin-shell/preferences';

/**
 * @param {string}        screenKey        Screen identifier (allowlisted server-side).
 * @param {Object}        defaultView      Default view state.
 * @param {Array<number>} [perPageOptions] Values this screen's control offers.
 * @return {[Object, Function]} `[ view, setView ]` pair.
 */
export default function usePersistedView( screenKey, defaultView, perPageOptions = DEFAULT_PER_PAGE_OPTIONS ) {
	const [ view, setView ] = useState( () => {
		const perPage = getViewPrefs()[ screenKey ]?.perPage;
		// The server validates a range, not a per-screen set — a stored
		// value this screen doesn't offer (a legacy value, or one saved
		// on a screen with different steps) would leave the control with
		// nothing highlighted, so fall back to the default.
		return isValidPerPage( perPage ) && perPageOptions.includes( perPage ) ? { ...defaultView, perPage } : defaultView;
	} );

	const lastSavedRef = useRef( view.perPage );
	const desiredRef = useRef( view.perPage );
	const inFlightRef = useRef( false );

	useEffect( () => {
		desiredRef.current = view.perPage;
		if ( view.perPage === lastSavedRef.current || ! isValidPerPage( view.perPage ) || inFlightRef.current ) {
			return;
		}
		// One at a time — concurrent writes could land out of order.
		const save = ( perPage, attempt = 0 ) => {
			inFlightRef.current = true;
			let failed = false;
			apiFetch( {
				path: PREFERENCES_PATH,
				method: 'POST',
				data: { screen: screenKey, prefs: { perPage } },
			} )
				.then( () => {
					lastSavedRef.current = perPage;
				} )
				.catch( () => {
					failed = true;
				} )
				.finally( () => {
					inFlightRef.current = false;
					if ( desiredRef.current !== perPage && isValidPerPage( desiredRef.current ) ) {
						save( desiredRef.current );
						return;
					}
					// Nothing else will retrigger the effect, so retry once.
					if ( failed && attempt < 1 ) {
						save( perPage, attempt + 1 );
					}
				} );
		};
		save( view.perPage );
	}, [ view.perPage, screenKey ] );

	return [ view, setView ];
}
