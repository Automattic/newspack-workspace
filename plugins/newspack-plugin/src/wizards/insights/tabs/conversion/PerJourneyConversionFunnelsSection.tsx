/**
 * PerJourneyConversionFunnelsSection (NPPD-1609, Section 2).
 *
 * Four small funnels in a 2-column grid: anonymous → registered,
 * registered → subscriber, registered → donor, and the visibility-gated
 * subscriber → donor cross-upsell. Scaffold renders header + caption + an
 * empty placeholder; the Funnel viz is wired in the following commit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';

export interface PerJourneyConversionFunnelsSectionProps {
	current: ConversionWindow;
}

const PerJourneyConversionFunnelsSection = ( { current }: PerJourneyConversionFunnelsSectionProps ) => {
	const crossUpsellHidden = current.subscriber_to_donor_funnel.visibility === 'hidden';
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
			<div className="newspack-insights__viz-placeholder" data-cross-upsell-hidden={ crossUpsellHidden } />
		</section>
	);
};

export default PerJourneyConversionFunnelsSection;
