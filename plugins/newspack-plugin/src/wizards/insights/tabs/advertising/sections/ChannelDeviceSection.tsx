/**
 * Advertising › Ad types & devices (Tab 8 enhancements).
 *
 * Two side-by-side cards: the impressions-by-type donut (raw GAM
 * LINE_ITEM_TYPE values grouped server-side into Direct-sold / Programmatic /
 * House / Other; the metric key stays `by_channel` — display strings say
 * "type") and the compact performance-by-device table (impressions /
 * revenue / eCPM per device category). The donut is impressions-weighted —
 * house line items are unpaid, so a revenue weighting would hide House
 * entirely; impressions show how inventory is allocated, including the
 * house/unsold share. Both degrade per-card via ChartCard / MetricTable.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/advertising';
import ChartCard from '../../components/ChartCard';
import MetricTable from '../../components/MetricTable';
import SectionHeading from '../../components/SectionHeading';
import { toSeries } from '../../components/metrics';
import PieChart from '../../components/PieChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const ChannelDeviceSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-channel-device">
		<SectionHeading
			id="newspack-insights-advertising-channel-device"
			title={ __( 'Ad types & devices', 'newspack-plugin' ) }
			description={ __( 'How inventory and revenue split across ad types and devices.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">
			<ChartCard
				title={ __( 'Impressions by type', 'newspack-plugin' ) }
				caption={ __( 'How your ad inventory is allocated — including unpaid house ads', 'newspack-plugin' ) }
				payload={ current.by_channel }
			>
				<PieChart
					segments={ toSeries( current.by_channel, 'channel', 'impressions' ) }
					emptyMessage={ __( 'No ad type activity in this timeframe.', 'newspack-plugin' ) }
				/>
			</ChartCard>
			<ChartCard
				title={ __( 'Performance by device', 'newspack-plugin' ) }
				caption={ __( 'Where your impressions and revenue land', 'newspack-plugin' ) }
				payload={ current.by_device }
			>
				<MetricTable
					payload={ current.by_device }
					emptyMessage={ __( 'No device data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'device', label: __( 'Device', 'newspack-plugin' ) },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
						{ key: 'ecpm', label: __( 'eCPM', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</ChartCard>
		</div>
	</section>
);

export default ChannelDeviceSection;
