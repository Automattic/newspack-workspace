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
import SectionEmpty from '../components/SectionEmpty';
import SectionHeading from '../components/SectionHeading';
import { formatCurrency, formatNumber } from '../components/format';

export interface CampaignSectionProps {
	rows: SubscribersCampaignRow[];
}

const HEADING_ID = 'newspack-insights-subscribers-campaign-heading';

const CampaignSection = ( { rows }: CampaignSectionProps ) => {
	const title = __( 'Subscriptions by campaign', 'newspack-plugin' );

	// Empty when there is nothing tagged to show: no rows at all, or only the
	// trailing "(no campaign)" untagged row.
	const hasTagged = rows.some( row => ! row.is_untagged );
	if ( ! hasTagged ) {
		return (
			<section className="newspack-insights__section newspack-insights__section--performance" aria-labelledby={ HEADING_ID }>
				<SectionHeading id={ HEADING_ID } title={ title } />
				<SectionEmpty>{ __( 'No campaign-tagged subscriptions in this window.', 'newspack-plugin' ) }</SectionEmpty>
			</section>
		);
	}

	return (
		<section className="newspack-insights__section newspack-insights__section--performance" aria-labelledby={ HEADING_ID }>
			<SectionHeading
				id={ HEADING_ID }
				title={ title }
				description={ __(
					'New subscriptions in the selected timeframe grouped by the UTM campaign on the initial order. Covers campaign-tagged subscriptions only; untagged subscriptions are grouped as “(no campaign).”',
					'newspack-plugin'
				) }
			/>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Campaign', 'newspack-plugin' ) }</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'New subscriptions', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Revenue', 'newspack-plugin' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( row => (
							<tr
								key={ row.is_untagged ? '__untagged__' : `campaign:${ row.value }` }
								className={ row.is_untagged ? 'newspack-insights__table-row--untagged' : undefined }
							>
								<td>{ row.is_untagged ? __( '(no campaign)', 'newspack-plugin' ) : row.value }</td>
								<td className="newspack-insights__table-num">{ formatNumber( row.count ) }</td>
								<td className="newspack-insights__table-num">{ formatCurrency( row.amount ).display }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
};

export default CampaignSection;
