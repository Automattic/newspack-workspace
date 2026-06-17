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
import type { InsightsWindow } from '../../../api/audience';
import ChartCard from '../../components/ChartCard';
import SectionHeading from '../../components/SectionHeading';
import { toSeries } from '../../components/metrics';
import PieChart from '../../components/PieChart';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const CompositionSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-composition">
		<SectionHeading
			id="newspack-insights-audience-composition"
			title={ __( 'Audience composition', 'newspack-plugin' ) }
			description={ __( "Who's reading your stories.", 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-4">
			<ChartCard
				title={ __( 'Newsletter Subscriber Composition', 'newspack-plugin' ) }
				caption={ __( 'Your newsletter subscribers vs the rest', 'newspack-plugin' ) }
				payload={ current.newsletter_subscriber_composition }
			>
				<PieChart segments={ toSeries( current.newsletter_subscriber_composition, 'label', 'value' ) } />
			</ChartCard>
			<ChartCard
				title={ __( 'Logged-In vs Anonymous', 'newspack-plugin' ) }
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
		</div>
	</section>
);

export default CompositionSection;
