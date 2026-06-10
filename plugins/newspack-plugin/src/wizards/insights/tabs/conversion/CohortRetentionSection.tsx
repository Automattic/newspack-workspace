/**
 * CohortRetentionSection (NPPD-1609, Section 5).
 *
 * Two stacked multi-series cohort LineCharts (registration → conversion,
 * subscriber retention), each with a hardcoded reference line. Snapshot —
 * not affected by the date picker; refreshed weekly. Scaffold renders
 * header + caption + an empty placeholder; the LineChart viz (with
 * reference line) is wired in the following commit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';

export interface CohortRetentionSectionProps {
	current: ConversionWindow;
}

const CohortRetentionSection = ( { current }: CohortRetentionSectionProps ) => {
	const pending = current.registration_to_conversion_cohort.pending;
	return (
		<section
			className="newspack-insights__section newspack-insights__section--cohort-retention"
			aria-labelledby="newspack-insights-conversion-cohort-heading"
		>
			<h2 id="newspack-insights-conversion-cohort-heading" className="newspack-insights__section-heading">
				{ __( 'Cohort retention', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __(
					'Retention curves by monthly cohort. The vertical axis is the share of each cohort still on a given lifecycle stage at each point in time. Updated weekly (see callout above).',
					'newspack-plugin'
				) }
			</p>
			<div className="newspack-insights__viz-placeholder" data-pending={ pending } />
		</section>
	);
};

export default CohortRetentionSection;
