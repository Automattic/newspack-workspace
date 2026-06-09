/**
 * Advertising › Revenue breakdown (NPPD-1618, Section 3).
 *
 * Where the revenue comes from: the direct-vs-programmatic split (pie), top ad
 * units, and top advertisers. 2-col grid with wrap — the pie and ad-units table
 * share row 1; the advertisers table wraps to row 2 at ~50% width (matching the
 * Engagement content-engagement layout).
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

const RevenueBreakdownSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-revenue-breakdown">
		<h2 id="newspack-insights-advertising-revenue-breakdown" className="newspack-insights__section-heading">
			{ __( 'Revenue breakdown', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'What drives your ad revenue.', 'newspack-plugin' ) }</p>
		<div className="newspack-insights__table-grid newspack-insights__table-grid--cols-2">
			<ChartCard
				title={ __( 'Direct vs Programmatic', 'newspack-plugin' ) }
				caption={ __( 'Revenue by demand source', 'newspack-plugin' ) }
				payload={ current.direct_vs_programmatic }
			>
				<PieChart segments={ toSeries( current.direct_vs_programmatic, 'label', 'revenue' ) } />
			</ChartCard>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Ad Units', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_ad_units }
					emptyMessage={ __( 'No ad unit data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'ad_unit', label: __( 'Ad Unit', 'newspack-plugin' ) },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
						{ key: 'ecpm', label: __( 'eCPM', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</div>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Advertisers', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_advertisers }
					emptyMessage={ __( 'No advertiser data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'advertiser', label: __( 'Advertiser', 'newspack-plugin' ) },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</div>
		</div>
	</section>
);

export default RevenueBreakdownSection;
