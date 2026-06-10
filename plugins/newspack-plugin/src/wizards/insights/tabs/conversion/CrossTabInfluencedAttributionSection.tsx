/**
 * CrossTabInfluencedAttributionSection (NPPD-1609, Section 7).
 *
 * Four influenced-rate scorecards centralized from the Gates and Prompts
 * tabs. This is the only Conversion Journey section with comparison
 * deltas, so it threads the `previous` window into each MetricCard.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';
import MetricCard from '../components/MetricCard';
import { scalarToMetricCardProps } from './scalarToCard';

export interface CrossTabInfluencedAttributionSectionProps {
	current: ConversionWindow;
	previous: ConversionWindow | null;
}

const CrossTabInfluencedAttributionSection = ( { current, previous }: CrossTabInfluencedAttributionSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--influenced-attribution"
		aria-labelledby="newspack-insights-conversion-influenced-heading"
	>
		<h2 id="newspack-insights-conversion-influenced-heading" className="newspack-insights__section-heading">
			{ __( 'Cross-tab influenced attribution', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'Influenced conversion rates from your Gates and Prompts tabs, centralized so you don’t have to bounce between tabs to compare. Influenced means the reader saw a gate or prompt in the lookback window before converting in a later session.',
				'newspack-plugin'
			) }
		</p>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Influenced Registration Rate', 'newspack-plugin' ),
					description: __( '% of new registrations whose user saw a gate or prompt in the 7 days prior', 'newspack-plugin' ),
					current: current.influenced_registration_rate_7d,
					previous: previous?.influenced_registration_rate_7d,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Influenced Subscription Rate', 'newspack-plugin' ),
					description: __( '% of new subscribers whose user saw a subscription-intent surface in the 14 days prior', 'newspack-plugin' ),
					current: current.influenced_subscription_rate_14d,
					previous: previous?.influenced_subscription_rate_14d,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Influenced Donation Rate', 'newspack-plugin' ),
					description: __( '% of new donors whose user saw a donation-intent surface in the 14 days prior', 'newspack-plugin' ),
					current: current.influenced_donation_rate_14d,
					previous: previous?.influenced_donation_rate_14d,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Influenced Newsletter Signup Rate', 'newspack-plugin' ),
					description: __(
						'% of new newsletter signups whose user saw a newsletter-intent surface in the 7 days prior',
						'newspack-plugin'
					),
					current: current.influenced_newsletter_rate_7d,
					previous: previous?.influenced_newsletter_rate_7d,
				} ) }
			/>
		</div>
	</section>
);

export default CrossTabInfluencedAttributionSection;
