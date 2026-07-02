/**
 * Maps a `ConversionScalarMetric` payload + section copy into the
 * MetricCard props used by every Tab 3 scorecard (Sections 7 and
 * 8.1–8.3). Centralises the `placeholder_type` → `MetricFormat` mapping
 * so the section components stay declarative. Mirrors the prompts tab's
 * `scalarToCard.ts`.
 */

import { __ } from '@wordpress/i18n';

import type { ConversionScalarMetric } from '../../api/conversion';
import type { MetricFormat } from '../components/MetricCard';

const formatFor = ( m: ConversionScalarMetric ): MetricFormat => {
	switch ( m.placeholder_type ) {
		case 'rate':
			return 'percent';
		case 'currency':
			return 'currency';
		case 'decimal':
			return 'decimal';
		case 'count':
		default:
			return 'number';
	}
};

export interface ScalarCardProps {
	label: string;
	description: string;
	current: ConversionScalarMetric;
	previous?: ConversionScalarMetric | null;
	/**
	 * "No population to calculate from" copy (NEWS-2593). Routed to MetricCard's
	 * em-dash treatment when a rate metric is populated but incalculable
	 * (`computable === false`, i.e. a zero denominator / 0-of-0). Falls back to a
	 * generic message when a section omits it. Ignored for non-rate scalars.
	 */
	notComputableMessage?: string;
}

export const scalarToMetricCardProps = ( props: ScalarCardProps ) => {
	const { label, description, current, previous, notComputableMessage } = props;
	// A failed query renders MetricCard's shared error treatment rather than a
	// misleading zero. The raw message stays server-side; the card shows generic
	// copy keyed off the `error` prop.
	if ( current.state === 'error' ) {
		return { label, description, error: current.error_message ?? __( 'Data temporarily unavailable.', 'newspack-plugin' ) };
	}
	if ( current.state === 'populated' && current.data_missing ) {
		return { label, description, dataMissing: true };
	}
	// Incalculable percentage (NEWS-2593): a populated rate whose population is
	// empty (0/0 → `computable: false`, e.g. an Influenced Donation Rate on a site
	// with no donations) carries no signal. Render MetricCard's em-dash hero +
	// explanatory line instead of a misleading `0%`. Scoped to rate format so
	// count/currency/decimal good-zeros keep their real `0`. A generic fallback
	// guarantees the em-dash even if a call site omits its per-metric copy.
	if ( current.state === 'populated' && current.computable === false && current.placeholder_type === 'rate' ) {
		return {
			label,
			description,
			notComputableMessage: notComputableMessage ?? __( 'Not enough data to calculate.', 'newspack-plugin' ),
		};
	}
	return {
		label,
		description,
		value: current.value,
		format: formatFor( current ),
		// Suppress the period-over-period delta unless BOTH windows are real
		// computed values. A non-computable current (e.g. an empty window's zero)
		// must not show a delta against a real prior value (that would read as a
		// misleading "↓ 100%").
		previousValue: current.computable && previous && previous.state !== 'error' && previous.computable ? previous.value : null,
		pending: current.state === 'coming_soon',
	};
};
