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
import SectionEmpty from '../components/SectionEmpty';
import SectionHeading from '../components/SectionHeading';
import { formatCurrency, formatNumber } from '../components/format';

export interface CampaignSectionProps {
	rows: DonorsCampaignRow[];
}

const HEADING_ID = 'newspack-insights-donors-campaign-heading';

const CampaignSection = ( { rows }: CampaignSectionProps ) => {
	const title = __( 'Donations by campaign', 'newspack-plugin' );

	// Empty when there is nothing tagged to show: no rows at all, or only the
	// trailing "(no campaign)" untagged row.
	const hasTagged = rows.some( row => ! row.is_untagged );
	if ( ! hasTagged ) {
		return (
			<section className="newspack-insights__section newspack-insights__section--performance" aria-labelledby={ HEADING_ID }>
				<SectionHeading id={ HEADING_ID } title={ title } />
				<SectionEmpty>{ __( 'No campaign-tagged donations in this window.', 'newspack-plugin' ) }</SectionEmpty>
			</section>
		);
	}

	return (
		<section className="newspack-insights__section newspack-insights__section--performance" aria-labelledby={ HEADING_ID }>
			<SectionHeading
				id={ HEADING_ID }
				title={ title }
				description={ __(
					'New donations in the selected timeframe grouped by the UTM campaign on the order. Covers campaign-tagged donations only; untagged donations are grouped as “(no campaign).”',
					'newspack-plugin'
				) }
			/>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Campaign', 'newspack-plugin' ) }</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Donations', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Revenue', 'newspack-plugin' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, index ) => (
							<tr
								key={ row.is_untagged ? '__untagged__' : `${ index }-${ row.value }` }
								className={ row.is_untagged ? 'newspack-insights__table-row--untagged' : undefined }
							>
								<td>{ row.value }</td>
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
