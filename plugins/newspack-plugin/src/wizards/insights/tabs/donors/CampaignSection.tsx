/**
 * CampaignSection (NEWS-2580) — Donations by campaign.
 *
 * New donations in the selected timeframe grouped by the `utm_campaign` attached
 * to the donation order (Woo order meta, anonymous-inclusive, no GA4). Static
 * server-ordered table (ranked count desc), matching the adjacent
 * "Donations by tier" section on this tab — not user-sortable.
 *
 * Coverage is the UTM-**tagged** subset only (donors who arrived via a tagged
 * link). Untagged donations collapse into a single trailing "(no campaign)" row
 * that renders its real count + revenue (a denominator, not a blank) and is
 * visually de-emphasized. The backend already orders it last. The empty state
 * fires when there is no data at all OR only the untagged row — i.e. no
 * campaign-tagged donations in the window.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonorsCampaignRow } from '../../api/donors';
import InsightsDataView from '../components/InsightsDataView';
import type { InsightsColumn } from '../components/InsightsDataView';
import Section from '../components/Section';
import SectionEmpty from '../components/SectionEmpty';
import SectionHeading from '../components/SectionHeading';
import { formatCurrency, formatNumber } from '../components/format';
import { Card } from '../../../../../packages/components/src';

export interface CampaignSectionProps {
	rows: DonorsCampaignRow[];
}

const HEADING_ID = 'newspack-insights-donors-campaign-heading';

const CampaignSection = ( { rows }: CampaignSectionProps ) => {
	const title = __( 'Donations by campaign', 'newspack-plugin' );

	// Server-ordered (ranked count desc, untagged last), so the table is not
	// user-sortable — columns omit `sortValue`.
	const columns: InsightsColumn< DonorsCampaignRow >[] = [
		{
			key: 'value',
			label: __( 'Campaign', 'newspack-plugin' ),
			render: row =>
				row.is_untagged ? <span className="newspack-insights__table-na">{ __( '(no campaign)', 'newspack-plugin' ) }</span> : row.value,
		},
		{
			key: 'count',
			label: __( 'Donations', 'newspack-plugin' ),
			numeric: true,
			render: row => formatNumber( row.count ),
		},
		{
			key: 'amount',
			label: __( 'Revenue', 'newspack-plugin' ),
			numeric: true,
			render: row => formatCurrency( row.amount ).display,
		},
	];

	// Empty when there is nothing tagged to show: no rows at all, or only the
	// trailing "(no campaign)" untagged row.
	const hasTagged = rows.some( row => ! row.is_untagged );
	if ( ! hasTagged ) {
		return (
			<Section className="newspack-insights__section newspack-insights__section--performance" aria-labelledby={ HEADING_ID }>
				<SectionHeading id={ HEADING_ID } title={ title } />
				<SectionEmpty>{ __( 'No campaign-tagged donations in this window.', 'newspack-plugin' ) }</SectionEmpty>
			</Section>
		);
	}

	return (
		<Section className="newspack-insights__section newspack-insights__section--performance" aria-labelledby={ HEADING_ID }>
			<SectionHeading
				id={ HEADING_ID }
				title={ title }
				description={ __(
					'New donations in the selected timeframe grouped by the UTM campaign on the order. Covers campaign-tagged donations only; untagged donations are grouped as “(no campaign).”',
					'newspack-plugin'
				) }
			/>
			<Card __experimentalCoreCard className="newspack-insights__chart-card">
				<InsightsDataView< DonorsCampaignRow >
					columns={ columns }
					rows={ rows }
					getRowKey={ row => ( row.is_untagged ? '__untagged__' : `campaign:${ row.value }` ) }
					emptyMessage={ __( 'No campaign-tagged donations in this window.', 'newspack-plugin' ) }
				/>
			</Card>
		</Section>
	);
};

export default CampaignSection;
