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
import { Grid } from '../../../../../../packages/components/src';
import type { InsightsWindow } from '../../../api/audience';
import type { MetricPayload } from '../../components/metrics';
import ChartCard from '../../components/ChartCard';
import MetricTable from '../../components/MetricTable';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import { toSeries, isNotSet } from '../../components/metrics';
import PieChart from '../../components/PieChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const CAMPAIGN_KEYS = [ 'source', 'medium', 'campaign' ];

/**
 * Drop campaign rows that carry no real attribution — every one of
 * source/medium/campaign is empty or GA4's "(not set)". Publishers who don't
 * tag traffic with UTMs get a row (or rows) of pure "(not set)" noise; we filter
 * those out at render time while leaving rows that have data in any column
 * (including their "(not set)" cells) intact. The orchestrator still returns all
 * rows — the decision is purely presentational. Error/overlay payloads (no
 * `rows` array) pass through untouched so ChartCard can surface their note.
 */
const withoutUnattributedRows = ( payload?: MetricPayload ): MetricPayload | undefined => {
	if ( ! payload || ! Array.isArray( payload.rows ) ) {
		return payload;
	}
	return {
		...payload,
		rows: payload.rows.filter( row => CAMPAIGN_KEYS.some( key => ! isNotSet( row[ key ] ) ) ),
	};
};

const TrafficSourcesSection = ( { current }: SectionProps ) => {
	const campaigns = withoutUnattributedRows( current.top_campaigns );

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-traffic">
			<SectionHeading
				id="newspack-insights-audience-traffic"
				title={ __( 'Traffic sources', 'newspack-plugin' ) }
				description={ __( 'Where your readers come from.', 'newspack-plugin' ) }
			/>
			{ /* Channel breakdown (1 column) reads as a unit with the campaigns
			     driving each channel (spans the other 2 columns) — NPPD-1649 fix #3. */ }
			<Grid columns={ 3 } gutter={ 16 } noMargin>
				<ChartCard title={ __( 'Traffic sources breakdown', 'newspack-plugin' ) } payload={ current.traffic_sources_breakdown }>
					<PieChart segments={ toSeries( current.traffic_sources_breakdown, 'channel', 'readers' ) } />
				</ChartCard>
				<ChartCard title={ __( 'Top campaigns', 'newspack-plugin' ) } payload={ campaigns } style={ { gridColumn: 'span 2' } }>
					<MetricTable
						payload={ campaigns }
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
			</Grid>
		</Section>
	);
};

export default TrafficSourcesSection;
