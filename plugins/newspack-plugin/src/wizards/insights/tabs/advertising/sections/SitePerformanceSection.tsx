/**
 * Advertising › Performance by site (NPPD-1671).
 *
 * Network-only: a full-width table breaking impressions / revenue / eCPM down by
 * the GAM `site` custom dimension newspack-network creates for each site in a
 * Newspack Network. Rendered only for network members (AdvertisingTab gates on
 * `is_network_member`); absent entirely for standalone publishers.
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
import SectionHeading from '../../components/SectionHeading';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const SitePerformanceSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-site-performance">
		<SectionHeading
			id="newspack-insights-advertising-site-performance"
			title={ __( 'Performance by site', 'newspack-plugin' ) }
			description={ __( 'How each site in your network is performing.', 'newspack-plugin' ) }
		/>
		<MetricTable
			payload={ current.top_sites }
			emptyMessage={ __( 'No per-site data in this timeframe.', 'newspack-plugin' ) }
			expandable
			defaultRowLimit={ 10 }
			columns={ [
				{ key: 'site', label: __( 'Site', 'newspack-plugin' ) },
				{ key: 'impressions', label: __( 'Impr.', 'newspack-plugin' ), format: 'number', align: 'right' },
				{ key: 'revenue', label: __( 'Revenue', 'newspack-plugin' ), format: 'currency', align: 'right' },
				{ key: 'ecpm', label: __( 'eCPM', 'newspack-plugin' ), format: 'currency', align: 'right' },
			] }
		/>
	</section>
);

export default SitePerformanceSection;
