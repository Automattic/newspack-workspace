/**
 * `useState` for a DataViews `view`, seeded from and persisted to the
 * current user's saved preferences (user meta via
 * `Admin_Shell_Preferences`), so a configured list survives a reload and
 * follows the user across browsers, matching classic Screen Options.
 *
 * Persisted: the presentation half of the view — layout type, sort,
 * visible fields (in their display order and with their toggles),
 * density, grid preview size and items per page. Not persisted: page,
 * search and filters, which are a query rather than a configuration.
 *
 * Column widths (`view.layout.styles`) are not stored: the lists work them
 * out on every render with `evenColumns` and strip them before saving.
 *
 * Saves are debounced because the grid's preview-size slider streams
 * changes, and flushed on `pagehide` so navigating away right after a
 * click can't drop the preference.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getViewPrefs } from '../admin-globals';
import { notifyError } from '../notices';
import { DEFAULT_PER_PAGE_OPTIONS, isValidPerPage } from '../utils/per-page';

import type { Dispatch, SetStateAction } from 'react';
import type { View } from '../types';

/** Sizing/alignment for one persisted table column. */
interface ColumnStylePref {
	width?: string | number;
	minWidth?: string | number;
	maxWidth?: string | number;
	align?: 'start' | 'center' | 'end';
}

/** The storable slice of a view, as sent to and read back from the server. */
interface StoredPrefs {
	perPage?: number;
	type?: string;
	titleField?: string;
	fields?: string[];
	sort?: { field: string; direction: string };
	showTitle?: boolean;
	showMedia?: boolean;
	showDescription?: boolean;
	layout?: {
		density?: string;
		previewSize?: number;
		styles?: Record< string, ColumnStylePref >;
	};
}

/**
 * A view as this hook reads it: the DataViews `View` plus the loose
 * presentation keys the persistence layer serialises. `View` is assignable
 * to it, so the public signature can take the strict type while the helpers
 * work against this superset.
 */
interface PersistableView {
	type?: string;
	perPage?: number;
	titleField?: string;
	fields?: string[];
	sort?: { field?: string; direction?: string };
	showTitle?: boolean;
	showMedia?: boolean;
	showDescription?: boolean;
	layout?: {
		density?: string;
		previewSize?: number;
		styles?: Record< string, unknown >;
	};
}

/** Screen bindings passed to `usePersistedView`. */
interface PersistOptions {
	perPageOptions?: number[];
	fieldIds?: string[] | null;
	layoutTypes?: string[] | null;
	urlPatch?: Partial< View > | null;
	normalize?: ( ( view: View ) => View ) | null;
}

// Membership test that also narrows the checked value to the array's element
// type — the stored-pref validators below rely on the narrowing.
function isOneOf< T extends string | number >( list: readonly T[], value: unknown ): value is T {
	return list.some( item => item === value );
}

const PREFERENCES_PATH = '/newspack-newsletters/v1/admin-shell/preferences';

const SAVE_DEBOUNCE_MS = 500;

const SAVE_ERROR_NOTICE_ID = 'newspack-newsletters-view-prefs-save-error';

// Mirrors `Admin_Shell_Preferences::MAX_FIELDS`, which rejects a longer
// list outright rather than truncating it.
const MAX_FIELDS = 50;

// Mirrors `Admin_Shell_Preferences::LAYOUT_TYPES`. A screen's own
// `layoutTypes` is narrower than this today, but it is the screen's list, so
// it can't stand in for the server enum.
const LAYOUT_TYPES = [ 'table', 'grid', 'list' ] as const;

// Mirrors `Admin_Shell_Preferences::DENSITIES`.
const DENSITIES = [ 'compact', 'balanced', 'comfortable' ] as const;

const SORT_DIRECTIONS = [ 'asc', 'desc' ] as const;

// DataViews renders these in View options alongside the field
// checkboxes. Mirrors `Admin_Shell_Preferences::VISIBILITY_FLAGS`.
const VISIBILITY_FLAGS = [ 'showTitle', 'showMedia', 'showDescription' ] as const;

// Mirrors `Admin_Shell_Preferences::MAX_PREVIEW_SIZE`. The grid stores a
// pixel width here, not the slider position.
const MAX_PREVIEW_SIZE = 4000;

const isPreviewSize = ( value: unknown ): value is number =>
	typeof value === 'number' && Number.isInteger( value ) && value >= 0 && value <= MAX_PREVIEW_SIZE;

const isNonEmptyString = ( value: unknown ): value is string => typeof value === 'string' && value.length > 0;

// Mirrors `Admin_Shell_Preferences::ALIGNMENTS`.
const ALIGNMENTS = [ 'start', 'center', 'end' ] as const;

// Picked key by key rather than forwarded whole: DataViews owns this
// shape, and one unknown key would fail validation for the whole payload.
function toColumnStyles( styles: Record< string, unknown > ): Record< string, ColumnStylePref > {
	const clean: Record< string, ColumnStylePref > = {};
	for ( const [ id, style ] of Object.entries( styles ) ) {
		if ( ! style || typeof style !== 'object' ) {
			continue;
		}
		// Stored view data; each key is validated below.
		const styleRecord = style as Record< string, unknown >;
		const picked: ColumnStylePref = {};
		for ( const key of [ 'width', 'minWidth', 'maxWidth' ] as const ) {
			const value = styleRecord[ key ];
			// NaN and Infinity serialise to `null`, which the schema rejects.
			if ( Number.isFinite( value ) || isNonEmptyString( value ) ) {
				picked[ key ] = value as string | number;
			}
		}
		if ( isOneOf( ALIGNMENTS, styleRecord.align ) ) {
			picked.align = styleRecord.align;
		}
		if ( Object.keys( picked ).length > 0 ) {
			clean[ id ] = picked;
		}
	}
	return clean;
}

// The server rejects a payload with nothing storable in it, so don't send one.
const isSavable = ( payload: string ): boolean => payload !== '{}';

// Key order follows insertion, so sort: an unchanged view must not
// serialise differently and trigger a save on its own.
function stableStringify( value: unknown ): string {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( stableStringify ).join( ',' ) }]`;
	}
	if ( value && typeof value === 'object' ) {
		const record = value as Record< string, unknown >;
		const entries = Object.keys( record )
			.sort()
			.map( key => `${ JSON.stringify( key ) }:${ stableStringify( record[ key ] ) }` );
		return `{${ entries.join( ',' ) }}`;
	}
	return JSON.stringify( value ) ?? 'null';
}

/**
 * Extract the storable slice of a view.
 *
 * Every value is held to the same bounds the route enforces: the schema
 * fails the whole request on the first value it rejects, so one key out
 * of range would stop every appearance setting persisting.
 *
 * @param view DataViews view state.
 * @return Preferences payload.
 */
function toPrefs( view: PersistableView = {}, { layoutTypes = null }: { layoutTypes?: string[] | null } = {} ): StoredPrefs {
	const prefs: StoredPrefs = {};

	if ( isValidPerPage( view.perPage ) ) {
		prefs.perPage = view.perPage;
	}
	if ( isOneOf( LAYOUT_TYPES, view.type ) && ( ! layoutTypes || isOneOf( layoutTypes, view.type ) ) ) {
		prefs.type = view.type;
	}
	if ( isNonEmptyString( view.titleField ) ) {
		prefs.titleField = view.titleField;
	}
	if ( Array.isArray( view.fields ) ) {
		prefs.fields = view.fields.filter( isNonEmptyString ).slice( 0, MAX_FIELDS );
	}
	if ( isNonEmptyString( view.sort?.field ) ) {
		prefs.sort = {
			field: view.sort.field,
			direction: isOneOf( SORT_DIRECTIONS, view.sort?.direction ) ? view.sort?.direction : 'desc',
		};
	}
	for ( const flag of VISIBILITY_FLAGS ) {
		if ( typeof view[ flag ] === 'boolean' ) {
			prefs[ flag ] = view[ flag ];
		}
	}

	const layout: NonNullable< StoredPrefs[ 'layout' ] > = {};
	if ( isOneOf( DENSITIES, view.layout?.density ) ) {
		layout.density = view.layout?.density;
	}
	if ( isPreviewSize( view.layout?.previewSize ) ) {
		layout.previewSize = view.layout?.previewSize;
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
function toViewPatch(
	prefs: unknown,
	{ perPageOptions, fieldIds, layoutTypes }: { perPageOptions: number[]; fieldIds?: string[] | null; layoutTypes?: string[] | null }
): PersistableView {
	const patch: PersistableView = {};
	if ( ! prefs || typeof prefs !== 'object' ) {
		return patch;
	}

	// Stored, untrusted data; every field is re-validated below.
	const stored = prefs as StoredPrefs;

	const isKnownField = ( id: string ): boolean => ! fieldIds || isOneOf( fieldIds, id );

	// A stored value this screen doesn't offer would leave the control
	// with nothing highlighted, so fall back to the default.
	if ( isValidPerPage( stored.perPage ) && isOneOf( perPageOptions, stored.perPage ) ) {
		patch.perPage = stored.perPage;
	}

	if ( isNonEmptyString( stored.type ) && ( ! layoutTypes || isOneOf( layoutTypes, stored.type ) ) ) {
		patch.type = stored.type;
	}

	if ( isNonEmptyString( stored.titleField ) && isKnownField( stored.titleField ) ) {
		patch.titleField = stored.titleField;
	}

	if ( Array.isArray( stored.fields ) ) {
		const fields = stored.fields.filter( ( id ): id is string => isNonEmptyString( id ) && isKnownField( id ) );
		// An empty stored array means "hide every column". An array the
		// filter emptied means those columns are gone, so use the default.
		if ( fields.length > 0 || stored.fields.length === 0 ) {
			patch.fields = fields;
		}
	}

	if ( isNonEmptyString( stored.sort?.field ) && isKnownField( stored.sort.field ) ) {
		patch.sort = {
			field: stored.sort.field,
			direction: isOneOf( SORT_DIRECTIONS, stored.sort?.direction ) ? stored.sort?.direction : 'desc',
		};
	}

	for ( const flag of VISIBILITY_FLAGS ) {
		if ( typeof stored[ flag ] === 'boolean' ) {
			patch[ flag ] = stored[ flag ];
		}
	}

	const layout: NonNullable< PersistableView[ 'layout' ] > = {};
	if ( isOneOf( DENSITIES, stored.layout?.density ) ) {
		layout.density = stored.layout?.density;
	}
	if ( isPreviewSize( stored.layout?.previewSize ) ) {
		layout.previewSize = stored.layout?.previewSize;
	}
	const storedStyles = stored.layout?.styles;
	if ( storedStyles && typeof storedStyles === 'object' ) {
		const styles: Record< string, unknown > = {};
		for ( const [ id, style ] of Object.entries( storedStyles ) ) {
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
 * @param screenKey   Screen identifier (allowlisted server-side).
 * @param defaultView Default view state.
 * @return `[ view, setView ]` pair.
 */
export default function usePersistedView(
	screenKey: string,
	defaultView: View,
	{ perPageOptions = DEFAULT_PER_PAGE_OPTIONS, fieldIds = null, layoutTypes = null, urlPatch = null, normalize = null }: PersistOptions = {}
): [ View, Dispatch< SetStateAction< View > > ] {
	const [ view, setView ] = useState< View >( () => {
		// Validated prefs merged onto a known-good view, so the result is a View.
		const merged = {
			...defaultView,
			...toViewPatch( ( getViewPrefs() as Record< string, unknown > )[ screenKey ], { perPageOptions, fieldIds, layoutTypes } ),
			...( urlPatch || {} ),
		} as View;
		return normalize ? normalize( merged ) : merged;
	} );

	const serialized = useMemo( () => stableStringify( toPrefs( view, { layoutTypes } ) ), [ view, layoutTypes ] );

	// The mounted view is the baseline, so nothing is written on arrival.
	// A legacy deep link's sort rides along on the first save after that.
	const lastSavedRef = useRef( serialized );
	const desiredRef = useRef( serialized );
	const inFlightRef = useRef< { controller: AbortController | null } | null >( null );
	const unloadingRef = useRef( false );
	const saveFailureReportedRef = useRef( false );
	const flushRef = useRef< ( options?: { keepalive?: boolean } ) => void >( () => {} );

	useEffect( () => {
		// A rejected payload fails identically on a retry; only a transport
		// blip or a server fault is worth sending twice.
		const isRetryable = ( reason: unknown ): boolean => {
			const status = ( reason as { data?: { status?: number } } )?.data?.status;
			return ! status || status >= 500;
		};

		// A drifted schema fails every save, so report the first one and stay
		// quiet after that rather than raising a snackbar per density toggle.
		const reportFailure = ( reason: unknown ): void => {
			if ( saveFailureReportedRef.current ) {
				return;
			}
			saveFailureReportedRef.current = true;
			console.warn( '[newspack-newsletters] Saving view preferences failed.', ( reason as { code?: unknown } )?.code ?? reason ); // eslint-disable-line no-console
			notifyError( __( 'Your view settings could not be saved, so they will not be restored next time.', 'newspack-newsletters' ), {
				id: SAVE_ERROR_NOTICE_ID,
			} );
		};

		// One at a time — concurrent writes could land out of order.
		const save = ( payload: string, { attempt = 0, keepalive = false }: { attempt?: number; keepalive?: boolean } = {} ): void => {
			let failed = false;
			let failureReason: unknown = null;
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
					// A blip that recovers shouldn't leave the latch spent, or
					// a genuine failure later in the session goes unreported.
					saveFailureReportedRef.current = false;
				} )
				.catch( error => {
					failed = true;
					failureReason = error;
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
					if ( ! failed ) {
						return;
					}
					// Nothing else will retrigger the effect, so retry once —
					// still keepalive if the page is on its way out.
					if ( attempt < 1 && isRetryable( failureReason ) ) {
						save( payload, { attempt: attempt + 1, keepalive } );
						return;
					}
					// A keepalive write is the unload flush, and there is no
					// page left to show a notice on.
					if ( ! keepalive ) {
						reportFailure( failureReason );
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
