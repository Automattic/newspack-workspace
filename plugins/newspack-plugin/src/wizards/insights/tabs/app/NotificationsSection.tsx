/**
 * NotificationsSection (App tab, Tab 10 — NPPD-1882).
 *
 * How push notifications perform: open rate, volume received, and opt-in
 * activity. Event-based (Bolt / GA4 notification events), so it works on any
 * Pugpig app property. Third of the Tier-1 app metric sections.
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

export interface NotificationsSectionProps {
	metrics: AppMetrics;
}

const NotificationsSection = ( { metrics }: NotificationsSectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-app-notifications-heading">
		<SectionHeading
			id="newspack-insights-app-notifications-heading"
			title={ __( 'Notifications', 'newspack-plugin' ) }
			description={ __( 'How your push notifications perform.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__metric-grid">
			<Scorecard
				label={ __( 'Notification open rate', 'newspack-plugin' ) }
				description={ __( 'Share of received push notifications that were opened.', 'newspack-plugin' ) }
				current={ metrics.notification_open_rate }
			/>
			<Scorecard
				label={ __( 'Notifications received', 'newspack-plugin' ) }
				description={ __( 'Push notifications delivered to app users in this timeframe.', 'newspack-plugin' ) }
				current={ metrics.notifications_received }
			/>
			<Scorecard
				label={ __( 'Opt-in changes', 'newspack-plugin' ) }
				description={ __( 'Times users changed their push-notification permission.', 'newspack-plugin' ) }
				current={ metrics.notification_opt_changes }
			/>
		</div>
	</section>
);

export default NotificationsSection;
