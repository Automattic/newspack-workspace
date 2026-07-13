/**
 * Newsletter Ads › performance tables.
 *
 * Three MetricTables (static, not sortable), each rendered as its own
 * full-width H2 section — Top ads, Top advertisers, and Ad performance by
 * newsletter — collapsed to 5 rows behind "See more". Ad titles and advertiser
 * names run long, so a side-by-side pair truncated badly. MetricTable renders
 * null cells (CTR/revenue without impressions or price) as the em-dash
 * natively — never a misleading 0% / $0.00.
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
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import TabSections from '../../components/TabSections';

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
	<TabSections>
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-newsletter-ads-top-ads">
			<SectionHeading id="newspack-insights-newsletter-ads-top-ads" title={ __( 'Top ads', 'newspack-plugin' ) } />
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
		</Section>
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-newsletter-ads-top-advertisers">
			<SectionHeading id="newspack-insights-newsletter-ads-top-advertisers" title={ __( 'Top advertisers', 'newspack-plugin' ) } />
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
		</Section>
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-newsletter-ads-by-newsletter">
			<SectionHeading id="newspack-insights-newsletter-ads-by-newsletter" title={ __( 'Ad performance by newsletter', 'newspack-plugin' ) } />
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
		</Section>
	</TabSections>
);

export default TablesSection;
