/**
 * Audience › Audience composition (NPPD-1649, Section 2).
 *
 * Who your readers are — subscribers, logged-in, devices.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../../packages/components/src';
import type { InsightsWindow } from '../../../api/audience';
import ChartCard from '../../components/ChartCard';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import { toSeries } from '../../components/metrics';
import PieChart from '../../components/PieChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const CompositionSection = ( { current }: SectionProps ) => (
	<Section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-composition">
		<SectionHeading
			id="newspack-insights-audience-composition"
			title={ __( 'Audience Composition', 'newspack-plugin' ) }
			description={ __( "Who's reading your stories.", 'newspack-plugin' ) }
		/>
		<Grid columns={ 2 } gutter={ 16 } noMargin>
			<ChartCard
				title={ __( 'Newsletter Subscriber Composition', 'newspack-plugin' ) }
				caption={ __( 'Your newsletter subscribers vs the rest', 'newspack-plugin' ) }
				payload={ current.newsletter_subscriber_composition }
			>
				<PieChart segments={ toSeries( current.newsletter_subscriber_composition, 'label', 'value' ) } />
			</ChartCard>
			<ChartCard
				title={ __( 'Logged-in vs Anonymous', 'newspack-plugin' ) }
				caption={ __( "Who's signed in", 'newspack-plugin' ) }
				payload={ current.logged_in_vs_anonymous_composition }
			>
				<PieChart segments={ toSeries( current.logged_in_vs_anonymous_composition, 'label', 'value' ) } />
			</ChartCard>
			<ChartCard
				title={ __( 'Device Breakdown', 'newspack-plugin' ) }
				caption={ __( 'What devices your readers use', 'newspack-plugin' ) }
				payload={ current.device_breakdown }
			>
				<PieChart segments={ toSeries( current.device_breakdown, 'device', 'readers' ) } />
			</ChartCard>
			<ChartCard
				title={ __( 'Supporter Type', 'newspack-plugin' ) }
				caption={ __( 'Subscribers, donors, and registered readers among your logged-in audience.', 'newspack-plugin' ) }
				payload={ current.supporter_type }
			>
				<PieChart segments={ toSeries( current.supporter_type, 'label', 'value' ) } />
			</ChartCard>
		</Grid>
	</Section>
);

export default CompositionSection;
