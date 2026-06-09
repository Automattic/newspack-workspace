/**
 * Audience › Traffic sources (NPPD-1649, Section 4).
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
import MetricTable from '../../components/MetricTable';
import { toSeries } from '../../components/metrics';
import PieChart from '../viz/PieChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const TrafficSourcesSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-traffic">
		<h2 id="newspack-insights-audience-traffic" className="newspack-insights__section-heading">
			{ __( 'Traffic sources', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'Where your readers come from.', 'newspack-plugin' ) }</p>
		{ /* Channel breakdown (left ~40%) reads as a unit with the campaigns
		     driving each channel (right ~60%) — NPPD-1649 fix #3. */ }
		<div className="newspack-insights__traffic-grid">
			<ChartCard title={ __( 'Traffic Sources Breakdown', 'newspack-plugin' ) } payload={ current.traffic_sources_breakdown }>
				<PieChart segments={ toSeries( current.traffic_sources_breakdown, 'channel', 'readers' ) } />
			</ChartCard>
			{ /* Wrap the table in a matching card so both halves read as parallel
			     bordered cards with the title inside (table-in-card pattern). */ }
			<ChartCard title={ __( 'Top Campaigns', 'newspack-plugin' ) } payload={ current.top_campaigns }>
				<MetricTable
					payload={ current.top_campaigns }
					emptyMessage={ __( 'No campaign traffic in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'source', label: __( 'Source', 'newspack-plugin' ) },
						{ key: 'medium', label: __( 'Medium', 'newspack-plugin' ) },
						{ key: 'campaign', label: __( 'Campaign', 'newspack-plugin' ) },
						{ key: 'readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'sessions', label: __( 'Sessions', 'newspack-plugin' ), format: 'number', align: 'right' },
					] }
				/>
			</ChartCard>
		</div>
	</section>
);

export default TrafficSourcesSection;
