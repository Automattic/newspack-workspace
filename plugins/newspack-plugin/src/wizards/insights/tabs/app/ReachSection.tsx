/**
 * ReachSection (App tab, Tab 10 — NPPD-1882).
 *
 * "Reach" — how many people use the app and on what. Three scorecards (active
 * users, new users, sessions) plus platform and app-version breakdowns as
 * composition pies. First of the Tier-1 app metric sections; Engagement,
 * Notifications, Editions follow.
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AppMetrics } from '../../api/app';
import { Grid } from '../../../../../packages/components/src';
import { toSeries } from '../components/metrics';
import Scorecard from '../components/Scorecard';
import ChartCard from '../components/ChartCard';
import PieChart from '../components/PieChart';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';

export interface ReachSectionProps {
	metrics: AppMetrics;
	previous?: AppMetrics | null;
	/** The shared "Last updated" + kebab chrome, hosted in this (first) section's heading. */
	lastUpdated?: ReactNode;
}

const ReachSection = ( { metrics, previous, lastUpdated }: ReachSectionProps ) => (
	<Section className="newspack-insights__section" aria-labelledby="newspack-insights-app-reach-heading">
		<SectionHeading
			id="newspack-insights-app-reach-heading"
			title={ __( 'Reach', 'newspack-plugin' ) }
			description={ __( 'How many people use your app, and on what.', 'newspack-plugin' ) }
			actions={ lastUpdated }
		/>
		<Grid columns={ 3 } gutter={ 16 } noMargin>
			<Scorecard
				label={ __( 'Active users', 'newspack-plugin' ) }
				description={ __( 'Distinct people who opened the app', 'newspack-plugin' ) }
				current={ metrics.active_users }
				previous={ previous?.active_users }
			/>
			<Scorecard
				label={ __( 'New users', 'newspack-plugin' ) }
				description={ __( 'First-time app users', 'newspack-plugin' ) }
				current={ metrics.new_users }
				previous={ previous?.new_users }
			/>
			<Scorecard
				label={ __( 'Sessions', 'newspack-plugin' ) }
				description={ __( 'Total app sessions', 'newspack-plugin' ) }
				current={ metrics.sessions }
				previous={ previous?.sessions }
			/>
		</Grid>
		<Grid columns={ 2 } gutter={ 16 } noMargin>
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
		</Grid>
	</Section>
);

export default ReachSection;
