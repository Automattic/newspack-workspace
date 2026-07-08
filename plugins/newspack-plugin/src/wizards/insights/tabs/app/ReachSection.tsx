/**
 * ReachSection (App tab, Tab 10 — NPPD-1882).
 *
 * "Reach" — how many people use the app and on what. Three scorecards (active
 * users, new users, sessions) plus platform and app-version breakdowns as
 * composition pies. First of the Tier-1 app metric sections; Engagement,
 * Notifications, Editions follow.
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
import Scorecard from '../components/Scorecard';
import ChartCard from '../components/ChartCard';
import PieChart from '../components/PieChart';
import SectionHeading from '../components/SectionHeading';

export interface ReachSectionProps {
	metrics: AppMetrics;
}

const ReachSection = ( { metrics }: ReachSectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-app-reach-heading">
		<SectionHeading
			id="newspack-insights-app-reach-heading"
			title={ __( 'Reach', 'newspack-plugin' ) }
			description={ __( 'How many people use your app, and on what.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-3">
			<Scorecard
				label={ __( 'Active users', 'newspack-plugin' ) }
				description={ __( 'Distinct people who opened the app', 'newspack-plugin' ) }
				current={ metrics.active_users }
			/>
			<Scorecard
				label={ __( 'New users', 'newspack-plugin' ) }
				description={ __( 'First-time app users', 'newspack-plugin' ) }
				current={ metrics.new_users }
			/>
			<Scorecard
				label={ __( 'Sessions', 'newspack-plugin' ) }
				description={ __( 'Total app sessions', 'newspack-plugin' ) }
				current={ metrics.sessions }
			/>
		</div>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">
			<ChartCard
				title={ __( 'By platform', 'newspack-plugin' ) }
				caption={ __( 'Active users by operating system', 'newspack-plugin' ) }
				payload={ metrics.platform }
			>
				<PieChart segments={ toSeries( metrics.platform, 'platform', 'active_users' ) } />
			</ChartCard>
			<ChartCard
				title={ __( 'By app version', 'newspack-plugin' ) }
				caption={ __( 'Active users by app version', 'newspack-plugin' ) }
				payload={ metrics.app_version }
			>
				<PieChart segments={ toSeries( metrics.app_version, 'app_version', 'active_users' ) } />
			</ChartCard>
		</div>
	</section>
);

export default ReachSection;
