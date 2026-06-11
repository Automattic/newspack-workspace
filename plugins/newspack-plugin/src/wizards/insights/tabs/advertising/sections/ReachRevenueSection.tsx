/**
 * Advertising › Reach & revenue (NPPD-1618, Section 1).
 *
 * Headline scorecards for the period: impressions served, revenue earned, and
 * the revenue mix (direct share). Three equal-width cards, matching the
 * Inventory performance row's sizing.
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
import RevenueMixCard from '../RevenueMixCard';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const ReachRevenueSection = ( { current, previous }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-reach-revenue">
		<h2 id="newspack-insights-advertising-reach-revenue" className="newspack-insights__section-heading">
			{ __( 'Reach & revenue', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'Volume and revenue mix for the period.', 'newspack-plugin' ) }</p>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-3">
			<Scorecard
				label={ __( 'Total Impressions', 'newspack-plugin' ) }
				description={ __( 'Total ad impressions served on your site in this timeframe.', 'newspack-plugin' ) }
				current={ current.total_impressions }
				previous={ previous?.total_impressions }
			/>
			<Scorecard
				label={ __( 'Total Revenue', 'newspack-plugin' ) }
				description={ __( 'Total ad revenue earned in this timeframe, before fees.', 'newspack-plugin' ) }
				current={ current.total_revenue }
				previous={ previous?.total_revenue }
			/>
			<RevenueMixCard payload={ current.direct_vs_programmatic } />
		</div>
	</section>
);

export default ReachRevenueSection;
