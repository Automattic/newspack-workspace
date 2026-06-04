/**
 * useComparisonMode
 *
 * Owns the "compare to previous period" toggle. When enabled, computes
 * the previous-period range as the same length immediately preceding the
 * current range. Hydrates from URL query (?compare=1) and persists on
 * change.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from './useDateRange';

const pad2 = ( n: number ) => String( n ).padStart( 2, '0' );

const toISO = ( d: Date ): string => `${ d.getFullYear() }-${ pad2( d.getMonth() + 1 ) }-${ pad2( d.getDate() ) }`;

const fromISO = ( s: string ): Date | null => {
	const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( s );
	if ( ! m ) {
		return null;
	}
	return new Date( Number( m[ 1 ] ), Number( m[ 2 ] ) - 1, Number( m[ 3 ] ) );
};

const daysBetween = ( a: Date, b: Date ): number => {
	const ms = b.getTime() - a.getTime();
	return Math.round( ms / ( 1000 * 60 * 60 * 24 ) );
};

/**
 * Same-length-back. Previous period is the [start - N days, start - 1 day]
 * window where N is the length of the current range.
 *
 * e.g. current = 2026-05-01 to 2026-05-30 (30 days)
 *      previous = 2026-04-01 to 2026-04-30 (30 days, ending the day before
 *      current starts so the two windows don't overlap)
 */
export const computePreviousRange = ( current: DateRange ): DateRange | null => {
	const start = fromISO( current.start );
	const end = fromISO( current.end );
	if ( ! start || ! end ) {
		return null;
	}
	const lengthDays = daysBetween( start, end );
	if ( lengthDays < 0 ) {
		return null;
	}
	const prevEnd = new Date( start );
	prevEnd.setDate( prevEnd.getDate() - 1 );
	const prevStart = new Date( prevEnd );
	prevStart.setDate( prevStart.getDate() - lengthDays );
	return {
		preset: 'custom',
		start: toISO( prevStart ),
		end: toISO( prevEnd ),
	};
};

const readUrl = (): boolean | undefined => {
	if ( typeof window === 'undefined' ) {
		return undefined;
	}
	const v = new URLSearchParams( window.location.search ).get( 'compare' );
	if ( v === '1' ) {
		return true;
	}
	if ( v === '0' ) {
		return false;
	}
	return undefined;
};

const writeUrl = ( enabled: boolean ) => {
	if ( typeof window === 'undefined' ) {
		return;
	}
	const params = new URLSearchParams( window.location.search );
	// Persist both states explicitly. Previously the disabled state
	// deleted the param, which meant an explicit user choice of
	// "disabled" would silently revert to the boot config default on
	// refresh whenever that default is true. `readUrl` already accepts
	// '0' so this round-trips cleanly.
	params.set( 'compare', enabled ? '1' : '0' );
	const next = `${ window.location.pathname }?${ params.toString() }${ window.location.hash }`;
	window.history.replaceState( window.history.state, '', next );
};

export interface UseComparisonModeOptions {
	defaultEnabled: boolean;
	currentRange: DateRange;
}

export interface UseComparisonModeReturn {
	enabled: boolean;
	setEnabled: ( v: boolean ) => void;
	previousRange: DateRange | null;
}

const useComparisonMode = ( { defaultEnabled, currentRange }: UseComparisonModeOptions ): UseComparisonModeReturn => {
	const [ enabled, setEnabledState ] = useState< boolean >( () => {
		const fromUrl = readUrl();
		return fromUrl !== undefined ? fromUrl : defaultEnabled;
	} );

	useEffect( () => {
		writeUrl( enabled );
	}, [ enabled ] );

	const setEnabled = useCallback( ( v: boolean ) => {
		setEnabledState( v );
	}, [] );

	const previousRange = useMemo( () => ( enabled ? computePreviousRange( currentRange ) : null ), [ enabled, currentRange ] );

	return { enabled, setEnabled, previousRange };
};

export default useComparisonMode;
