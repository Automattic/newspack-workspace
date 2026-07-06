/**
 * PaidReaderConversionSection (NPPD-1604, Section 3; empty states NPPD-1694).
 *
 * Four scorecards in a single row covering paywall-gate conversion
 * (Direct attribution, Influenced 14-day lookback) plus revenue
 * from same-session paid access gate conversions.
 *
 * When the section would render as a row of zeros it swaps the grid for a
 * single `<EmptyMetricSection>` (detection stays here, not in the orchestrator):
 *   - no paid access gate impressions in the window → `no_opportunity`
 *   - impressions but no conversions       → `no_conversions` (with the impression count)
 *   - otherwise the four scorecards, each carrying its count fallback so an
 *     individual zero card reads as "0 of N" / "0 conversions" rather than 0%/$0.
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
import SectionHeading from '../components/SectionHeading';
import EmptyMetricSection from '../components/EmptyMetricSection';
import { scalarToMetricCardProps } from './scalarToCard';

export interface PaidReaderConversionSectionProps {
	current: GatesWindow;
	previous: GatesWindow | null;
}

const HEADING_ID = 'newspack-insights-gates-paid-heading';

const PaidReaderConversionSection = ( { current, previous }: PaidReaderConversionSectionProps ) => {
	const title = __( 'Paid reader conversion', 'newspack-plugin' );
	const caption = __(
		'How effectively paid access gate gates convert visitors into paying subscribers. Direct counts subscriptions that happened in the same session as a paid access gate impression. Influenced counts subscriptions that happened in a later session within 14 days of a paid access gate impression. Revenue is computed from actual Woo orders, not gate-event amounts.',
		'newspack-plugin'
	);
	const impressionsLabel = __( 'paid access gate impressions', 'newspack-plugin' );
	// The Influenced rate is converter-denominated (NPPD-1764): its denominator is all
	// new subscribers in the window, not paid access gate impressions.
	const subscribersLabel = __( 'subscribers', 'newspack-plugin' );
	const conversionsLabel = __( 'conversions', 'newspack-plugin' );

	const impressions = current.paywall_impressions_total;
	const conversions = current.paywall_conversions_total;

	// The section totals are derived from the Direct denominator and the Direct/
	// Influenced numerators, all of which are null when their query errors —
	// coercing the totals to 0. A zero total is only a *genuine* empty state when
	// both source metrics actually computed; if either errored we fall through to
	// the scorecards so each card surfaces its own error treatment rather than a
	// misleading "no paid access gate impressions" / "no conversions" empty state. (Direct and
	// Influenced are separate queries and can fail independently.)
	const dataKnown = current.paywall_conversion_direct.state !== 'error' && current.paywall_conversion_influenced_14d.state !== 'error';

	// Empty states (NPPD-1694). Order matters: no opportunity before no conversions.
	if ( dataKnown && impressions === 0 ) {
		return (
			<EmptyMetricSection
				title={ title }
				caption={ caption }
				state="no_opportunity"
				body={ __(
					'No paid access gate impressions in this timeframe. Your paid access gate gates may not be reaching readers — could be a placement question, a frequency question, or simply that the timeframe doesn’t include enough traffic. See the per-gate breakdown below for configuration details.',
					'newspack-plugin'
				) }
			/>
		);
	}
	if ( dataKnown && conversions === 0 ) {
		return (
			<EmptyMetricSection
				title={ title }
				caption={ caption }
				state="no_conversions"
				signalCount={ impressions }
				body={ __(
					'No paid access gate conversions in this timeframe. Your paid access gate was shown {N} times, but none led to a paid subscription within the 14-day attribution window. Worth a look at your checkout flow or pricing. See the per-gate breakdown below.',
					'newspack-plugin'
				) }
			/>
		);
	}

	return (
		<section className="newspack-insights__section newspack-insights__section--paid-reader" aria-labelledby={ HEADING_ID }>
			<SectionHeading id={ HEADING_ID } title={ title } description={ caption } />
			<div className="newspack-insights__metric-grid">
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Paid access gate Conversion (Direct)', 'newspack-plugin' ),
						description: __(
							'Sessions with a subscription after a paid access gate impression ÷ sessions with a paid access gate impression',
							'newspack-plugin'
						),
						current: current.paywall_conversion_direct,
						previous: previous?.paywall_conversion_direct,
						zeroFallback: {
							numerator: current.paywall_conversion_direct.numerator ?? undefined,
							denominator: current.paywall_conversion_direct.denominator ?? undefined,
							attemptsLabel: impressionsLabel,
						},
					} ) }
				/>
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Paid access gate Conversion (Influenced, 14d)', 'newspack-plugin' ),
						description: __(
							'Subscribers whose conversion followed a paid access gate exposure in a prior session within 14 days ÷ all new subscribers',
							'newspack-plugin'
						),
						current: current.paywall_conversion_influenced_14d,
						previous: previous?.paywall_conversion_influenced_14d,
						zeroFallback: {
							numerator: current.paywall_conversion_influenced_14d.numerator ?? undefined,
							denominator: current.paywall_conversion_influenced_14d.denominator ?? undefined,
							attemptsLabel: subscribersLabel,
						},
					} ) }
				/>
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Total Paid access gate Revenue (Direct)', 'newspack-plugin' ),
						description: __(
							'Sum of Woo order totals from subscriptions completed in the same session as a paid access gate impression',
							'newspack-plugin'
						),
						current: current.total_paywall_revenue_direct,
						previous: previous?.total_paywall_revenue_direct,
						// Currency total: conversions ride on the scalar's `denominator`;
						// impressions come from the section total — but only when the Direct
						// scalar computed. Otherwise `impressions` is an unreliable 0, so pass
						// undefined and let the card render its own value/error treatment
						// instead of a misleading "No paid access gate impressions".
						zeroFallback: {
							numerator: current.total_paywall_revenue_direct.denominator ?? undefined,
							denominator: dataKnown ? impressions : undefined,
							currencyRole: 'total',
							attemptsLabel: impressionsLabel,
							conversionsLabel,
						},
					} ) }
				/>
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Avg Revenue per Paid access gate Conversion', 'newspack-plugin' ),
						description: __( 'Total paid access gate revenue ÷ paid access gate conversions', 'newspack-plugin' ),
						current: current.avg_revenue_per_paywall_conversion,
						previous: previous?.avg_revenue_per_paywall_conversion,
						zeroFallback: {
							numerator: current.avg_revenue_per_paywall_conversion.denominator ?? undefined,
							denominator: dataKnown ? impressions : undefined,
							currencyRole: 'average',
							attemptsLabel: impressionsLabel,
							conversionsLabel,
						},
					} ) }
				/>
			</div>
		</section>
	);
};

export default PaidReaderConversionSection;
