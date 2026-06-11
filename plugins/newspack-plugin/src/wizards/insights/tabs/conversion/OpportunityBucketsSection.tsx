/**
 * OpportunityBucketsSection (NPPD-1609, Section 8).
 *
 * Three snapshot scorecards (stale registered readers, at-risk
 * subscribers, lapsed donors) above a full-width "top pages that don't
 * convert" table. The scorecards are current-state counts — no comparison
 * deltas. Phase 1 renders the table's empty-state row.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionTopPageRow, ConversionWindow } from '../../api/conversion';
import MetricCard from '../components/MetricCard';
import { formatNumber, formatPercent } from '../components/format';
import { scalarToMetricCardProps } from './scalarToCard';
import SortableTable, { type SortableColumn } from './viz/SortableTable';

export interface OpportunityBucketsSectionProps {
	current: ConversionWindow;
}

const TOP_PAGES_ROW_LIMIT = 25;

const TOP_PAGES_COLUMNS: SortableColumn< ConversionTopPageRow >[] = [
	{
		key: 'page_title',
		label: __( 'Page title', 'newspack-plugin' ),
		numeric: false,
		render: row => row.page_title,
		sortValue: row => row.page_title,
	},
	{
		key: 'page_url',
		label: __( 'Page URL', 'newspack-plugin' ),
		numeric: false,
		render: row => (
			<a href={ row.page_url } target="_blank" rel="noreferrer">
				{ row.page_url }
			</a>
		),
		sortValue: row => row.page_url,
	},
	{
		key: 'pageviews',
		label: __( 'Pageviews', 'newspack-plugin' ),
		numeric: true,
		render: row => formatNumber( row.pageviews ),
		sortValue: row => row.pageviews,
	},
	{
		key: 'unique_readers',
		label: __( 'Unique readers', 'newspack-plugin' ),
		numeric: true,
		render: row => formatNumber( row.unique_readers ),
		sortValue: row => row.unique_readers,
	},
	{
		key: 'conversion_rate',
		label: __( 'Conversion rate', 'newspack-plugin' ),
		numeric: true,
		render: row => formatPercent( row.conversion_rate ),
		sortValue: row => row.conversion_rate,
	},
];

const OpportunityBucketsSection = ( { current }: OpportunityBucketsSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--opportunity-buckets"
		aria-labelledby="newspack-insights-conversion-opportunity-heading"
	>
		<h2 id="newspack-insights-conversion-opportunity-heading" className="newspack-insights__section-heading">
			{ __( 'Opportunity buckets', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'Where the funnel has slack. These are diagnostic counts and underperforming pages — readers and content that could move with attention.',
				'newspack-plugin'
			) }
		</p>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Stale Registered Readers', 'newspack-plugin' ),
					description: __( 'Registered but never converted, no activity in 90 days', 'newspack-plugin' ),
					current: current.stale_registered_count,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'At-Risk Subscribers', 'newspack-plugin' ),
					description: __( 'Active subscribers with a failed-payment retry scheduled', 'newspack-plugin' ),
					current: current.at_risk_subscriber_count,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Lapsed Donors', 'newspack-plugin' ),
					description: __( 'Donors with no donation in the last 365 days', 'newspack-plugin' ),
					current: current.lapsed_donor_count,
				} ) }
			/>
		</div>
		<div className="newspack-insights__conversion-top-pages">
			<h3 className="newspack-insights__conversion-subheading">{ __( 'Top pages that don’t convert', 'newspack-plugin' ) }</h3>
			<SortableTable
				columns={ TOP_PAGES_COLUMNS }
				rows={ current.top_pages_no_conversion.rows }
				getRowKey={ row => row.post_id }
				defaultSortKey="pageviews"
				initialRowLimit={ TOP_PAGES_ROW_LIMIT }
				emptyMessage={ sprintf(
					/* translators: %s: minimum pageview count for a page to qualify (formatted). */
					__(
						'No qualifying pages yet. Pages with at least %s pageviews and a measurable conversion rate will appear here.',
						'newspack-plugin'
					),
					formatNumber( current.top_pages_no_conversion.threshold_pageviews )
				) }
			/>
			<p className="newspack-insights__conversion-top-pages-note">
				{ __(
					'These pages get traffic but don’t drive registrations. Consider adding a gate or prompt where engagement is high but conversion is low.',
					'newspack-plugin'
				) }
			</p>
		</div>
	</section>
);

export default OpportunityBucketsSection;
