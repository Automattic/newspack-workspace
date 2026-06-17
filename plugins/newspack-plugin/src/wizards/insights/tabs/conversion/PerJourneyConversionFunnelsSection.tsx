/**
 * PerJourneyConversionFunnelsSection (NPPD-1609, Section 2).
 *
 * Four small funnels in a 2-column grid: anonymous → registered (2.1),
 * registered → subscriber (2.2), registered → donor (2.3), and the
 * visibility-gated subscriber → donor cross-upsell (2.4).
 *
 * Phase 2: each funnel's rendering is gated on the metric's `state`
 * envelope (populated / empty / error / coming_soon). Section 2.4
 * also respects the `visibility` gate independently of state: when
 * `visibility === 'hidden'` the gated note is shown regardless of state.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionFunnelData, ConversionGatedFunnelData, ConversionWindow } from '../../api/conversion';
import SectionHeading from '../components/SectionHeading';
import Funnel from '../components/Funnel';
import SectionState from './SectionState';

export interface PerJourneyConversionFunnelsSectionProps {
	current: ConversionWindow;
}

interface JourneyFunnelProps {
	title: string;
	caption: string;
	children: React.ReactNode;
}

const JourneyFunnel = ( { title, caption, children }: JourneyFunnelProps ) => (
	<div className="newspack-insights__conversion-journey-cell">
		<h3 className="newspack-insights__conversion-subheading">{ title }</h3>
		<p className="newspack-insights__conversion-subcaption">{ caption }</p>
		{ children }
	</div>
);

interface StandardFunnelCellProps {
	data: ConversionFunnelData;
	emptyMessage: string;
}

const StandardFunnelCell = ( { data, emptyMessage }: StandardFunnelCellProps ) => (
	<SectionState state={ data.state } emptyMessage={ emptyMessage }>
		<Funnel stages={ data.stages } />
	</SectionState>
);

interface GatedFunnelCellProps {
	data: ConversionGatedFunnelData;
	emptyMessage: string;
}

/** Section 2.4: visibility gate takes priority over state-based rendering. */
const GatedFunnelCell = ( { data, emptyMessage }: GatedFunnelCellProps ) => {
	if ( data.visibility === 'hidden' ) {
		return (
			<p className="newspack-insights__conversion-gated-note">
				{ __(
					'Cross-upsell view appears when both subscription and donation programs have at least 50 active participants.',
					'newspack-plugin'
				) }
			</p>
		);
	}
	return (
		<SectionState state={ data.state } emptyMessage={ emptyMessage }>
			<Funnel stages={ data.stages } />
		</SectionState>
	);
};

const PerJourneyConversionFunnelsSection = ( { current }: PerJourneyConversionFunnelsSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--per-journey-funnels"
		aria-labelledby="newspack-insights-conversion-per-journey-heading"
	>
		<SectionHeading
			id="newspack-insights-conversion-per-journey-heading"
			title={ __( 'Per-journey conversion funnels', 'newspack-plugin' ) }
			description={ __(
				'Focused conversion paths. Each funnel shows where readers drop off within a specific journey — anonymous to registered, registered to paid, paid to donor.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__conversion-journey-grid">
			<JourneyFunnel
				title={ __( 'Anonymous → Registered', 'newspack-plugin' ) }
				caption={ __(
					'The free-conversion path. Combines gate and prompt impressions to make the funnel readable; per-surface breakdowns live in Tabs 4 and 5.',
					'newspack-plugin'
				) }
			>
				<StandardFunnelCell
					data={ current.anonymous_to_registered_funnel }
					emptyMessage={ __(
						'No registration funnel data yet. This will populate once registrations occur in this window.',
						'newspack-plugin'
					) }
				/>
			</JourneyFunnel>
			<JourneyFunnel
				title={ __( 'Registered → Subscriber', 'newspack-plugin' ) }
				caption={ __(
					'The paid-upsell path. Subscription excludes donation products; donor conversions are in the next funnel.',
					'newspack-plugin'
				) }
			>
				<StandardFunnelCell
					data={ current.registered_to_subscriber_funnel }
					emptyMessage={ __(
						'No subscription funnel data yet. This will populate once subscriptions occur in this window.',
						'newspack-plugin'
					) }
				/>
			</JourneyFunnel>
			<JourneyFunnel
				title={ __( 'Registered → Donor', 'newspack-plugin' ) }
				caption={ __( 'The donation-conversion path.', 'newspack-plugin' ) }
			>
				<StandardFunnelCell
					data={ current.registered_to_donor_funnel }
					emptyMessage={ __( 'No donor funnel data yet. This will populate once donations occur in this window.', 'newspack-plugin' ) }
				/>
			</JourneyFunnel>
			<JourneyFunnel
				title={ __( 'Subscriber → Donor (cross-upsell)', 'newspack-plugin' ) }
				caption={ __( 'Cross-upsell visibility for publishers running both subscriptions and donations.', 'newspack-plugin' ) }
			>
				<GatedFunnelCell
					data={ current.subscriber_to_donor_funnel }
					emptyMessage={ __( 'No cross-upsell data yet. This will populate once cross-upsell conversions occur.', 'newspack-plugin' ) }
				/>
			</JourneyFunnel>
		</div>
	</section>
);

export default PerJourneyConversionFunnelsSection;
