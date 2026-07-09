/**
 * controlsUrl
 *
 * Commit-time URL persistence for the Insights global controls. The date-range
 * and comparison hooks still hydrate from these query params on mount, but no
 * longer write on every change — usePendingControls calls these writers only
 * when the user applies a change, so the URL always reflects the viewed data.
 */

/**
 * Internal dependencies
 */
import type { DateRange } from './useDateRange';

export const writeDateRangeUrl = ( range: DateRange ): void => {
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

export const writeComparisonUrl = ( enabled: boolean ): void => {
	if ( typeof window === 'undefined' ) {
		return;
	}
	const params = new URLSearchParams( window.location.search );
	// Persist both states explicitly ('0' round-trips cleanly with readUrl in
	// useComparisonMode) so an explicit "disabled" choice survives a refresh.
	params.set( 'compare', enabled ? '1' : '0' );
	const next = `${ window.location.pathname }?${ params.toString() }${ window.location.hash }`;
	window.history.replaceState( window.history.state, '', next );
};
