/**
 * CompositionSection (App tab, Tab 10 — NPPD-1882, Tier-2a).
 *
 * Who the app audience is and what they read: the subscriber-status mix
 * (`KGSubscriberStatus`) and the free-vs-paid content split (`KGStoryCost`),
 * each as a composition pie. Both are KG custom dimensions, so each card
 * renders its "not configured" state until the dimension is registered on the
 * property (auto-registration is Tier-2b).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AppMetrics } from '../../api/app';
import { toSeries } from '../components/metrics';
import ChartCard from '../components/ChartCard';
import PieChart from '../components/PieChart';
import SectionHeading from '../components/SectionHeading';

export interface CompositionSectionProps {
	metrics: AppMetrics;
}

/**
 * Humanize a raw KG dimension value like "ExistingSubscriber" into a spaced,
 * sentence-cased label ("Existing subscriber"). Single-word values (e.g. "None")
 * just get a capitalized first letter. The underlying GA4 value is preserved —
 * this is display-only.
 */
const humanizeStatus = ( label: string ): string => {
	const spaced = label.replace( /([a-z])([A-Z])/g, '$1 $2' ).trim();
	if ( ! spaced ) {
		return label;
	}
	return spaced.charAt( 0 ).toUpperCase() + spaced.slice( 1 ).toLowerCase();
};

const CompositionSection = ( { metrics }: CompositionSectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-app-composition-heading">
		<SectionHeading
			id="newspack-insights-app-composition-heading"
			title={ __( 'Audience & access', 'newspack-plugin' ) }
			description={ __( 'The subscriber mix of your app audience and the free-vs-paid content they read.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">
			<ChartCard
				title={ __( 'Subscriber mix', 'newspack-plugin' ) }
				caption={ __( 'Active users by subscriber status', 'newspack-plugin' ) }
				payload={ metrics.subscriber_mix }
			>
				<PieChart
					segments={ toSeries( metrics.subscriber_mix, 'status', 'users' ).map( segment => ( {
						...segment,
						label: humanizeStatus( segment.label ),
					} ) ) }
				/>
			</ChartCard>
			<ChartCard
				title={ __( 'Free vs. paid content', 'newspack-plugin' ) }
				caption={ __( 'Screen views by content access level', 'newspack-plugin' ) }
				payload={ metrics.content_cost }
			>
				<PieChart segments={ toSeries( metrics.content_cost, 'cost', 'views' ) } />
			</ChartCard>
		</div>
	</section>
);

export default CompositionSection;
