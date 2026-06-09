/**
 * Advertising › Revenue breakdown (NPPD-1618, Section 3).
 *
 * Two rows:
 *   Row 1 — Top Ad Units (left) and Top Advertisers (right) tables, equal width.
 *   Row 2 — Direct vs Programmatic (left) and Performance by Device (right)
 *           pies, each width-constrained to ~50%, three-slot vertical layout.
 *
 * The device pie lives here (rather than a separate audience section) — grouping
 * both breakdown pies under "Revenue breakdown" is the right IA call for v1.
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
		{ /* Row 1: tables side by side. */ }
		<div className="newspack-insights__table-grid newspack-insights__table-grid--cols-2">
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
		{ /* Row 2: pies side by side, width-constrained to ~50% each. */ }
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">
			<ChartCard
				title={ __( 'Direct vs Programmatic', 'newspack-plugin' ) }
				caption={ __( 'Revenue by demand source', 'newspack-plugin' ) }
				payload={ current.direct_vs_programmatic }
			>
				<PieChart segments={ toSeries( current.direct_vs_programmatic, 'label', 'revenue' ) } />
			</ChartCard>
			<ChartCard
				title={ __( 'Performance by Device', 'newspack-plugin' ) }
				caption={ __( 'Revenue by device category', 'newspack-plugin' ) }
				payload={ current.performance_by_device }
			>
				<PieChart segments={ toSeries( current.performance_by_device, 'device', 'revenue' ) } />
			</ChartCard>
		</div>
	</section>
);

export default RevenueBreakdownSection;
