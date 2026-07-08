/**
 * EngagementSection (App tab, Tab 10 — NPPD-1882).
 *
 * "Engagement" — how deeply people use the app. Leads with average engagement
 * time (app sessions run far longer than web), plus engagement rate, engaged
 * sessions, screens per session, and screen views. Second of the Tier-1 app
 * metric sections. (Retention lands as its own cohort section.)
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AppMetrics } from '../../api/app';
import Scorecard from '../components/Scorecard';
import SectionHeading from '../components/SectionHeading';

export interface EngagementSectionProps {
	metrics: AppMetrics;
	previous?: AppMetrics | null;
}

const EngagementSection = ( { metrics, previous }: EngagementSectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-app-engagement-heading">
		<SectionHeading
			id="newspack-insights-app-engagement-heading"
			title={ __( 'Engagement', 'newspack-plugin' ) }
			description={ __( 'How deeply people use your app.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-3">
			<Scorecard
				label={ __( 'Avg. engagement time', 'newspack-plugin' ) }
				description={ __( 'Average time in the app per session — app readers tend to stay far longer than on the web', 'newspack-plugin' ) }
				current={ metrics.avg_engagement_time }
				previous={ previous?.avg_engagement_time }
			/>
			<Scorecard
				label={ __( 'Engagement rate', 'newspack-plugin' ) }
				description={ __( 'Share of sessions that were engaged (meaningful time, a conversion, or multiple screens)', 'newspack-plugin' ) }
				current={ metrics.engagement_rate }
				previous={ previous?.engagement_rate }
			/>
			<Scorecard
				label={ __( 'Engaged sessions', 'newspack-plugin' ) }
				description={ __( 'Sessions that lasted 10+ seconds, had a key event, or viewed 2+ screens', 'newspack-plugin' ) }
				current={ metrics.engaged_sessions }
				previous={ previous?.engaged_sessions }
			/>
			<Scorecard
				label={ __( 'Screens per session', 'newspack-plugin' ) }
				description={ __( 'Average screens viewed per app session', 'newspack-plugin' ) }
				current={ metrics.screens_per_session }
				previous={ previous?.screens_per_session }
			/>
			<Scorecard
				label={ __( 'Screen views', 'newspack-plugin' ) }
				description={ __( 'Total app screens viewed', 'newspack-plugin' ) }
				current={ metrics.screen_views }
				previous={ previous?.screen_views }
			/>
		</div>
	</section>
);

export default EngagementSection;
