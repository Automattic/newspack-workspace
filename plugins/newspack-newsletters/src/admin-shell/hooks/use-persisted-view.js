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
import { isValidPerPage } from '../utils/per-page';

const PREFERENCES_PATH = '/newspack-newsletters/v1/admin-shell/preferences';

/**
 * @param {string} screenKey   Screen identifier (allowlisted server-side).
 * @param {Object} defaultView Default view state.
 * @return {[Object, Function]} `[ view, setView ]` pair.
 */
export default function usePersistedView( screenKey, defaultView ) {
	const [ view, setView ] = useState( () => {
		const perPage = getViewPrefs()[ screenKey ]?.perPage;
		return isValidPerPage( perPage ) ? { ...defaultView, perPage } : defaultView;
	} );

	const lastSavedRef = useRef( view.perPage );
	// The value the user last chose — may differ from `lastSavedRef` while a
	// save for an older value is still in flight.
	const desiredRef = useRef( view.perPage );
	const inFlightRef = useRef( false );

	useEffect( () => {
		desiredRef.current = view.perPage;
		if ( view.perPage === lastSavedRef.current || ! isValidPerPage( view.perPage ) || inFlightRef.current ) {
			return;
		}
		// One at a time — concurrent writes could land out of order.
		const save = perPage => {
			inFlightRef.current = true;
			apiFetch( {
				path: PREFERENCES_PATH,
				method: 'POST',
				data: { screen: screenKey, prefs: { perPage } },
			} )
				.then( () => {
					lastSavedRef.current = perPage;
				} )
				.catch( () => {} )
				.finally( () => {
					inFlightRef.current = false;
					// Changed mid-flight: the effect's guard skipped it, so correct here.
					if ( desiredRef.current !== perPage && isValidPerPage( desiredRef.current ) ) {
						save( desiredRef.current );
					}
				} );
		};
		save( view.perPage );
	}, [ view.perPage, screenKey ] );

	return [ view, setView ];
}
