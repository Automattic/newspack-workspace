/**
 * Maps a `ConversionScalarMetric` payload + section copy into the
 * MetricCard props used by every Tab 3 scorecard (Sections 7 and
 * 8.1–8.3). Centralises the `placeholder_type` → `MetricFormat` mapping
 * so the section components stay declarative. Mirrors the prompts tab's
 * `scalarToCard.ts`.
 */

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
