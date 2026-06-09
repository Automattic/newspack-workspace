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
import type { MetricPayload, MetricRow } from '../../components/metrics';
import ChartCard from '../../components/ChartCard';
import MetricTable from '../../components/MetricTable';
import { toSeries } from '../../components/metrics';
import PieChart from '../viz/PieChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const CAMPAIGN_KEYS = [ 'source', 'medium', 'campaign' ];

/** A campaign cell is meaningless when it is empty or GA4's "(not set)". */
const isNotSet = ( value: unknown ): boolean => {
	const normalized = String( value ?? '' )
		.trim()
		.toLowerCase();
	return normalized === '' || normalized === '(not set)';
};

/**
 * True when at least one campaign row carries real source/medium/campaign data.
 * When every row is "(not set)" across all three columns the table is just noise
 * (the publisher doesn't tag traffic with UTMs), so it's hidden entirely.
 */
const hasRealCampaignData = ( payload?: MetricPayload ): boolean => {
	const rows: MetricRow[] = Array.isArray( payload?.rows ) ? ( payload as MetricPayload ).rows ?? [] : [];
	return rows.some( row => CAMPAIGN_KEYS.some( key => ! isNotSet( row[ key ] ) ) );
};

/** True when the channel-breakdown pie has renderable data. */
const hasBreakdownData = ( payload?: MetricPayload ): boolean =>
	!! payload && ! payload.hidden_in_v1 && ! payload.overlay && ! payload.error && Array.isArray( payload.rows ) && payload.rows.length > 0;

const TrafficSourcesSection = ( { current }: SectionProps ) => {
	const showCampaigns = hasRealCampaignData( current.top_campaigns );
	const showBreakdown = hasBreakdownData( current.traffic_sources_breakdown );

	// Nothing meaningful to show in either half — hide the whole section.
	if ( ! showCampaigns && ! showBreakdown ) {
		return null;
	}

	const breakdownCard = (
		<ChartCard title={ __( 'Traffic Sources Breakdown', 'newspack-plugin' ) } payload={ current.traffic_sources_breakdown }>
			<PieChart segments={ toSeries( current.traffic_sources_breakdown, 'channel', 'readers' ) } />
		</ChartCard>
	);

	const campaignsCard = (
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
	);

	return (
		<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-traffic">
			<h2 id="newspack-insights-audience-traffic" className="newspack-insights__section-heading">
				{ __( 'Traffic sources', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">{ __( 'Where your readers come from.', 'newspack-plugin' ) }</p>
			{ /* Channel breakdown (left ~35%) reads as a unit with the campaigns
			     driving each channel (right ~65%) — NPPD-1649 fix #3. When the
			     campaigns table is all "(not set)" it's hidden, and the breakdown
			     pie stands alone in a balanced half-width column. */ }
			{ showCampaigns ? (
				<div className="newspack-insights__traffic-grid">
					{ breakdownCard }
					{ campaignsCard }
				</div>
			) : (
				<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">{ breakdownCard }</div>
			) }
		</section>
	);
};

export default TrafficSourcesSection;
