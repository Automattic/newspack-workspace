/**
 * PerJourneyConversionFunnelsSection (NPPD-1609, Section 2).
 *
 * Four small funnels in a 2-column grid: anonymous → registered,
 * registered → subscriber, registered → donor, and the visibility-gated
 * subscriber → donor cross-upsell. The cross-upsell cell shows an
 * empty-state note instead of a funnel while hidden (Phase 1 default).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';
import Funnel from './viz/Funnel';

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

const PerJourneyConversionFunnelsSection = ( { current }: PerJourneyConversionFunnelsSectionProps ) => {
	const crossUpsell = current.subscriber_to_donor_funnel;
	const crossUpsellHidden = crossUpsell.visibility === 'hidden';
	return (
		<section
			className="newspack-insights__section newspack-insights__section--per-journey-funnels"
			aria-labelledby="newspack-insights-conversion-per-journey-heading"
		>
			<h2 id="newspack-insights-conversion-per-journey-heading" className="newspack-insights__section-heading">
				{ __( 'Per-journey conversion funnels', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __(
					'Focused conversion paths. Each funnel shows where readers drop off within a specific journey — anonymous to registered, registered to paid, paid to donor.',
					'newspack-plugin'
				) }
			</p>
			<div className="newspack-insights__conversion-journey-grid">
				<JourneyFunnel
					title={ __( 'Anonymous → Registered', 'newspack-plugin' ) }
					caption={ __(
						'The free-conversion path. Combines gate and prompt impressions to make the funnel readable; per-surface breakdowns live in Tabs 4 and 5.',
						'newspack-plugin'
					) }
				>
					<Funnel stages={ current.anonymous_to_registered_funnel.stages } />
				</JourneyFunnel>
				<JourneyFunnel
					title={ __( 'Registered → Subscriber', 'newspack-plugin' ) }
					caption={ __(
						'The paid-upsell path. Subscription excludes donation products; donor conversions are in the next funnel.',
						'newspack-plugin'
					) }
				>
					<Funnel stages={ current.registered_to_subscriber_funnel.stages } />
				</JourneyFunnel>
				<JourneyFunnel
					title={ __( 'Registered → Donor', 'newspack-plugin' ) }
					caption={ __( 'The donation-conversion path.', 'newspack-plugin' ) }
				>
					<Funnel stages={ current.registered_to_donor_funnel.stages } />
				</JourneyFunnel>
				<JourneyFunnel
					title={ __( 'Subscriber → Donor (cross-upsell)', 'newspack-plugin' ) }
					caption={ __( 'Cross-upsell visibility for publishers running both subscriptions and donations.', 'newspack-plugin' ) }
				>
					{ crossUpsellHidden ? (
						<p className="newspack-insights__conversion-gated-note">
							{ __(
								'Cross-upsell view appears when both subscription and donation programs have at least 50 active participants.',
								'newspack-plugin'
							) }
						</p>
					) : (
						<Funnel stages={ crossUpsell.stages } />
					) }
				</JourneyFunnel>
			</div>
		</section>
	);
};

export default PerJourneyConversionFunnelsSection;
