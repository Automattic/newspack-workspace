/**
 * Audience › Time trends (NPPD-1649, Section 3).
 *
 * When your readers show up — across the period, by day of week, and by hour.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/audience';
import ChartCard from '../../components/ChartCard';
import { toSeries } from '../../components/metrics';
import { formatShortDate } from '../../components/format';
import LineChart from '../viz/LineChart';
import BarChart from '../viz/BarChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const TimeTrendsSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-trends">
		<h2 id="newspack-insights-audience-trends" className="newspack-insights__section-heading">
			{ __( 'Time trends', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __( 'When your readers show up across the period, by day of week, and by hour of day.', 'newspack-plugin' ) }
		</p>
		{ /* New vs Returning takes the full width; the two day/hour bar charts share the row below. */ }
		<ChartCard
			subhead={ __( 'Day to day', 'newspack-plugin' ) }
			title={ __( 'New vs Returning Over Time', 'newspack-plugin' ) }
			payload={ current.new_vs_returning_over_time }
		>
			<LineChart
				series={ [
					{ name: __( 'New', 'newspack-plugin' ), points: toSeries( current.new_vs_returning_over_time, 'date', 'new' ) },
					{ name: __( 'Returning', 'newspack-plugin' ), points: toSeries( current.new_vs_returning_over_time, 'date', 'returning' ) },
				] }
				formatLabel={ formatShortDate }
			/>
		</ChartCard>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">
			<ChartCard
				subhead={ __( 'Day of week', 'newspack-plugin' ) }
				title={ __( 'Readership by Day of Week', 'newspack-plugin' ) }
				payload={ current.readership_by_day_of_week }
			>
				<BarChart bars={ toSeries( current.readership_by_day_of_week, 'day_of_week', 'active_readers' ) } />
			</ChartCard>
			<ChartCard
				subhead={ __( 'Hour of day', 'newspack-plugin' ) }
				title={ __( 'Readership by Hour of Day', 'newspack-plugin' ) }
				payload={ current.readership_by_hour_of_day }
			>
				<BarChart bars={ toSeries( current.readership_by_hour_of_day, 'hour', 'active_readers' ) } />
			</ChartCard>
		</div>
	</section>
);

export default TimeTrendsSection;
