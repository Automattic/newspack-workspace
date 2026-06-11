/**
 * PaidReaderConversionSection (NPPD-1604, Section 3).
 *
 * Four scorecards in a single row covering paywall-gate conversion
 * (Direct attribution, Influenced 14-day lookback) plus revenue
 * from same-session paywall conversions.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { GatesWindow } from '../../api/gates';
import MetricCard from '../components/MetricCard';
import { scalarToMetricCardProps } from './scalarToCard';

export interface PaidReaderConversionSectionProps {
	current: GatesWindow;
	previous: GatesWindow | null;
}

const PaidReaderConversionSection = ( { current, previous }: PaidReaderConversionSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--paid-reader" aria-labelledby="newspack-insights-gates-paid-heading">
		<h2 id="newspack-insights-gates-paid-heading" className="newspack-insights__section-heading">
			{ __( 'Paid reader conversion', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'How effectively paywall gates convert visitors into paying subscribers. Direct counts subscriptions that happened in the same session as a paywall impression. Influenced counts subscriptions that happened in a later session within 14 days of a paywall impression. Revenue is computed from actual Woo orders, not gate-event amounts.',
				'newspack-plugin'
			) }
		</p>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Paywall Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Sessions with a subscription after a paywall impression ÷ sessions with a paywall impression',
						'newspack-plugin'
					),
					current: current.paywall_conversion_direct,
					previous: previous?.paywall_conversion_direct,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Paywall Conversion (Influenced, 14d)', 'newspack-plugin' ),
					description: __(
						'Readers who subscribed in a later session within 14 days of seeing a paywall ÷ readers who saw a paywall',
						'newspack-plugin'
					),
					current: current.paywall_conversion_influenced_14d,
					previous: previous?.paywall_conversion_influenced_14d,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Total Paywall Revenue (Direct)', 'newspack-plugin' ),
					description: __(
						'Sum of Woo order totals from subscriptions completed in the same session as a paywall impression',
						'newspack-plugin'
					),
					current: current.total_paywall_revenue_direct,
					previous: previous?.total_paywall_revenue_direct,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Avg Revenue per Paywall Conversion', 'newspack-plugin' ),
					description: __( 'Total paywall revenue ÷ paywall conversions', 'newspack-plugin' ),
					current: current.avg_revenue_per_paywall_conversion,
					previous: previous?.avg_revenue_per_paywall_conversion,
				} ) }
			/>
		</div>
	</section>
);

export default PaidReaderConversionSection;
