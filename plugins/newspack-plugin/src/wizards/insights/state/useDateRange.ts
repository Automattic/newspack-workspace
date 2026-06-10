/**
 * useDateRange
 *
 * Owns the active date range state for the Insights wizard. Hydrates
 * initial state from URL query (so refresh / direct links preserve range)
 * with fallback to the boot config default; persists changes back to URL
 * via history.replaceState (no history pollution).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';

export type DateRangePreset = 'last-7' | 'last-30' | 'last-90' | 'this-month' | 'last-month' | 'custom';

export interface DateRange {
	preset: DateRangePreset;
	start: string; // YYYY-MM-DD
	end: string; // YYYY-MM-DD
}

export interface DateRangePresetDef {
	key: DateRangePreset;
	label: string;
}

export const DATE_RANGE_PRESETS: DateRangePresetDef[] = [
	{ key: 'last-7', label: __( 'Last 7 days', 'newspack-plugin' ) },
	{ key: 'last-30', label: __( 'Last 30 days', 'newspack-plugin' ) },
	{ key: 'last-90', label: __( 'Last 90 days', 'newspack-plugin' ) },
	{ key: 'this-month', label: __( 'This month', 'newspack-plugin' ) },
	{ key: 'last-month', label: __( 'Last month', 'newspack-plugin' ) },
	{ key: 'custom', label: __( 'Custom', 'newspack-plugin' ) },
];

const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Validate a YYYY-MM-DD string. Checks both the shape AND that the parsed
 * date round-trips back to the same string — otherwise inputs like
 * '2026-99-99' would pass the regex and silently roll over to a future
 * month when used as a Date.
 */
const isValidISODate = ( s: unknown ): s is string => {
	if ( typeof s !== 'string' || ! ISO_DATE_RE.test( s ) ) {
		return false;
	}
	const [ y, m, d ] = s.split( '-' ).map( Number );
	const parsed = new Date( y, m - 1, d );
	return parsed.getFullYear() === y && parsed.getMonth() === m - 1 && parsed.getDate() === d;
};

const isPreset = ( v: unknown ): v is DateRangePreset => typeof v === 'string' && DATE_RANGE_PRESETS.some( p => p.key === v );

const pad2 = ( n: number ) => String( n ).padStart( 2, '0' );

const toISO = ( d: Date ): string => `${ d.getFullYear() }-${ pad2( d.getMonth() + 1 ) }-${ pad2( d.getDate() ) }`;

/**
 * Compute a range from a preset, anchored to today.
 *
 * "Last N days" presets produce an inclusive N-day window ending today —
 * e.g. "Last 7 days" on Jun 7 = Jun 1 → Jun 7 (7 days total). So we
 * subtract (N - 1) days, not N.
 *
 * Returns null for 'custom' — the caller supplies start/end directly.
 */
export const computeRangeForPreset = ( preset: DateRangePreset, today: Date = new Date() ): { start: string; end: string } | null => {
	if ( preset === 'custom' ) {
		return null;
	}
	const end = toISO( today );
	if ( preset === 'last-7' ) {
		const s = new Date( today );
		s.setDate( s.getDate() - 6 );
		return { start: toISO( s ), end };
	}
	if ( preset === 'last-30' ) {
		const s = new Date( today );
		s.setDate( s.getDate() - 29 );
		return { start: toISO( s ), end };
	}
	if ( preset === 'last-90' ) {
		const s = new Date( today );
		s.setDate( s.getDate() - 89 );
		return { start: toISO( s ), end };
	}
	if ( preset === 'this-month' ) {
		const s = new Date( today.getFullYear(), today.getMonth(), 1 );
		return { start: toISO( s ), end };
	}
	if ( preset === 'last-month' ) {
		const s = new Date( today.getFullYear(), today.getMonth() - 1, 1 );
		const e = new Date( today.getFullYear(), today.getMonth(), 0 );
		return { start: toISO( s ), end: toISO( e ) };
	}
	return null;
};

const readUrl = (): Partial< DateRange > => {
	if ( typeof window === 'undefined' ) {
		return {};
	}
	const params = new URLSearchParams( window.location.search );
	const preset = params.get( 'range' );
	const start = params.get( 'start' );
	const end = params.get( 'end' );
	const out: Partial< DateRange > = {};
	if ( isPreset( preset ) ) {
		out.preset = preset;
	}
	if ( isValidISODate( start ) ) {
		out.start = start;
	}
	if ( isValidISODate( end ) ) {
		out.end = end;
	}
	return out;
};

const writeUrl = ( range: DateRange ) => {
	if ( typeof window === 'undefined' ) {
		return;
	}
	const params = new URLSearchParams( window.location.search );
	params.set( 'range', range.preset );
	if ( range.preset === 'custom' ) {
		params.set( 'start', range.start );
		params.set( 'end', range.end );
	} else {
		params.delete( 'start' );
		params.delete( 'end' );
	}
	const next = `${ window.location.pathname }?${ params.toString() }${ window.location.hash }`;
	window.history.replaceState( window.history.state, '', next );
};

export interface UseDateRangeOptions {
	defaultRange: DateRange;
}

export interface UseDateRangeReturn {
	range: DateRange;
	setPreset: ( preset: DateRangePreset ) => void;
	setCustom: ( start: string, end: string ) => void;
}

/**
 * Hydrate from URL first, fall back to defaultRange. Persist on change.
 */
const useDateRange = ( { defaultRange }: UseDateRangeOptions ): UseDateRangeReturn => {
	const [ range, setRange ] = useState< DateRange >( () => {
		const fromUrl = readUrl();
		// Custom preset requires both start and end from URL; otherwise fall
		// back to default.
		if ( fromUrl.preset === 'custom' ) {
			if ( fromUrl.start && fromUrl.end ) {
				return {
					preset: 'custom',
					start: fromUrl.start,
					end: fromUrl.end,
				};
			}
			return defaultRange;
		}
		if ( fromUrl.preset ) {
			const computed = computeRangeForPreset( fromUrl.preset );
			if ( computed ) {
				return { preset: fromUrl.preset, ...computed };
			}
		}
		return defaultRange;
	} );

	useEffect( () => {
		writeUrl( range );
	}, [ range ] );

	const setPreset = useCallback( ( preset: DateRangePreset ) => {
		if ( preset === 'custom' ) {
			// Custom needs explicit start/end; setCustom handles that path.
			// Hitting "Custom" without supplying dates keeps the current
			// range but flags it as custom so the picker opens.
			setRange( prev => ( {
				preset: 'custom',
				start: prev.start,
				end: prev.end,
			} ) );
			return;
		}
		const computed = computeRangeForPreset( preset );
		if ( computed ) {
			setRange( { preset, ...computed } );
		}
	}, [] );

	const setCustom = useCallback( ( start: string, end: string ) => {
		if ( ! isValidISODate( start ) || ! isValidISODate( end ) ) {
			return;
		}
		// Normalize so the earlier date is always start. The picker has
		// two independent <input type="date"> fields and editing one
		// before the other can transiently produce start > end, which
		// otherwise propagates a nonsensical range into the URL and
		// breaks computePreviousRange.
		const [ s, e ] = start <= end ? [ start, end ] : [ end, start ];
		setRange( { preset: 'custom', start: s, end: e } );
	}, [] );

	return { range, setPreset, setCustom };
};

export default useDateRange;
