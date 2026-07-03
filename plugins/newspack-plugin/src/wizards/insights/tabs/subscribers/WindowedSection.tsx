/**
 * WindowedSection (NPPD-1616, empty states NPPD-1695).
 *
 * All Tab 6 metrics that ARE scoped to the date range picker:
 * new/churned subscriber counts, gross/net subscription revenue,
 * refund rate, and failed payment retry rate. Renders 6 MetricCards
 * with a dynamic section heading that mirrors the active preset
 * ("In the last 30 days", "This month", "From Sep 5 to Oct 5", etc.).
 *
 * Empty states (NPPD-1695), mirroring Tab 7 (Donors):
 *   - Whole-section `EmptyMetricSection` (`no_opportunity`) when the
 *     window saw NO subscription activity at all (`has_window_activity`
 *     false) — the whole grid would be zeros.
 *   - Per-card no-acquisition treatment on "New subscribers" when there
 *     are active subscribers but none new this window (period delta
 *     suppressed). A whole-section empty would hide the real churn /
 *     revenue cards, so this stays a single-card treatment.
 *   - `zeroFallback` count context on the currency cards when there were
 *     no subscription orders.
 *
 * Good zeros render normally, NOT as empty states: "Churned subscribers"
 * is a count (its 0 + green `lowerIsBetter` delta IS the good-zero
 * signal), and "Failed payment recovery" reframes its no-retries case as
 * the positive "No failed payments in this timeframe" (NPPD-1726) rather
 * than a missing-data note.
 *
 * The two rate metrics (refund rate, retry recovery) carry the
 * `{value, computable, denominator}` shape so the UI can render a
 * small-cohort 0% as "0% of N orders" with inline context, or swap to a
 * per-card note when the denominator is 0.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SubscribersRateValue, SubscribersWindow } from '../../api/subscribers';
import type { DateRange } from '../../state/useDateRange';
import EmptyMetricSection from '../components/EmptyMetricSection';
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import { formatNumber } from '../components/format';

export interface WindowedSectionProps {
	range: DateRange;
	current: SubscribersWindow;
	previous: SubscribersWindow | null;
	/**
	 * Window-independent count of active subscribers (from
	 * `snapshot.active_subscribers`). Supplies the `{N}` context for the
	 * "New subscribers" no-acquisition state — the publisher has existing
	 * subscribers, just none new in this timeframe.
	 */
	activeSubscribers: number;
	/**
	 * Newsletter → subscription conversion (NEWS-2603): a mature-cohort snapshot rate
	 * from the hub. Window-independent — it sits in this windowed section for grouping,
	 * but the value doesn't change with the date range.
	 */
	newsletterConversion: SubscribersRateValue;
}

const parseISO = ( s: string ): Date => {
	const [ y, m, d ] = s.split( '-' ).map( Number );
	return new Date( y, m - 1, d );
};

const formatShortDate = ( s: string ): string => new Intl.DateTimeFormat( undefined, { month: 'short', day: 'numeric' } ).format( parseISO( s ) );

const getHeading = ( range: DateRange ): string => {
	switch ( range.preset ) {
		case 'last-7':
			return __( 'In the last 7 days', 'newspack-plugin' );
		case 'last-30':
			return __( 'In the last 30 days', 'newspack-plugin' );
		case 'last-90':
			return __( 'In the last 90 days', 'newspack-plugin' );
		case 'this-month':
			return __( 'This month', 'newspack-plugin' );
		case 'last-month':
			return __( 'Last month', 'newspack-plugin' );
		case 'custom':
		default:
			return sprintf(
				/* translators: 1: start date formatted like "Sep 5", 2: end date formatted like "Oct 5" */
				__( 'From %1$s to %2$s', 'newspack-plugin' ),
				formatShortDate( range.start ),
				formatShortDate( range.end )
			);
	}
};

const ordersCohortSubtitle = ( denominator: number ): string =>
	sprintf(
		/* translators: %s: count of subscription orders in the comparison cohort */
		__( 'of %s', 'newspack-plugin' ),
		sprintf(
			/* translators: %s: count of subscription orders */
			_n( '%s order', '%s orders', denominator, 'newspack-plugin' ),
			formatNumber( denominator )
		)
	);

const retriesCohortSubtitle = ( denominator: number ): string =>
	sprintf(
		/* translators: %s: count of payment retry attempts in the comparison cohort */
		__( 'of %s', 'newspack-plugin' ),
		sprintf(
			/* translators: %s: count of payment retry attempts */
			_n( '%s retry', '%s retries', denominator, 'newspack-plugin' ),
			formatNumber( denominator )
		)
	);

const WindowedSection = ( { range, current, previous, activeSubscribers, newsletterConversion }: WindowedSectionProps ) => {
	// Whole-section empty: nothing happened this window. Collapse the grid to a
	// single callout (NPPD-1694 `EmptyMetricSection`). Checked first so the
	// per-card states below only fire inside a section that has real data.
	if ( ! current.has_window_activity ) {
		return (
			<EmptyMetricSection
				title={ getHeading( range ) }
				state="no_opportunity"
				body={ __(
					'No subscription activity in this timeframe. Your subscription product is configured, but no new subscribers, churn, or revenue changes happened in this timeframe. Worth expanding the date range or checking the conversion funnel.',
					'newspack-plugin'
				) }
			/>
		);
	}

	const refund: SubscribersRateValue = current.refund_rate;
	const retry: SubscribersRateValue = current.failed_payment_retry_rate;

	// Per-card no-acquisition state: the section has activity (revenue and/or
	// churn), but no first-time subscribers landed. A whole-section empty here
	// would hide the real churn / revenue cards, so this stays single-card.
	const noNewSubscribers = current.new_subscribers === 0 && activeSubscribers > 0;

	// No completed subscription orders this window → the currency cards show a
	// count note instead of "$0.00". Gated on GROSS (not net): a window with
	// orders that fully refunded has gross > 0 and a real net of 0, which should
	// render as $0.00, not "no orders".
	const noOrders = current.revenue_gross === 0;
	const subscriptionOrdersLabel = __( 'subscription orders', 'newspack-plugin' );

	// Rate-format good-zeros (NPPD-1698 D4 / D5): render the em-dash + a
	// positive/neutral secondary line via MetricCard's shared em-dash treatment,
	// not a bespoke note — so Refund rate and Failed payment recovery render
	// identically to the other em-dashed cards.
	//   · Refund rate: no orders → "No subscription orders…"; orders but zero
	//     refunds (a good zero) → "No refund requests…"; otherwise the real rate.
	//   · Failed payment recovery: no retries means no failed payments (a good
	//     zero) → "No failed payments…". A computable 0% (retries ran, none
	//     recovered) is a real bad zero and renders as 0%.
	let refundEmptyMessage: string | undefined;
	if ( ! refund.computable ) {
		refundEmptyMessage = __( 'No subscription orders in this timeframe.', 'newspack-plugin' );
	} else if ( refund.value === 0 ) {
		refundEmptyMessage = __( 'No refund requests in this timeframe.', 'newspack-plugin' );
	}
	const retryEmptyMessage = ! retry.computable ? __( 'No failed payments in this timeframe.', 'newspack-plugin' ) : undefined;
	const refundPrevious = previous?.refund_rate?.computable ? previous.refund_rate.value : null;
	const retryPrevious = previous?.failed_payment_retry_rate?.computable ? previous.failed_payment_retry_rate.value : null;
	// Snapshot rate: an em-dash (not a misleading 0%) until a mature cohort of
	// newsletter signups with a full 12 months of history exists.
	const newsletterConversionEmptyMessage = ! newsletterConversion.computable
		? __( 'Not enough newsletter signups with 12 months of history yet.', 'newspack-plugin' )
		: undefined;

	return (
		<section className="newspack-insights__section newspack-insights__section--windowed" aria-labelledby="newspack-insights-windowed-heading">
			<SectionHeading id="newspack-insights-windowed-heading" title={ getHeading( range ) } />
			<div className="newspack-insights__metric-grid">
				<MetricCard
					label={ __( 'New subscribers', 'newspack-plugin' ) }
					value={ current.new_subscribers }
					format="number"
					// Drop the period delta when there are no new subscribers: a "↓ 100%"
					// against a real prior count would misread an honest zero.
					previousValue={ noNewSubscribers ? undefined : previous?.new_subscribers }
					secondary={
						noNewSubscribers
							? sprintf(
									/* translators: %s: count of active subscribers (current state) */
									__( '%s active subscribers, but none new this timeframe', 'newspack-plugin' ),
									formatNumber( activeSubscribers )
							  )
							: undefined
					}
					description={ __( 'First-time subscribers', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Win-backs', 'newspack-plugin' ) }
					value={ current.winback_subscribers }
					format="number"
					previousValue={ previous?.winback_subscribers }
					description={ __( 'Previously churned subscribers who resubscribed', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Newsletter → subscription', 'newspack-plugin' ) }
					value={ newsletterConversion.value }
					format="percent"
					notComputableMessage={ newsletterConversionEmptyMessage }
					description={ __(
						'Of newsletter signups with a full 12 months of history, the share who became a paid subscriber within 12 months of signing up. A snapshot — not affected by the selected timeframe.',
						'newspack-plugin'
					) }
				/>
				<MetricCard
					label={ __( 'Churned subscribers', 'newspack-plugin' ) }
					value={ current.churned_subscribers }
					format="number"
					previousValue={ previous?.churned_subscribers }
					lowerIsBetter
					description={ __( 'Subscribers who churned in this timeframe', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Gross revenue', 'newspack-plugin' ) }
					value={ current.revenue_gross }
					format="currency"
					previousValue={ previous?.revenue_gross }
					zeroFallback={ noOrders ? { denominator: 0, currencyRole: 'total', attemptsLabel: subscriptionOrdersLabel } : undefined }
					description={ __( 'Subscription orders before refunds', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Net revenue', 'newspack-plugin' ) }
					value={ current.revenue_net }
					format="currency"
					previousValue={ previous?.revenue_net }
					zeroFallback={ noOrders ? { denominator: 0, currencyRole: 'total', attemptsLabel: subscriptionOrdersLabel } : undefined }
					description={ __( 'Gross minus refunds processed', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Refund rate', 'newspack-plugin' ) }
					value={ refund.computable ? refund.value : 0 }
					format="percent"
					previousValue={ refundEmptyMessage ? undefined : refundPrevious }
					lowerIsBetter
					secondary={ refundEmptyMessage ? undefined : ordersCohortSubtitle( refund.denominator ) }
					notComputableMessage={ refundEmptyMessage }
					description={ __( 'Refunds ÷ subscription orders', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Failed payment recovery', 'newspack-plugin' ) }
					value={ retry.computable ? retry.value : 0 }
					format="percent"
					previousValue={ retryEmptyMessage ? undefined : retryPrevious }
					secondary={ retryEmptyMessage ? undefined : retriesCohortSubtitle( retry.denominator ) }
					notComputableMessage={ retryEmptyMessage }
					description={ __( 'Recovered retries ÷ retry attempts', 'newspack-plugin' ) }
				/>
			</div>
		</section>
	);
};

export default WindowedSection;
