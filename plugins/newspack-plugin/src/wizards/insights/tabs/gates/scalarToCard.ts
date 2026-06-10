/**
 * Small helper that maps a `GatesScalarMetric` payload + section
 * copy into the MetricCard props used by every Tab 4 scorecard.
 *
 * Centralises the `placeholder_type` → `MetricFormat` mapping so
 * the section components stay declarative.
 */

import { __ } from '@wordpress/i18n';

import type { GatesScalarMetric } from '../../api/gates';
import type { MetricFormat } from '../components/MetricCard';

const formatFor = ( m: GatesScalarMetric ): MetricFormat => {
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
	current: GatesScalarMetric;
	previous?: GatesScalarMetric | null;
}

export const scalarToMetricCardProps = ( props: ScalarCardProps ) => {
	const { label, description, current, previous } = props;
	// A failed query renders MetricCard's shared error treatment rather than a
	// misleading zero. The raw message stays server-side; the card shows generic
	// copy keyed off the `error` prop.
	if ( current.state === 'error' ) {
		return { label, description, error: current.error_message ?? __( 'Data unavailable', 'newspack-plugin' ) };
	}
	return {
		label,
		description,
		value: current.value,
		format: formatFor( current ),
		// Only compare against a real prior value (not an error/non-computable one).
		previousValue: previous && previous.state !== 'error' && previous.computable ? previous.value : null,
	};
};
