/**
 * Advertising › Reach & revenue (NPPD-1618, Section 1).
 *
 * Headline scorecards: how many impressions you served and the revenue they
 * earned this timeframe. Prominent two-up treatment.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/advertising';
import Scorecard from '../../components/Scorecard';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const ReachRevenueSection = ( { current, previous }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-reach-revenue">
		<h2 id="newspack-insights-advertising-reach-revenue" className="newspack-insights__section-heading">
			{ __( 'Reach & revenue', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'Impressions served and revenue earned this period.', 'newspack-plugin' ) }</p>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-2">
			<Scorecard
				label={ __( 'Total Impressions', 'newspack-plugin' ) }
				description={ __( 'Ad impressions served', 'newspack-plugin' ) }
				current={ current.total_impressions }
				previous={ previous?.total_impressions }
			/>
			<Scorecard
				label={ __( 'Total Revenue', 'newspack-plugin' ) }
				description={ __( 'Gross ad revenue', 'newspack-plugin' ) }
				current={ current.total_revenue }
				previous={ previous?.total_revenue }
			/>
		</div>
	</section>
);

export default ReachRevenueSection;
