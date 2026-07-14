/**
 * useDateRange
 *
 * Owns the active date range state for the Insights wizard. Hydrates
 * initial state from URL query (so refresh / direct links preserve range)
 * with fallback to the boot config default. URL persistence happens at
 * commit time via `writeDateRangeUrl` (see `./controlsUrl`), not here.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useState } from '@wordpress/element';

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

/**
 * Human "when" phrase for a range, for embedding in metric descriptions
 * (e.g. `Registrations created {phrase}` → "…in the last 30 days"). Each preset
 * reads naturally in that slot with its preposition baked in where one is
 * needed; custom falls back to the generic "in this timeframe".
 */
export const rangeWhenPhrase = ( range: DateRange ): string => {
	switch ( range.preset ) {
		case 'last-7':
			return __( 'in the last 7 days', 'newspack-plugin' );
		case 'last-30':
			return __( 'in the last 30 days', 'newspack-plugin' );
		case 'last-90':
			return __( 'in the last 90 days', 'newspack-plugin' );
		case 'this-month':
			return __( 'this month', 'newspack-plugin' );
		case 'last-month':
			return __( 'last month', 'newspack-plugin' );
		default:
			return __( 'in this timeframe', 'newspack-plugin' );
	}
};

const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Returns "today" anchored to the site timezone so preset windows
 * computed here match the server-side Preset_Windows (PHP current_datetime()
 * in site TZ). Parity between these two is what makes pre-warmed durable
 * cache keys hit — a day mismatch causes a silent cache miss.
 * Falls back to browser-local Date if no site timezone is configured.
 */
const getSiteToday = (): Date => {
	const tz = ( typeof window !== 'undefined' && window.newspackInsights?.timezone ) || undefined;
	if ( ! tz ) {
		return new Date(); // graceful fallback: browser-local (pre-fix behavior)
	}
	// en-CA formats as YYYY-MM-DD; extract the site-TZ civil date, then build a
	// local-midnight Date carrying those civil fields so the existing day-arithmetic
	// (setDate(-6), getMonth(), etc.) operates on the correct calendar date.
	const parts = new Intl.DateTimeFormat( 'en-CA', {
		timeZone: tz,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	} ).format( new Date() );
	const [ y, m, d ] = parts.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
};

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
export const computeRangeForPreset = ( preset: DateRangePreset, today: Date = getSiteToday() ): { start: string; end: string } | null => {
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

export interface UseDateRangeOptions {
	defaultRange: DateRange;
}

export interface UseDateRangeReturn {
	range: DateRange;
	setPreset: ( preset: DateRangePreset ) => void;
	setCustom: ( start: string, end: string ) => void;
	setRange: ( range: DateRange ) => void;
}

/**
 * Hydrate from URL first, fall back to defaultRange.
 */
const useDateRange = ( { defaultRange }: UseDateRangeOptions ): UseDateRangeReturn => {
	const [ range, setRangeState ] = useState< DateRange >( () => {
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

	const setPreset = useCallback( ( preset: DateRangePreset ) => {
		if ( preset === 'custom' ) {
			// Custom needs explicit start/end; setCustom handles that path.
			// Hitting "Custom" without supplying dates keeps the current
			// range but flags it as custom so the picker opens.
			setRangeState( prev => ( {
				preset: 'custom',
				start: prev.start,
				end: prev.end,
			} ) );
			return;
		}
		const computed = computeRangeForPreset( preset );
		if ( computed ) {
			setRangeState( { preset, ...computed } );
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
		setRangeState( { preset: 'custom', start: s, end: e } );
	}, [] );

	const setRange = useCallback( ( next: DateRange ): void => {
		setRangeState( next );
	}, [] );

	return { range, setPreset, setCustom, setRange };
};

export default useDateRange;
