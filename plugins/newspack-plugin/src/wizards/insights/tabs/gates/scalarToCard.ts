/**
 * Small helper that maps a `GatesScalarMetric` payload + section
 * copy into the MetricCard props used by every Tab 4 scorecard.
 *
 * Centralises the `placeholder_type` → `MetricFormat` mapping so
 * the section components stay declarative.
 */

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
	return {
		label,
		description,
		value: current.value,
		format: formatFor( current ),
		previousValue: previous?.computable ? previous.value : null,
		pending: current.pending,
	};
};
