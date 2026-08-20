/**
 * `useState` for a DataViews `view`, seeded from and persisted to the
 * current user's saved preferences (user meta via
 * `Admin_Shell_Preferences`), so a configured list survives a reload and
 * follows the user across browsers, matching classic Screen Options.
 *
 * Persisted: the presentation half of the view — layout type, sort,
 * visible fields (in their display order and with their toggles),
 * density, column widths and items per page. Not persisted: page,
 * search and filters, which are a query rather than a configuration.
 *
 * Saves are debounced because column resizing streams changes, and
 * flushed on `pagehide` so navigating away right after a click can't
 * drop the preference.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';

import { getViewPrefs } from '../admin-globals';
import { DEFAULT_PER_PAGE_OPTIONS, isValidPerPage } from '../utils/per-page';

const PREFERENCES_PATH = '/newspack-newsletters/v1/admin-shell/preferences';

const SAVE_DEBOUNCE_MS = 500;

// Mirrors `Admin_Shell_Preferences::DENSITIES`.
const DENSITIES = [ 'compact', 'balanced', 'comfortable' ];

const SORT_DIRECTIONS = [ 'asc', 'desc' ];

// DataViews renders these in View options alongside the field
// checkboxes. Mirrors `Admin_Shell_Preferences::VISIBILITY_FLAGS`.
const VISIBILITY_FLAGS = [ 'showTitle', 'showMedia', 'showDescription' ];

// Mirrors `Admin_Shell_Preferences::MAX_PREVIEW_SIZE`. The grid stores a
// pixel width here, not the slider position.
const MAX_PREVIEW_SIZE = 4000;

const isPreviewSize = value => Number.isInteger( value ) && value >= 0 && value <= MAX_PREVIEW_SIZE;

const isNonEmptyString = value => typeof value === 'string' && value.length > 0;

// Mirrors `Admin_Shell_Preferences::ALIGNMENTS`.
const ALIGNMENTS = [ 'start', 'center', 'end' ];

// Picked key by key rather than forwarded whole: DataViews owns this
// shape, and one unknown key would fail validation for the whole payload.
function toColumnStyles( styles ) {
	const clean = {};
	for ( const [ id, style ] of Object.entries( styles ) ) {
		if ( ! style || typeof style !== 'object' ) {
			continue;
		}
		const picked = {};
		for ( const key of [ 'width', 'minWidth', 'maxWidth' ] ) {
			// NaN and Infinity serialise to `null`, which the schema rejects.
			if ( Number.isFinite( style[ key ] ) || isNonEmptyString( style[ key ] ) ) {
				picked[ key ] = style[ key ];
			}
		}
		if ( ALIGNMENTS.includes( style.align ) ) {
			picked.align = style.align;
		}
		if ( Object.keys( picked ).length > 0 ) {
			clean[ id ] = picked;
		}
	}
	return clean;
}

// The server rejects a payload with nothing storable in it, so don't send one.
const isSavable = payload => payload !== '{}';

// Key order follows insertion, so sort: an unchanged view must not
// serialise differently and trigger a save on its own.
function stableStringify( value ) {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( stableStringify ).join( ',' ) }]`;
	}
	if ( value && typeof value === 'object' ) {
		const entries = Object.keys( value )
			.sort()
			.map( key => `${ JSON.stringify( key ) }:${ stableStringify( value[ key ] ) }` );
		return `{${ entries.join( ',' ) }}`;
	}
	return JSON.stringify( value ) ?? 'null';
}

/**
 * Extract the storable slice of a view.
 *
 * @param {Object} view DataViews view state.
 * @return {Object} Preferences payload.
 */
function toPrefs( view = {} ) {
	const prefs = {};

	if ( isValidPerPage( view.perPage ) ) {
		prefs.perPage = view.perPage;
	}
	if ( isNonEmptyString( view.type ) ) {
		prefs.type = view.type;
	}
	if ( isNonEmptyString( view.titleField ) ) {
		prefs.titleField = view.titleField;
	}
	if ( Array.isArray( view.fields ) ) {
		prefs.fields = view.fields.filter( isNonEmptyString );
	}
	if ( isNonEmptyString( view.sort?.field ) ) {
		prefs.sort = {
			field: view.sort.field,
			direction: SORT_DIRECTIONS.includes( view.sort.direction ) ? view.sort.direction : 'desc',
		};
	}
	for ( const flag of VISIBILITY_FLAGS ) {
		if ( typeof view[ flag ] === 'boolean' ) {
			prefs[ flag ] = view[ flag ];
		}
	}

	const layout = {};
	if ( DENSITIES.includes( view.layout?.density ) ) {
		layout.density = view.layout.density;
	}
	if ( isPreviewSize( view.layout?.previewSize ) ) {
		layout.previewSize = view.layout.previewSize;
	}
	const styles = view.layout?.styles;
	if ( styles && typeof styles === 'object' ) {
		const picked = toColumnStyles( styles );
		if ( Object.keys( picked ).length > 0 ) {
			layout.styles = picked;
		}
	}
	if ( Object.keys( layout ).length > 0 ) {
		prefs.layout = layout;
	}

	return prefs;
}

/**
 * Turn stored preferences into a view patch, dropping anything this
 * screen no longer offers.
 *
 * The server validates shape and size, not a per-screen field
 * allowlist — a value saved before a column was renamed or removed is
 * dropped here rather than left to render an empty column.
 *
 * @param {Object}        prefs                  Stored preferences.
 * @param {Object}        options                Screen bindings.
 * @param {Array<number>} options.perPageOptions Values this screen's control offers.
 * @param {Array<string>} [options.fieldIds]     Field IDs this screen defines.
 * @param {Array<string>} [options.layoutTypes]  Layout types this screen offers.
 * @return {Object} View patch.
 */
function toViewPatch( prefs, { perPageOptions, fieldIds, layoutTypes } ) {
	const patch = {};
	if ( ! prefs || typeof prefs !== 'object' ) {
		return patch;
	}

	const isKnownField = id => ! fieldIds || fieldIds.includes( id );

	// A stored value this screen doesn't offer would leave the control
	// with nothing highlighted, so fall back to the default.
	if ( isValidPerPage( prefs.perPage ) && perPageOptions.includes( prefs.perPage ) ) {
		patch.perPage = prefs.perPage;
	}

	if ( isNonEmptyString( prefs.type ) && ( ! layoutTypes || layoutTypes.includes( prefs.type ) ) ) {
		patch.type = prefs.type;
	}

	if ( isNonEmptyString( prefs.titleField ) && isKnownField( prefs.titleField ) ) {
		patch.titleField = prefs.titleField;
	}

	if ( Array.isArray( prefs.fields ) ) {
		const fields = prefs.fields.filter( id => isNonEmptyString( id ) && isKnownField( id ) );
		// An empty stored array means "hide every column". An array the
		// filter emptied means those columns are gone, so use the default.
		if ( fields.length > 0 || prefs.fields.length === 0 ) {
			patch.fields = fields;
		}
	}

	if ( isNonEmptyString( prefs.sort?.field ) && isKnownField( prefs.sort.field ) ) {
		patch.sort = {
			field: prefs.sort.field,
			direction: SORT_DIRECTIONS.includes( prefs.sort.direction ) ? prefs.sort.direction : 'desc',
		};
	}

	for ( const flag of VISIBILITY_FLAGS ) {
		if ( typeof prefs[ flag ] === 'boolean' ) {
			patch[ flag ] = prefs[ flag ];
		}
	}

	const layout = {};
	if ( DENSITIES.includes( prefs.layout?.density ) ) {
		layout.density = prefs.layout.density;
	}
	if ( isPreviewSize( prefs.layout?.previewSize ) ) {
		layout.previewSize = prefs.layout.previewSize;
	}
	if ( prefs.layout?.styles && typeof prefs.layout.styles === 'object' ) {
		const styles = {};
		for ( const [ id, style ] of Object.entries( prefs.layout.styles ) ) {
			if ( isKnownField( id ) && style && typeof style === 'object' ) {
				styles[ id ] = style;
			}
		}
		if ( Object.keys( styles ).length > 0 ) {
			layout.styles = styles;
		}
	}
	if ( Object.keys( layout ).length > 0 ) {
		patch.layout = layout;
	}

	return patch;
}

/**
 * @param {string}        screenKey                Screen identifier (allowlisted server-side).
 * @param {Object}        defaultView              Default view state.
 * @param {Object}        [options]                Screen bindings.
 * @param {Array<number>} [options.perPageOptions] Values this screen's control offers.
 * @param {Array<string>} [options.fieldIds]       Field IDs this screen defines.
 * @param {Array<string>} [options.layoutTypes]    Layout types this screen offers.
 * @param {Object}        [options.urlPatch]       View patch seeded from the URL. Applied last, so a
 *                                                 forwarded legacy link still wins over saved preferences.
 * @param {Function}      [options.normalize]      Reconciles the restored view with the screen's own rules. Runs on
 *                                                 the merged view, so settings a stored layout type implies are
 *                                                 applied at mount rather than only on the next change.
 * @return {[Object, Function]} `[ view, setView ]` pair.
 */
export default function usePersistedView(
	screenKey,
	defaultView,
	{ perPageOptions = DEFAULT_PER_PAGE_OPTIONS, fieldIds = null, layoutTypes = null, urlPatch = null, normalize = null } = {}
) {
	const [ view, setView ] = useState( () => {
		const merged = {
			...defaultView,
			...toViewPatch( getViewPrefs()[ screenKey ], { perPageOptions, fieldIds, layoutTypes } ),
			...( urlPatch || {} ),
		};
		return normalize ? normalize( merged ) : merged;
	} );

	const serialized = useMemo( () => stableStringify( toPrefs( view ) ), [ view ] );

	// The mounted view is the baseline, so nothing is written on arrival.
	// A legacy deep link's sort rides along on the first save after that.
	const lastSavedRef = useRef( serialized );
	const desiredRef = useRef( serialized );
	const inFlightRef = useRef( null );
	const unloadingRef = useRef( false );
	const flushRef = useRef( () => {} );

	useEffect( () => {
		// One at a time — concurrent writes could land out of order.
		const save = ( payload, { attempt = 0, keepalive = false } = {} ) => {
			let failed = false;
			const controller = 'undefined' === typeof AbortController ? null : new AbortController();
			const inFlight = { controller };
			inFlightRef.current = inFlight;
			apiFetch( {
				path: PREFERENCES_PATH,
				method: 'POST',
				data: { screen: screenKey, prefs: JSON.parse( payload ) },
				...( keepalive ? { keepalive: true } : {} ),
				...( controller ? { signal: controller.signal } : {} ),
			} )
				.then( () => {
					lastSavedRef.current = payload;
				} )
				.catch( () => {
					failed = true;
				} )
				.finally( () => {
					// Only if this is still the write in flight: an aborted
					// request settles after its replacement has started.
					if ( inFlightRef.current === inFlight ) {
						inFlightRef.current = null;
					}
					// The page is going: a chained save or retry goes with it.
					if ( unloadingRef.current ) {
						return;
					}
					if ( desiredRef.current !== payload && isSavable( desiredRef.current ) ) {
						save( desiredRef.current );
						return;
					}
					// Nothing else will retrigger the effect, so retry once.
					if ( failed && attempt < 1 ) {
						save( payload, { attempt: attempt + 1 } );
					}
				} );
		};

		flushRef.current = ( { keepalive = false } = {} ) => {
			const payload = desiredRef.current;
			if ( payload === lastSavedRef.current || ! isSavable( payload ) ) {
				return;
			}
			if ( inFlightRef.current ) {
				if ( ! keepalive ) {
					return;
				}
				// Replace the write in flight: it was started without
				// `keepalive`, so navigation can tear it down, and if it did
				// survive it could land after this one.
				unloadingRef.current = true;
				inFlightRef.current.controller?.abort();
			}
			save( payload, { keepalive } );
		};
	}, [ screenKey ] );

	useEffect( () => {
		desiredRef.current = serialized;
		if ( serialized === lastSavedRef.current ) {
			return undefined;
		}
		const timer = setTimeout( () => flushRef.current(), SAVE_DEBOUNCE_MS );
		return () => clearTimeout( timer );
	}, [ serialized ] );

	useEffect( () => {
		const flushNow = () => flushRef.current( { keepalive: true } );
		// Restored from the back/forward cache: resume, and re-send
		// anything the freeze dropped.
		const resume = () => {
			unloadingRef.current = false;
			flushRef.current();
		};
		window.addEventListener( 'pagehide', flushNow );
		window.addEventListener( 'pageshow', resume );
		return () => {
			window.removeEventListener( 'pagehide', flushNow );
			window.removeEventListener( 'pageshow', resume );
			// Navigating between screens unmounts before a debounced save fires.
			flushRef.current();
		};
	}, [] );

	return [ view, setView ];
}
