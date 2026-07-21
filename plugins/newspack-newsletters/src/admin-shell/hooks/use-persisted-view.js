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
	const requestSeqRef = useRef( 0 );

	useEffect( () => {
		desiredRef.current = view.perPage;
		if ( view.perPage === lastSavedRef.current || ! isValidPerPage( view.perPage ) ) {
			return;
		}
		const save = perPage => {
			const seq = ++requestSeqRef.current;
			apiFetch( {
				path: PREFERENCES_PATH,
				method: 'POST',
				data: { screen: screenKey, prefs: { perPage } },
			} )
				.then( () => {
					// A newer save was issued while this one was in flight — it
					// owns the state now.
					if ( seq !== requestSeqRef.current ) {
						return;
					}
					lastSavedRef.current = perPage;
					// Reverting mid-flight leaves the effect's guard satisfied, so
					// nothing else would correct the stored value.
					if ( perPage !== desiredRef.current ) {
						save( desiredRef.current );
					}
				} )
				.catch( () => {} );
		};
		save( view.perPage );
	}, [ view.perPage, screenKey ] );

	return [ view, setView ];
}
