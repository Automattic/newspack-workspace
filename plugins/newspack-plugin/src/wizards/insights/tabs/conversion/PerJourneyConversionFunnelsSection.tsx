/**
 * PerJourneyConversionFunnelsSection (NPPD-1609, Section 2; empty states NPPD-1742, NPPD-1743).
 *
 * Funnels in a grid: anonymous → registered (2.1), registered → subscriber
 * (2.2), registered → donor (2.3), and the visibility-gated subscriber → donor
 * cross-upsell (2.4).
 *
 * Configuration matrix (NPPD-1742): the subscription (2.2) and donation (2.3)
 * legs are reader-revenue endpoints that only render when that stream is
 * configured. When the server reports `visibility: 'hidden'`
 * (`visibility_reason: 'not_configured'`) the leg's whole cell is omitted — a
 * registrations-only publisher sees just the registration leg, never zero
 * funnels. Within a rendered leg, the funnel-shaped empty states apply:
 *   - zero entries (nobody entered the funnel) → swap the funnel for the
 *     in-cell `no_opportunity` note (nothing to draw);
 *   - entries but zero conversions → keep the funnel (the populated entry bar
 *     collapsing to zero is the signal) and annotate with the `no_conversions`
 *     note, `{N}` = the prior-stage base;
 *   - otherwise → the normal funnel.
 *
 * The registration leg (2.1) gets the same funnel-shaped empty states (NPPD-1743),
 * driven by its own funnel stages (anonymous → saw a conversion surface →
 * registered); it is always visible (no config gate). Section 2.4 keeps its own
 * cohort-size visibility gate, unchanged.
 *
 * Body copy here is provisional, mirroring the sibling shape; final wording is
 * owned by the voice-and-tone audit (NPPD-1698).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionGatedFunnelData, ConversionWindow } from '../../api/conversion';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import SectionEmpty from '../components/SectionEmpty';
import { formatNumber } from '../components/format';
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

interface ConversionLegCellProps {
	data: ConversionGatedFunnelData;
	/** Shown for an unexpected error/empty render path. */
	emptyMessage: string;
	/** Shown (in-cell) when the leg is configured but nobody entered the funnel. */
	noOpportunityMessage: string;
	/** Annotation when entries exist but none converted; may contain `{N}`. */
	noConversionsBody: string;
}

/** Substitute `{N}` with a formatted count; render verbatim otherwise. */
const interpolateCount = ( body: string, count: number ): string =>
	body.includes( '{N}' ) ? body.split( '{N}' ).join( formatNumber( count ) ) : body;

/**
 * Section 2.2 / 2.3 conversion leg (NPPD-1742). Assumes the leg is configured —
 * the parent omits the cell entirely when `visibility === 'hidden'`. Applies the
 * funnel-shaped empty states; otherwise renders the funnel (with the shared
 * state treatment for error).
 */
const ConversionLegCell = ( { data, emptyMessage, noOpportunityMessage, noConversionsBody }: ConversionLegCellProps ) => {
	// Defensive: the parent gates on visibility, but never render a hidden leg.
	if ( data.visibility === 'hidden' ) {
		return null;
	}
	if ( data.state === 'populated' && data.stages.length > 0 ) {
		const entry = data.stages[ 0 ].count;
		const conversion = data.stages[ data.stages.length - 1 ].count;
		// Prior-stage base — the population that had the opportunity to convert.
		const priorStage = data.stages.length >= 2 ? data.stages[ data.stages.length - 2 ].count : entry;
		// Nobody entered → nothing to draw: no_opportunity in place of the funnel.
		if ( entry === 0 ) {
			return <SectionEmpty>{ noOpportunityMessage }</SectionEmpty>;
		}
		// Entered, the prior stage HAS a base, but none converted out of it → keep
		// the funnel (the drop-off is the signal) and annotate. {N} = prior-stage
		// base. The `priorStage > 0` guard matters: a leg where readers registered
		// but nobody reached the prior stage (e.g. no prompt shown) has no
		// "opportunity" base, so the no_conversions copy ("{N} saw a prompt, but
		// none converted") would be a self-contradicting "0 saw a prompt". That
		// case falls through to the normal funnel, which honestly shows the
		// drop-off at the prior stage.
		if ( conversion === 0 && priorStage > 0 ) {
			return (
				<>
					<Funnel stages={ data.stages } />
					<p className="newspack-insights__conversion-gated-note">{ interpolateCount( noConversionsBody, priorStage ) }</p>
				</>
			);
		}
		return <Funnel stages={ data.stages } />;
	}
	// A successful query with no rows is a configured-but-quiet leg: no_opportunity.
	if ( data.state === 'empty' ) {
		return <SectionEmpty>{ noOpportunityMessage }</SectionEmpty>;
	}
	// error (and any other state) → shared section-state treatment.
	return (
		<SectionState state={ data.state } emptyMessage={ emptyMessage }>
			<Funnel stages={ data.stages } />
		</SectionState>
	);
};

const PerJourneyConversionFunnelsSection = ( { current }: PerJourneyConversionFunnelsSectionProps ) => {
	const subscriptionVisible = current.registered_to_subscriber_funnel.visibility !== 'hidden';
	const donationVisible = current.registered_to_donor_funnel.visibility !== 'hidden';

	return (
		<Section
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
					<ConversionLegCell
						data={ current.anonymous_to_registered_funnel }
						emptyMessage={ __(
							'No registration funnel data yet. This will populate once registrations occur in this timeframe.',
							'newspack-plugin'
						) }
						noOpportunityMessage={ __(
							'No readers entered the registration journey in this timeframe. Try a wider date range.',
							'newspack-plugin'
						) }
						noConversionsBody={ __(
							'No new registrations in this timeframe. {N} readers saw a registration prompt or gate, but none registered — the funnel below shows where they dropped off.',
							'newspack-plugin'
						) }
					/>
				</JourneyFunnel>
				{ subscriptionVisible && (
					<JourneyFunnel
						title={ __( 'Registered → Subscriber', 'newspack-plugin' ) }
						caption={ __(
							'The paid-upsell path. Subscription excludes donation products; donor conversions are in the next funnel.',
							'newspack-plugin'
						) }
					>
						<ConversionLegCell
							data={ current.registered_to_subscriber_funnel }
							emptyMessage={ __(
								'No subscription funnel data yet. This will populate once subscriptions occur in this timeframe.',
								'newspack-plugin'
							) }
							noOpportunityMessage={ __(
								'No registered readers entered the subscription journey in this timeframe. Try a wider date range.',
								'newspack-plugin'
							) }
							noConversionsBody={ __(
								'No new subscribers in this timeframe. {N} registered readers saw a subscription prompt, but none subscribed — the funnel below shows where they dropped off.',
								'newspack-plugin'
							) }
						/>
					</JourneyFunnel>
				) }
				{ donationVisible && (
					<JourneyFunnel
						title={ __( 'Registered → Donor', 'newspack-plugin' ) }
						caption={ __( 'The donation-conversion path.', 'newspack-plugin' ) }
					>
						<ConversionLegCell
							data={ current.registered_to_donor_funnel }
							emptyMessage={ __(
								'No donor funnel data yet. This will populate once donations occur in this timeframe.',
								'newspack-plugin'
							) }
							noOpportunityMessage={ __(
								'No registered readers entered the donation journey in this timeframe. Try a wider date range.',
								'newspack-plugin'
							) }
							noConversionsBody={ __(
								'No new donors in this timeframe. {N} registered readers saw a donation prompt, but none donated — the funnel below shows where they dropped off.',
								'newspack-plugin'
							) }
						/>
					</JourneyFunnel>
				) }
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
		</Section>
	);
};

export default PerJourneyConversionFunnelsSection;
