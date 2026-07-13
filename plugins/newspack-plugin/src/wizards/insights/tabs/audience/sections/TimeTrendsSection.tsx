/**
 * Audience › Time trends (NPPD-1649, Section 3).
 *
 * When your readers show up — across the period, by day of week, and by hour.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../../packages/components/src';
import type { InsightsWindow } from '../../../api/audience';
import ChartCard from '../../components/ChartCard';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import { toSeries } from '../../components/metrics';
import { formatShortDate } from '../../components/format';
import LineChart from '../../components/LineChart';
import BarChart from '../../components/BarChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const TimeTrendsSection = ( { current }: SectionProps ) => (
	<Section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-trends">
		<SectionHeading
			id="newspack-insights-audience-trends"
			title={ __( 'Time trends', 'newspack-plugin' ) }
			description={ __( 'When your readers show up across the period, by day of week, and by hour of day.', 'newspack-plugin' ) }
		/>
		{ /* New vs Returning takes the full width; the two day/hour bar charts share the row below. */ }
		<VStack spacing={ 4 }>
			<ChartCard
				caption={ __( 'Day to day', 'newspack-plugin' ) }
				title={ __( 'New vs returning over time', 'newspack-plugin' ) }
				payload={ current.new_vs_returning_over_time }
			>
				<LineChart
					series={ [
						{ name: __( 'New', 'newspack-plugin' ), points: toSeries( current.new_vs_returning_over_time, 'date', 'new' ) },
						{ name: __( 'Returning', 'newspack-plugin' ), points: toSeries( current.new_vs_returning_over_time, 'date', 'returning' ) },
					] }
					formatLabel={ formatShortDate }
					height={ 260 }
					seriesColorIndices={ [ 0, 3 ] }
				/>
			</ChartCard>
			<Grid columns={ 2 } gutter={ 16 } noMargin>
				<ChartCard title={ __( 'Readership by day of week', 'newspack-plugin' ) } payload={ current.readership_by_day_of_week }>
					<BarChart bars={ toSeries( current.readership_by_day_of_week, 'day_of_week', 'active_readers' ) } />
				</ChartCard>
				<ChartCard title={ __( 'Readership by hour of day', 'newspack-plugin' ) } payload={ current.readership_by_hour_of_day }>
					<BarChart bars={ toSeries( current.readership_by_hour_of_day, 'hour', 'active_readers' ) } />
				</ChartCard>
			</Grid>
		</VStack>
	</Section>
);

export default TimeTrendsSection;
