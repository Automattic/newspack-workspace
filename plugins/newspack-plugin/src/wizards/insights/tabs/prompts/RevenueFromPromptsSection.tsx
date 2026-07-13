/**
 * RevenueFromPromptsSection (NPPD-1607, Section 5).
 *
 * Four scorecards for donation and subscription revenue completed after a
 * prompt impression, in Direct and Influenced (14-day lookback) attribution.
 * Direct revenue sums actual Woo order totals (order meta). Influenced revenue
 * is hub-computed (BQ-internal) from the checkout `amount` of the influenced
 * conversion events — no Woo join on the consumer.
 *
 * "Direct" here means the order carried `_newspack_popup_id` — i.e. the reader
 * converted through the block the prompt itself rendered. It is not session-
 * scoped. See PaidReaderConversionSection's docblock.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../packages/components/src';
import type { PromptsWindow } from '../../api/prompts';
import MetricCard from '../components/MetricCard';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import { NOT_CAPABLE_COPY } from './notCapableCopy';
import { NOT_COMPUTABLE_COPY } from './notComputableCopy';
import { scalarToMetricCardProps } from './scalarToCard';

export interface RevenueFromPromptsSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const RevenueFromPromptsSection = ( { current, previous }: RevenueFromPromptsSectionProps ) => (
	<Section className="newspack-insights__section newspack-insights__section--revenue" aria-labelledby="newspack-insights-prompts-revenue-heading">
		<SectionHeading
			id="newspack-insights-prompts-revenue-heading"
			title={ __( 'Revenue from prompts', 'newspack-plugin' ) }
			description={ __(
				'Donation and subscription revenue completed after a prompt impression. Direct totals Woo order revenue from conversions completed through a prompt’s own block. Influenced totals checkout revenue from later-session completions within 14 days of seeing a prompt.',
				'newspack-plugin'
			) }
		/>
		<Grid columns={ 4 } gutter={ 16 } noMargin>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Donation Revenue (Direct)', 'newspack-plugin' ),
					description: __(
						'Sum of Woo donation order totals from donations completed through a prompt’s donation block',
						'newspack-plugin'
					),
					current: current.donation_revenue_direct,
					previous: previous?.donation_revenue_direct,
					notCapableMessage: NOT_CAPABLE_COPY.donation,
					notComputableMessage: NOT_COMPUTABLE_COPY.donation,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Donation Revenue (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Checkout revenue from donation completions in a later session within 14 days of seeing a donation-intent prompt',
						'newspack-plugin'
					),
					current: current.donation_revenue_influenced_14d,
					previous: previous?.donation_revenue_influenced_14d,
					notCapableMessage: NOT_CAPABLE_COPY.donation,
					notComputableMessage: NOT_COMPUTABLE_COPY.donation,
				} ) }
			/>
			{ /* Block-name vs intent-name asymmetry: NOT_CAPABLE keys off the block that
			     grants capability (checkout); NOT_COMPUTABLE keys off the metric's intent
			     (subscription). Same pattern in PaidReaderConversionSection. */ }
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Subscription Revenue (Direct)', 'newspack-plugin' ),
					description: __(
						'Sum of Woo subscription order totals from subscriptions purchased through a prompt’s checkout button',
						'newspack-plugin'
					),
					current: current.subscription_revenue_direct,
					previous: previous?.subscription_revenue_direct,
					notCapableMessage: NOT_CAPABLE_COPY.checkout,
					notComputableMessage: NOT_COMPUTABLE_COPY.subscription,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Subscription Revenue (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Checkout revenue from subscription completions in a later session within 14 days of seeing a subscription-intent prompt',
						'newspack-plugin'
					),
					current: current.subscription_revenue_influenced_14d,
					previous: previous?.subscription_revenue_influenced_14d,
					notCapableMessage: NOT_CAPABLE_COPY.checkout,
					notComputableMessage: NOT_COMPUTABLE_COPY.subscription,
				} ) }
			/>
		</Grid>
	</Section>
);

export default RevenueFromPromptsSection;
