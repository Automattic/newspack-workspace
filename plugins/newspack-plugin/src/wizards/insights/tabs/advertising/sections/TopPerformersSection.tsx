/**
 * Advertising › Top performers (Section 3).
 *
 * Top ad units, Top advertisers, and Top campaigns (direct-sold orders), each
 * its own top-level section (h2) stacked at full width — ad unit and advertiser
 * names run long, so a side-by-side pair truncated badly. The first two carry
 * clicks + CTR. All three collapse to 5 rows with a "See more" toggle. The
 * ad-units table intentionally omits the row-level eCPM the payload still
 * carries — Revenue next to eCPM double-counted the story.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/advertising';
import MetricTable from '../../components/MetricTable';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import TabSections from '../../components/TabSections';
import { Card } from '../../../../../../packages/components/src';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const TopPerformersSection = ( { current }: SectionProps ) => (
	<TabSections>
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-top-ad-units">
			<SectionHeading id="newspack-insights-advertising-top-ad-units" title={ __( 'Top ad units', 'newspack-plugin' ) } />
			<Card __experimentalCoreCard className="newspack-insights__chart-card">
				<MetricTable
					payload={ current.top_ad_units }
					emptyMessage={ __( 'No ad unit data in this timeframe.', 'newspack-plugin' ) }
					expandable
					defaultRowLimit={ 5 }
					columns={ [
						{ key: 'ad_unit', label: __( 'Ad unit', 'newspack-plugin' ) },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'clicks', label: __( 'Clicks', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'ctr', label: __( 'CTR', 'newspack-plugin' ), format: 'percent', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</Card>
		</Section>
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-top-advertisers">
			<SectionHeading id="newspack-insights-advertising-top-advertisers" title={ __( 'Top advertisers', 'newspack-plugin' ) } />
			<Card __experimentalCoreCard className="newspack-insights__chart-card">
				<MetricTable
					payload={ current.top_advertisers }
					emptyMessage={ __( 'No advertiser data in this timeframe.', 'newspack-plugin' ) }
					expandable
					defaultRowLimit={ 5 }
					columns={ [
						{ key: 'advertiser', label: __( 'Advertiser', 'newspack-plugin' ) },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'clicks', label: __( 'Clicks', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'ctr', label: __( 'CTR', 'newspack-plugin' ), format: 'percent', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</Card>
		</Section>
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-top-campaigns">
			<SectionHeading id="newspack-insights-advertising-top-campaigns" title={ __( 'Top campaigns', 'newspack-plugin' ) } />
			{ /* Direct-sold orders only — programmatic delivery is order-less and
			     filtered server-side. */ }
			<Card __experimentalCoreCard className="newspack-insights__chart-card">
				<MetricTable
					payload={ current.top_campaigns }
					emptyMessage={ __( 'No campaign data in this timeframe.', 'newspack-plugin' ) }
					expandable
					defaultRowLimit={ 5 }
					columns={ [
						{ key: 'campaign', label: __( 'Campaign', 'newspack-plugin' ) },
						{ key: 'advertiser', label: __( 'Advertiser', 'newspack-plugin' ) },
						{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'clicks', label: __( 'Clicks', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'ctr', label: __( 'CTR', 'newspack-plugin' ), format: 'percent', align: 'right' },
						{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
					] }
				/>
			</Card>
		</Section>
	</TabSections>
);

export default TopPerformersSection;
