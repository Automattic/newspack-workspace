/**
 * Newsletter Ads › Top performers (NPPD-1861).
 *
 * Mirrors Advertising's TopPerformersSection exactly: MetricTable (static, not
 * sortable) with Top ads and Top advertisers side by side (collapsed to 5 rows
 * behind "See more"), and the per-newsletter breakdown full-width below.
 * MetricTable renders null cells (CTR/revenue without impressions or price) as
 * the em-dash natively — never a misleading 0% / $0.00.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ByNewsletterRow, InsightsWindow } from '../../../api/newsletter_ads';
import type { MetricPayload } from '../../components/metrics';
import { formatShortDate } from '../../components/format';
import MetricTable from '../../components/MetricTable';
import SectionHeading from '../../components/SectionHeading';

export interface SectionProps {
	current: InsightsWindow;
}

/**
 * Present the by-newsletter payload for display: sent_date arrives as
 * 'YYYY-MM-DD' and MetricTable renders strings verbatim, so format it here
 * (formatShortDate expects GA4's 'YYYYMMDD' — strip the separators).
 */
const withDisplayDates = ( payload?: MetricPayload ): MetricPayload | undefined => {
	if ( ! payload || ! Array.isArray( payload.rows ) ) {
		return payload;
	}
	return {
		...payload,
		rows: ( payload.rows as unknown as ByNewsletterRow[] ).map( row => ( {
			...row,
			sent_date: formatShortDate( row.sent_date.replace( /\D/g, '' ) ),
		} ) ),
	};
};

const TablesSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-newsletter-ads-top-performers">
		<SectionHeading
			id="newspack-insights-newsletter-ads-top-performers"
			title={ __( 'Top performers', 'newspack-plugin' ) }
			description={ __( 'Which ads, advertisers, and newsletters drive your results in the selected timeframe.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__table-grid newspack-insights__table-grid--cols-2">
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top ads', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_ads }
					emptyMessage={ __( 'No ad data in this timeframe.', 'newspack-plugin' ) }
					expandable
					defaultRowLimit={ 5 }
					columns={ [
						{ key: 'title', label: __( 'Ad', 'newspack-plugin' ) },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'clicks', label: __( 'Clicks', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'ctr', label: __( 'CTR', 'newspack-plugin' ), format: 'percent', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</div>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top advertisers', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_advertisers }
					emptyMessage={ __( 'No advertiser data in this timeframe.', 'newspack-plugin' ) }
					expandable
					defaultRowLimit={ 5 }
					columns={ [
						{ key: 'advertiser', label: __( 'Advertiser', 'newspack-plugin' ) },
						{ key: 'ads', label: __( 'Ads', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'clicks', label: __( 'Clicks', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</div>
		</div>
		<div className="newspack-insights__table-grid">
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Ad performance by newsletter', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ withDisplayDates( current.by_newsletter ) }
					emptyMessage={ __( 'No newsletters carried ads in this timeframe.', 'newspack-plugin' ) }
					expandable
					defaultRowLimit={ 5 }
					columns={ [
						{ key: 'title', label: __( 'Newsletter', 'newspack-plugin' ) },
						{ key: 'sent_date', label: __( 'Sent date', 'newspack-plugin' ) },
						{ key: 'ads', label: __( 'Ads carried', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'clicks', label: __( 'Clicks', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'ctr', label: __( 'CTR', 'newspack-plugin' ), format: 'percent', align: 'right' },
					] }
				/>
			</div>
		</div>
	</section>
);

export default TablesSection;
