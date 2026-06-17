/**
 * CohortRetentionSection (NPPD-1609, Section 5).
 *
 * Two stacked multi-series cohort LineCharts (registration → conversion,
 * subscriber retention), each with a hardcoded reference-line target.
 * Snapshot — refreshed weekly, independent of the date picker.
 *
 * Phase 2: both metrics (5.1, 5.2) are `coming_soon` (Phase B). Each
 * chart's rendering is gated on the metric's `state` envelope.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionCohortData } from '../../api/conversion';
import SectionHeading from '../components/SectionHeading';
import LineChart, { type LineSeries } from '../components/LineChart';
import SectionState from './SectionState';

export interface CohortRetentionSectionProps {
	current: {
		registration_to_conversion_cohort: ConversionCohortData;
		subscriber_retention_cohort: ConversionCohortData;
	};
}

/** Map cohort series (period → value) to LineChart series. */
const toCohortSeries = ( data: ConversionCohortData ): LineSeries[] =>
	data.cohorts.map( cohort => ( {
		name: cohort.label,
		points: cohort.points.map( p => ( { label: String( p.period ), value: p.value } ) ),
	} ) );

interface CohortChartProps {
	title: string;
	data: ConversionCohortData;
}

const CohortChart = ( { title, data }: CohortChartProps ) => (
	<div className="newspack-insights__conversion-cohort-cell">
		<h3 className="newspack-insights__conversion-subheading">{ title }</h3>
		<SectionState state={ data.state } emptyMessage={ __( 'Cohort data will appear after the first weekly refresh.', 'newspack-plugin' ) }>
			<LineChart
				series={ toCohortSeries( data ) }
				referenceLine={ data.reference_line }
				yMax={ 1 }
				emptyMessage={ __( 'Cohort data will appear after the first weekly refresh.', 'newspack-plugin' ) }
			/>
		</SectionState>
	</div>
);

const CohortRetentionSection = ( { current }: CohortRetentionSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--cohort-retention"
		aria-labelledby="newspack-insights-conversion-cohort-heading"
	>
		<SectionHeading
			id="newspack-insights-conversion-cohort-heading"
			title={ __( 'Cohort retention', 'newspack-plugin' ) }
			description={ __(
				'Retention curves by monthly cohort. The vertical axis is the share of each cohort still on a given lifecycle stage at each point in time. Updated weekly.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__conversion-cohort-stack">
			<CohortChart title={ __( 'Registration → conversion', 'newspack-plugin' ) } data={ current.registration_to_conversion_cohort } />
			<CohortChart title={ __( 'Subscriber retention', 'newspack-plugin' ) } data={ current.subscriber_retention_cohort } />
		</div>
	</section>
);

export default CohortRetentionSection;
