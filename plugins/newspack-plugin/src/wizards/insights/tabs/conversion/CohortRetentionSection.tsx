/**
 * CohortRetentionSection (NPPD-1609, Section 5).
 *
 * Two stacked cohort heatmaps (registration → conversion, subscriber retention):
 * rows are monthly cohorts, columns are months since start, cells shaded by value.
 * This replaced the many-series LineCharts, which became unreadable "spaghetti"
 * once a site had more than a handful of cohorts. Snapshot — refreshed weekly,
 * independent of the date picker.
 *
 * Each chart's rendering is gated on the metric's `state` envelope.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionCohortData } from '../../api/conversion';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import CohortHeatmap from '../components/CohortHeatmap';
import { formatPercent } from '../components/format';
import SectionState from './SectionState';

export interface CohortRetentionSectionProps {
	current: {
		registration_to_conversion_cohort: ConversionCohortData;
		subscriber_retention_cohort: ConversionCohortData;
	};
}

interface CohortChartProps {
	title: string;
	data: ConversionCohortData;
	/** One-line explanation of what a cell means (the two charts read oppositely). */
	caption: string;
	referenceLabel?: string;
}

const CohortChart = ( { title, data, caption, referenceLabel }: CohortChartProps ) => (
	<div className="newspack-insights__conversion-cohort-cell">
		<h3 className="newspack-insights__conversion-subheading">{ title }</h3>
		<p className="newspack-insights__conversion-subcaption">{ caption }</p>
		<SectionState
			state={ data.state }
			comingSoonMessage={ __(
				'Cohort data is being prepared. Check back in a few minutes, then click Refresh now to load it.',
				'newspack-plugin'
			) }
			emptyMessage={ __( 'No cohort data available yet.', 'newspack-plugin' ) }
		>
			<CohortHeatmap
				cohorts={ data.cohorts }
				formatValue={ formatPercent }
				columnsLabel={ __( 'Months since cohort start', 'newspack-plugin' ) }
				referenceLabel={ referenceLabel }
				emptyMessage={ __( 'No cohort data available yet.', 'newspack-plugin' ) }
			/>
		</SectionState>
	</div>
);

const CohortRetentionSection = ( { current }: CohortRetentionSectionProps ) => (
	<Section
		className="newspack-insights__section newspack-insights__section--cohort-retention"
		aria-labelledby="newspack-insights-conversion-cohort-heading"
	>
		<SectionHeading
			id="newspack-insights-conversion-cohort-heading"
			title={ __( 'Cohort retention', 'newspack-plugin' ) }
			description={ __(
				'Each row is a monthly cohort; each column is months since that cohort started. Read down a column to compare cohorts at the same age, and across a row to watch one cohort over time. Updated weekly.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__conversion-cohort-stack">
			<CohortChart
				/*
				 * TODO: default a self-relative reference callout here — the median
				 * cumulative conversion of mature (>=12-month) cohorts at the 6-month
				 * mark — and expose it as a configurable Newspack publisher setting.
				 * For now 5.1 shows no target; the hardcoded 15% was removed because no
				 * fixed-% default fits the network (publisher models diverge widely).
				 */
				title={ __( 'Registration → conversion', 'newspack-plugin' ) }
				caption={ __(
					'Of the readers who registered each month, the share who had become a paying subscriber or donor by that many months later. Starts at 0% and climbs.',
					'newspack-plugin'
				) }
				data={ current.registration_to_conversion_cohort }
			/>
			<CohortChart
				title={ __( 'Subscriber retention', 'newspack-plugin' ) }
				caption={ __(
					'Of the subscribers who started each month, the share still subscribed that many months later. Starts at 100% and declines.',
					'newspack-plugin'
				) }
				data={ current.subscriber_retention_cohort }
				referenceLabel={ current.subscriber_retention_cohort.reference_line?.label }
			/>
		</div>
	</Section>
);

export default CohortRetentionSection;
