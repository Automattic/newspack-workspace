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
import { Grid } from '../../../../../packages/components/src';
import Scorecard from '../components/Scorecard';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';

export interface NotificationsSectionProps {
	metrics: AppMetrics;
	previous?: AppMetrics | null;
}

const NotificationsSection = ( { metrics, previous }: NotificationsSectionProps ) => (
	<Section className="newspack-insights__section" aria-labelledby="newspack-insights-app-notifications-heading">
		<SectionHeading
			id="newspack-insights-app-notifications-heading"
			title={ __( 'Notifications', 'newspack-plugin' ) }
			description={ __( 'How your push notifications perform.', 'newspack-plugin' ) }
		/>
		<Grid columns={ 3 } gutter={ 16 } noMargin>
			<Scorecard
				label={ __( 'Notification Open Rate', 'newspack-plugin' ) }
				description={ __( 'Share of received push notifications that were opened', 'newspack-plugin' ) }
				current={ metrics.notification_open_rate }
				previous={ previous?.notification_open_rate }
			/>
			<Scorecard
				label={ __( 'Notifications Received', 'newspack-plugin' ) }
				description={ __( 'Push notifications delivered to app users', 'newspack-plugin' ) }
				current={ metrics.notifications_received }
				previous={ previous?.notifications_received }
			/>
			<Scorecard
				label={ __( 'Opt-in Changes', 'newspack-plugin' ) }
				description={ __( 'Times users changed their push-notification permission', 'newspack-plugin' ) }
				current={ metrics.notification_opt_changes }
				previous={ previous?.notification_opt_changes }
			/>
		</Grid>
	</Section>
);

export default NotificationsSection;
