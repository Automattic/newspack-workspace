/**
 * PaidReaderConversionSection (NPPD-1607, Section 4).
 *
 * Four scorecards covering paid-conversion intents — donation and
 * subscription — each in Direct and Influenced (14-day lookback)
 * attribution. Completion (not just attempt) is established via the
 * Woo join in Phase 2.
 *
 * Direct here is NOT session-scoped. Its numerator is Woo orders carrying
 * `_newspack_popup_id`, stamped by the hidden field the prompt injects into its
 * own donation block / checkout button. A reader who clicks a prompt's link out
 * to a landing page and converts there is not counted. Copy must describe that
 * mechanism, not a session.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsWindow } from '../../api/prompts';
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import { NOT_CAPABLE_COPY } from './notCapableCopy';
import { NOT_COMPUTABLE_COPY } from './notComputableCopy';
import { scalarToMetricCardProps } from './scalarToCard';

export interface PaidReaderConversionSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const PaidReaderConversionSection = ( { current, previous }: PaidReaderConversionSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--paid-reader" aria-labelledby="newspack-insights-prompts-paid-heading">
		<SectionHeading
			id="newspack-insights-prompts-paid-heading"
			title={ __( 'Paid reader conversion', 'newspack-plugin' ) }
			description={ __(
				'How effectively prompts convert readers into donors and subscribers. Direct counts conversions completed through a prompt’s own donation block or checkout button. Influenced counts conversions in a later session within 14 days of seeing a prompt.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Donation Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Donations completed through a prompt’s donation block ÷ impressions of prompts with a donation block',
						'newspack-plugin'
					),
					current: current.donation_conversion_direct,
					previous: previous?.donation_conversion_direct,
					notCapableMessage: NOT_CAPABLE_COPY.donation,
					notComputableMessage: NOT_COMPUTABLE_COPY.donation,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Donation Conversion (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Donors whose conversion followed a donation-prompt exposure in a prior session within 14 days ÷ all new donors',
						'newspack-plugin'
					),
					current: current.donation_conversion_influenced_14d,
					previous: previous?.donation_conversion_influenced_14d,
					notCapableMessage: NOT_CAPABLE_COPY.donation,
					notComputableMessage: NOT_COMPUTABLE_COPY.donationInfluenced,
				} ) }
			/>
			{ /* Block-name vs intent-name asymmetry: NOT_CAPABLE keys off the block that
			     grants capability (checkout); NOT_COMPUTABLE keys off the metric's intent
			     (subscription). Same pattern in RevenueFromPromptsSection. */ }
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Subscription Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Subscriptions purchased through a prompt’s checkout button ÷ impressions of prompts with a checkout button',
						'newspack-plugin'
					),
					current: current.subscription_conversion_direct,
					previous: previous?.subscription_conversion_direct,
					notCapableMessage: NOT_CAPABLE_COPY.checkout,
					notComputableMessage: NOT_COMPUTABLE_COPY.subscription,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Subscription Conversion (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Subscribers whose conversion followed a subscription-prompt exposure in a prior session within 14 days ÷ all new subscribers',
						'newspack-plugin'
					),
					current: current.subscription_conversion_influenced_14d,
					previous: previous?.subscription_conversion_influenced_14d,
					notCapableMessage: NOT_CAPABLE_COPY.checkout,
					notComputableMessage: NOT_COMPUTABLE_COPY.subscriptionInfluenced,
				} ) }
			/>
		</div>
	</section>
);

export default PaidReaderConversionSection;
