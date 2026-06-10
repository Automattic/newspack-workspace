/**
 * Shared metric-payload types and mappers for the GA4-backed Insights tabs
 * (Audience, Engagement — NPPD-1649).
 *
 * Every metric in an Audience_Metric / Engagement_Metric response is one of
 * these shapes. `payloadToCard` collapses a scorecard payload + section copy
 * into MetricCard props, handling the graceful-failure states (overlay,
 * error, hidden) centrally so sections stay declarative.
 */

/**
 * Internal dependencies
 */
import type { MetricCardProps, MetricFormat, MetricCardOverlay } from './MetricCard';

/** Placeholder link for the custom-dimension setup docs. */
export const SETUP_DOCS_URL = 'https://help.newspack.com/';

export type MetricPayloadType = 'count' | 'currency' | 'decimal' | 'rate' | 'duration' | 'breakdown' | 'table' | 'timeseries';

export type MetricRow = Record< string, string | number | null >;

/** Values that should never trigger uniform-column collapse (data-quality gaps stay visible). */
const NON_COLLAPSIBLE_VALUES = [ '', '(not set)' ];

/**
 * If every row shares the same meaningful value for `key`, return it (as a
 * string); otherwise null. Unset / empty / "(not set)" never collapse. Used to
 * hide a uniform column (e.g. country) and to label the scope pill above it.
 */
export const uniformValue = ( rows: MetricRow[], key: string ): string | null => {
	if ( ! rows || rows.length === 0 ) {
		return null;
	}
	const first = rows[ 0 ][ key ];
	if ( first === null || first === undefined || NON_COLLAPSIBLE_VALUES.includes( String( first ) ) ) {
		return null;
	}
	return rows.every( row => row[ key ] === first ) ? String( first ) : null;
};

/**
 * Union-ish payload covering both scorecard and rows metrics. The orchestrator
 * only ever sets the fields relevant to a given metric; consumers branch on the
 * graceful-failure flags first, then read `value` (scorecards) or `rows`.
 */
export interface MetricPayload {
	value?: number | null;
	computable?: boolean;
	type?: MetricPayloadType;
	numerator?: number;
	denominator?: number;
	rows?: MetricRow[];
	overlay?: MetricCardOverlay;
	error?: string;
	hidden_in_v1?: boolean;
	not_configured?: boolean;
	degraded?: boolean;
}

const typeToFormat = ( type?: MetricPayloadType ): MetricFormat => {
	switch ( type ) {
		case 'rate':
			return 'percent';
		case 'currency':
			return 'currency';
		case 'decimal':
			return 'decimal';
		case 'duration':
			return 'duration';
		default:
			return 'number';
	}
};

export interface PayloadToCardArgs {
	label: string;
	description?: string;
	current?: MetricPayload;
	previous?: MetricPayload | null;
	lowerIsBetter?: boolean;
}

/**
 * Map a scorecard payload into MetricCard props. Returns null when the metric
 * is hidden in v1 (caller skips rendering the card entirely).
 */
export const payloadToCard = ( args: PayloadToCardArgs ): MetricCardProps | null => {
	const { label, description, current, previous, lowerIsBetter } = args;

	if ( ! current || current.hidden_in_v1 ) {
		return null;
	}
	if ( current.overlay ) {
		return { label, description, overlay: current.overlay };
	}
	if ( current.error ) {
		return { label, description, error: current.error };
	}
	if ( current.not_configured ) {
		return { label, description, notConfigured: true };
	}

	const previousValue = previous && previous.computable && typeof previous.value === 'number' ? previous.value : null;

	return {
		label,
		description,
		lowerIsBetter,
		value: typeof current.value === 'number' ? current.value : 0,
		format: typeToFormat( current.type ),
		previousValue,
	};
};

/**
 * Convert a rows-shaped payload into chart series `{ label, value }[]`,
 * summing duplicate labels (e.g. collapsing a date×reader_type breakdown into
 * one point per date). Returns [] for non-rows / failure payloads.
 */
export const toSeries = ( payload: MetricPayload | undefined, labelKey: string, valueKey: string ): Array< { label: string; value: number } > => {
	if ( ! payload || ! Array.isArray( payload.rows ) || payload.overlay || payload.error ) {
		return [];
	}
	const byLabel = new Map< string, number >();
	for ( const row of payload.rows ) {
		const label = String( row[ labelKey ] ?? '' );
		const value = Number( row[ valueKey ] ?? 0 ) || 0;
		byLabel.set( label, ( byLabel.get( label ) ?? 0 ) + value );
	}
	return Array.from( byLabel, ( [ label, value ] ) => ( { label, value } ) );
};
