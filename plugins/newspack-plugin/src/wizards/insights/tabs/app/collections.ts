/**
 * Collection helpers (App tab, Tab 10 — NPPD-1882).
 *
 * Shared logic for the multi-property "collection" (publication) breakdowns:
 * title-casing the raw lowercase values, listing the distinct collections, and
 * pivoting a `collection × dimension` matrix down to one collection's top-N rows.
 */

/**
 * Internal dependencies
 */
import type { MetricPayload, MetricRow } from '../components/metrics';

/** Title-case a raw collection value ("example city" → "Example City"). */
export const titleCase = ( value: string ): string => value.replace( /\b\w/g, char => char.toUpperCase() );

/** Distinct collection values present across the given breakdown payloads. */
export const collectionValues = ( ...payloads: Array< MetricPayload | undefined > ): string[] => {
	const seen = new Set< string >();
	payloads.forEach( payload => {
		( payload?.rows ?? [] ).forEach( row => {
			const value = String( row.collection ?? '' );
			if ( value ) {
				seen.add( value );
			}
		} );
	} );
	return [ ...seen ];
};

/**
 * Pivot a `collection × dimension` matrix to one collection's top-N rows: filter
 * to the collection, merge case-duplicate labels (sum; keep the dominant casing),
 * sort by metric desc with a label tie-breaker, and cap. Mirrors the server-side
 * merge so a per-collection view matches the aggregate "All publications" view.
 */
export const topByCollection = (
	payload: MetricPayload | undefined,
	collection: string,
	dimKey: string,
	metricKey: string,
	limit = 8
): MetricPayload => {
	const merged = new Map< string, { row: MetricRow; top: number } >();
	( payload?.rows ?? [] )
		.filter( row => String( row.collection ?? '' ) === collection )
		.forEach( row => {
			const label = String( row[ dimKey ] ?? '' );
			const value = Number( row[ metricKey ] ?? 0 );
			const key = label.toLowerCase();
			const existing = merged.get( key );
			if ( ! existing ) {
				merged.set( key, { row: { [ dimKey ]: label, [ metricKey ]: value }, top: value } );
				return;
			}
			existing.row[ metricKey ] = Number( existing.row[ metricKey ] ?? 0 ) + value;
			if ( value > existing.top ) {
				existing.row[ dimKey ] = label;
				existing.top = value;
			}
		} );

	const rows = [ ...merged.values() ]
		.map( entry => entry.row )
		.sort( ( a, b ) => {
			const byMetric = Number( b[ metricKey ] ?? 0 ) - Number( a[ metricKey ] ?? 0 );
			return byMetric !== 0 ? byMetric : String( a[ dimKey ] ?? '' ).localeCompare( String( b[ dimKey ] ?? '' ) );
		} )
		.slice( 0, limit );

	return { rows, computable: true, type: 'breakdown' };
};
