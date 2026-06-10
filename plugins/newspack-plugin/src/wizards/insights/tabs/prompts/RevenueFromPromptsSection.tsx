/**
 * RevenueFromPromptsSection (NPPD-1607, Section 5).
 *
 * Four scorecards summing Woo order totals from donations and
 * subscriptions completed after a prompt impression, in Direct and
 * Influenced (14-day lookback) attribution. Revenue is computed from
 * actual Woo orders, not prompt-event amounts.
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
import { scalarToMetricCardProps } from './scalarToCard';

export interface RevenueFromPromptsSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const RevenueFromPromptsSection = ( { current, previous }: RevenueFromPromptsSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--revenue" aria-labelledby="newspack-insights-prompts-revenue-heading">
		<h2 id="newspack-insights-prompts-revenue-heading" className="newspack-insights__section-heading">
			{ __( 'Revenue from prompts', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'Sum of Woo order totals from donations and subscriptions completed after a prompt impression. Direct totals revenue from same-session completions. Influenced totals revenue from later-session completions within 14 days of seeing a prompt.',
				'newspack-plugin'
			) }
		</p>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Donation Revenue (Direct)', 'newspack-plugin' ),
					description: __(
						'Sum of Woo donation order totals from same-session completions after a donation-intent prompt impression',
						'newspack-plugin'
					),
					current: current.donation_revenue_direct,
					previous: previous?.donation_revenue_direct,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Donation Revenue (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Sum of Woo donation order totals from later-session completions within 14 days of seeing a donation-intent prompt',
						'newspack-plugin'
					),
					current: current.donation_revenue_influenced_14d,
					previous: previous?.donation_revenue_influenced_14d,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Subscription Revenue (Direct)', 'newspack-plugin' ),
					description: __(
						'Sum of Woo subscription order totals from same-session completions after a subscription-intent prompt impression',
						'newspack-plugin'
					),
					current: current.subscription_revenue_direct,
					previous: previous?.subscription_revenue_direct,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Subscription Revenue (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Sum of Woo subscription order totals from later-session completions within 14 days of seeing a subscription-intent prompt',
						'newspack-plugin'
					),
					current: current.subscription_revenue_influenced_14d,
					previous: previous?.subscription_revenue_influenced_14d,
				} ) }
			/>
		</div>
	</section>
);

export default RevenueFromPromptsSection;
