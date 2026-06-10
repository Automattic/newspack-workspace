/**
 * Advertising › Top performers (NPPD-1618, Section 3).
 *
 * Top Ad Units (left) and Top Advertisers (right) tables, side by side at equal
 * width. Top Advertisers collapses to 5 rows with a "See more" toggle.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/advertising';
import MetricTable from '../../components/MetricTable';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const TopPerformersSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-top-performers">
		<h2 id="newspack-insights-advertising-top-performers" className="newspack-insights__section-heading">
			{ __( 'Top performers', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'Where your ad dollars are coming from.', 'newspack-plugin' ) }</p>
		<div className="newspack-insights__table-grid newspack-insights__table-grid--cols-2">
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Ad Units', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_ad_units }
					emptyMessage={ __( 'No ad unit data in this timeframe.', 'newspack-plugin' ) }
					expandable
					defaultRowLimit={ 5 }
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
					expandable
					defaultRowLimit={ 5 }
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

export default TopPerformersSection;
