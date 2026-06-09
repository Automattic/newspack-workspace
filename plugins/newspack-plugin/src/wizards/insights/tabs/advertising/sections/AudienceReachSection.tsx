/**
 * Advertising › Audience reach (NPPD-1618, Section 4).
 *
 * Who the ads reached: performance by device (pie, by revenue) and the top
 * countries table. 2-col grid.
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
import { toSeries } from '../../components/metrics';
import PieChart from '../../audience/viz/PieChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const AudienceReachSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-audience-reach">
		<h2 id="newspack-insights-advertising-audience-reach" className="newspack-insights__section-heading">
			{ __( 'Audience reach', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'Where and on what your ads were seen.', 'newspack-plugin' ) }</p>
		<div className="newspack-insights__table-grid newspack-insights__table-grid--cols-2">
			<ChartCard
				title={ __( 'Performance by Device', 'newspack-plugin' ) }
				caption={ __( 'Revenue by device category', 'newspack-plugin' ) }
				payload={ current.performance_by_device }
			>
				<PieChart segments={ toSeries( current.performance_by_device, 'device', 'revenue' ) } />
			</ChartCard>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Countries', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_countries }
					emptyMessage={ __( 'No geographic data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'country', label: __( 'Country', 'newspack-plugin' ) },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
						{ key: 'ecpm', label: __( 'eCPM', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</div>
		</div>
	</section>
);

export default AudienceReachSection;
