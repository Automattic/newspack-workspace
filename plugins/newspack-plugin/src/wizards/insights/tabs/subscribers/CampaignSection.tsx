/**
 * CampaignSection (NEWS-2591) — Subscriptions by campaign.
 *
 * New subscriptions in the selected timeframe grouped by the `utm_campaign` on
 * the initial subscription order (Woo order meta, anonymous-inclusive, no GA4).
 * Static server-ordered table (ranked count desc), matching the adjacent
 * "Subscriptions by product" section on this tab — not user-sortable.
 *
 * Coverage is the UTM-**tagged** subset only (subscribers who arrived via a
 * tagged link). Untagged subscriptions collapse into a single trailing
 * "(no campaign)" row that renders its real count + revenue (a denominator, not
 * a blank) and is visually de-emphasized. The backend already orders it last.
 * The empty state fires when there is no data at all OR only the untagged row —
 * i.e. no campaign-tagged subscriptions in the window.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SubscribersCampaignRow } from '../../api/subscribers';
import InsightsDataView from '../components/InsightsDataView';
import type { InsightsColumn } from '../components/InsightsDataView';
import Section from '../components/Section';
import SectionEmpty from '../components/SectionEmpty';
import SectionHeading from '../components/SectionHeading';
import { formatCurrency, formatNumber } from '../components/format';

export interface CampaignSectionProps {
	rows: SubscribersCampaignRow[];
}

const HEADING_ID = 'newspack-insights-subscribers-campaign-heading';

const CampaignSection = ( { rows }: CampaignSectionProps ) => {
	const title = __( 'Subscriptions by campaign', 'newspack-plugin' );

	// Server-ordered (ranked count desc, untagged last), so the table is not
	// user-sortable — columns omit `sortValue`.
	const columns: InsightsColumn< SubscribersCampaignRow >[] = [
		{
			key: 'value',
			label: __( 'Campaign', 'newspack-plugin' ),
			render: row =>
				row.is_untagged ? <span className="newspack-insights__table-na">{ __( '(no campaign)', 'newspack-plugin' ) }</span> : row.value,
		},
		{
			key: 'count',
			label: __( 'New subscriptions', 'newspack-plugin' ),
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
				<SectionEmpty>{ __( 'No campaign-tagged subscriptions in this window.', 'newspack-plugin' ) }</SectionEmpty>
			</Section>
		);
	}

	return (
		<Section className="newspack-insights__section newspack-insights__section--performance" aria-labelledby={ HEADING_ID }>
			<SectionHeading
				id={ HEADING_ID }
				title={ title }
				description={ __(
					'New subscriptions in the selected timeframe grouped by the UTM campaign on the initial order. Covers campaign-tagged subscriptions only; untagged subscriptions are grouped as “(no campaign).”',
					'newspack-plugin'
				) }
			/>
			<InsightsDataView< SubscribersCampaignRow >
				columns={ columns }
				rows={ rows }
				getRowKey={ row => ( row.is_untagged ? '__untagged__' : `campaign:${ row.value }` ) }
				emptyMessage={ __( 'No campaign-tagged subscriptions in this window.', 'newspack-plugin' ) }
			/>
		</Section>
	);
};

export default CampaignSection;
