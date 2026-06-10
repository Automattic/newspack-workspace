/**
 * OpportunityBucketsSection (NPPD-1609, Section 8).
 *
 * Three snapshot scorecards (stale registered readers, at-risk
 * subscribers, lapsed donors) above a full-width "top pages that don't
 * convert" table. The scorecards are current-state counts — no comparison
 * deltas. Scaffold renders the three cards + a table placeholder; the
 * SortableTable is wired in the following commit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';
import MetricCard from '../components/MetricCard';
import { scalarToMetricCardProps } from './scalarToCard';

export interface OpportunityBucketsSectionProps {
	current: ConversionWindow;
}

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
		<div className="newspack-insights__viz-placeholder" data-pending={ current.top_pages_no_conversion.pending } />
	</section>
);

export default OpportunityBucketsSection;
