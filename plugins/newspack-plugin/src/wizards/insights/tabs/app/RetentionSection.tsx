/**
 * RetentionSection (App tab, Tab 10 — NPPD-1882).
 *
 * Weekly retention curve: the share of new app users still active N weeks after
 * their first open, averaged across recent complete weekly cohorts. The canonical
 * app-health signal — do installs come back?
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AppMetrics } from '../../api/app';
import type { MetricPayload } from '../components/metrics';
import SectionHeading from '../components/SectionHeading';
import ChartCard from '../components/ChartCard';
import LineChart from '../components/LineChart';
import { formatPercent } from '../components/format';

/** Map the retention timeseries payload to line-chart points ({ week, retention } → { label, value }). */
const toPoints = ( payload: MetricPayload | undefined ) =>
	( payload?.rows ?? [] ).map( row => ( {
		label: String( row.week ?? '' ),
		value: Number( row.retention ?? 0 ),
	} ) );

export interface RetentionSectionProps {
	metrics: AppMetrics;
}

const RetentionSection = ( { metrics }: RetentionSectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-app-retention-heading">
		<SectionHeading
			id="newspack-insights-app-retention-heading"
			title={ __( 'Retention', 'newspack-plugin' ) }
			description={ __( 'How many new app users come back in the weeks after their first open.', 'newspack-plugin' ) }
		/>
		<ChartCard
			title={ __( 'Weekly retention', 'newspack-plugin' ) }
			caption={ __( 'Share of new users still active N weeks after first open', 'newspack-plugin' ) }
			payload={ metrics.retention }
		>
			<LineChart
				points={ toPoints( metrics.retention ) }
				yMax={ 1 }
				formatValue={ formatPercent }
				formatLabel={ ( label: string ) =>
					sprintf(
						/* translators: %s: week number since first app open. */
						__( 'Week %s', 'newspack-plugin' ),
						label
					)
				}
				xAxisLabel={ __( 'Weeks since first open', 'newspack-plugin' ) }
				yAxisLabel={ __( 'Still active', 'newspack-plugin' ) }
				emptyMessage={ __( 'Retention will appear once there are enough weekly cohorts of data.', 'newspack-plugin' ) }
			/>
		</ChartCard>
	</section>
);

export default RetentionSection;
