/**
 * `useState` for a DataViews `view`, seeded from and persisted to the
 * current user's saved preferences (user meta via
 * `Admin_Shell_Preferences`). Only `perPage` is persisted for now —
 * the saved value follows the user across browsers, matching classic
 * Screen Options behaviour.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';

import { getViewPrefs } from '../admin-globals';
import { isValidPerPage } from '../utils/per-page';

const PREFERENCES_PATH = '/newspack-newsletters/v1/admin-shell/preferences';
const SAVE_DEBOUNCE_MS = 500;

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
	const timerRef = useRef( null );
	const requestSeqRef = useRef( 0 );
	const isUnmountingRef = useRef( false );

	// Cleans up before the effect below on unmount (effects clean up in
	// declaration order), so that effect can tell a true unmount apart from
	// a routine dependency change.
	useEffect(
		() => () => {
			isUnmountingRef.current = true;
		},
		[]
	);

	useEffect( () => {
		if ( view.perPage === lastSavedRef.current || ! isValidPerPage( view.perPage ) ) {
			return undefined;
		}
		const { perPage } = view;
		const save = () => {
			timerRef.current = null;
			const seq = ++requestSeqRef.current;
			apiFetch( {
				path: PREFERENCES_PATH,
				method: 'POST',
				data: { screen: screenKey, prefs: { perPage } },
			} )
				.then( () => {
					// A newer save may have been issued (and even settled) while
					// this one was in flight — don't let it clobber that state.
					if ( seq === requestSeqRef.current ) {
						lastSavedRef.current = perPage;
					}
				} )
				.catch( () => {} );
		};
		timerRef.current = setTimeout( save, SAVE_DEBOUNCE_MS );
		return () => {
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
				timerRef.current = null;
				if ( isUnmountingRef.current ) {
					save();
				}
			}
		};
	}, [ view.perPage, screenKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return [ view, setView ];
}
