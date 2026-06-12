/**
 * WindowedSection (NPPD-1616).
 *
 * All Tab 6 metrics that ARE scoped to the date range picker:
 * new/churned subscriber counts, gross/net subscription revenue,
 * refund rate, and failed payment retry rate. Renders 6 MetricCards
 * with a dynamic section heading that mirrors the active preset
 * ("In the last 30 days", "This month", "From Sep 5 to Oct 5", etc.).
 *
 * The two rate metrics (refund rate, retry recovery) carry the
 * `{value, computable, denominator}` shape so the UI can:
 *   - render a small-cohort 0% as "0% of N orders" with inline context
 *   - swap to a per-card empty state when the denominator is 0
 *     ("No subscription orders in this timeframe." for refund rate)
 * Same pattern as Tab 7's RetentionSection.
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
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import { formatNumber } from '../components/format';

export interface WindowedSectionProps {
	range: DateRange;
	current: SubscribersWindow;
	previous: SubscribersWindow | null;
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

const WindowedSection = ( { range, current, previous }: WindowedSectionProps ) => {
	const refund: SubscribersRateValue = current.refund_rate;
	const retry: SubscribersRateValue = current.failed_payment_retry_rate;

	return (
		<section className="newspack-insights__section newspack-insights__section--windowed" aria-labelledby="newspack-insights-windowed-heading">
			<SectionHeading id="newspack-insights-windowed-heading" title={ getHeading( range ) } />
			<div className="newspack-insights__metric-grid">
				<MetricCard
					label={ __( 'New subscribers', 'newspack-plugin' ) }
					value={ current.new_subscribers }
					format="number"
					previousValue={ previous?.new_subscribers }
					description={ __( 'First-time subscribers', 'newspack-plugin' ) }
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
					description={ __( 'Subscription orders before refunds', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Net revenue', 'newspack-plugin' ) }
					value={ current.revenue_net }
					format="currency"
					previousValue={ previous?.revenue_net }
					description={ __( 'Gross minus refunds processed', 'newspack-plugin' ) }
				/>
				{ refund.computable ? (
					<MetricCard
						label={ __( 'Refund rate', 'newspack-plugin' ) }
						value={ refund.value }
						format="percent"
						previousValue={ previous?.refund_rate?.computable ? previous.refund_rate.value : null }
						lowerIsBetter
						secondary={ ordersCohortSubtitle( refund.denominator ) }
						description={ __( 'Refunds ÷ subscription orders', 'newspack-plugin' ) }
					/>
				) : (
					<div className="newspack-insights__metric-card newspack-insights__metric-card--empty">
						<div className="newspack-insights__metric-card-label">{ __( 'Refund rate', 'newspack-plugin' ) }</div>
						<p className="newspack-insights__metric-card-empty-note">
							{ __( 'No subscription orders in this timeframe.', 'newspack-plugin' ) }
						</p>
					</div>
				) }
				{ retry.computable ? (
					<MetricCard
						label={ __( 'Failed payment recovery', 'newspack-plugin' ) }
						value={ retry.value }
						format="percent"
						previousValue={ previous?.failed_payment_retry_rate?.computable ? previous.failed_payment_retry_rate.value : null }
						secondary={ retriesCohortSubtitle( retry.denominator ) }
						description={ __( 'Recovered retries ÷ retry attempts', 'newspack-plugin' ) }
					/>
				) : (
					<div className="newspack-insights__metric-card newspack-insights__metric-card--empty">
						<div className="newspack-insights__metric-card-label">{ __( 'Failed payment recovery', 'newspack-plugin' ) }</div>
						<p className="newspack-insights__metric-card-empty-note">
							{ __( 'No payment retries in this timeframe.', 'newspack-plugin' ) }
						</p>
					</div>
				) }
			</div>
		</section>
	);
};

export default WindowedSection;
