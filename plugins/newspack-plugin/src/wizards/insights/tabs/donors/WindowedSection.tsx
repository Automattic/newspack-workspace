/**
 * WindowedSection (NPPD-1617, empty states NPPD-1696).
 *
 * Tab 7 metrics scoped to the date range picker: new/lapsed donor
 * counts, total donation revenue (with an inline one-time + recurring
 * breakdown as a secondary line), and the average gift. Heading is
 * dynamic ("In the last 30 days", "This month", etc.) — same pattern
 * as Tab 6's WindowedSection.
 *
 * Empty states (NPPD-1696) apply the two NPPD-1694 primitives at the
 * altitude each one is for:
 *   - Whole-section `EmptyMetricSection` (`no_opportunity`) when the
 *     window saw NO donation activity at all (`has_window_activity`
 *     false). The whole grid would be zeros, so the section collapses
 *     to a single explanatory callout.
 *   - Per-card treatment for legitimate zeros INSIDE an otherwise-
 *     populated section, where a whole-section empty would destroy real
 *     data (this section mixes acquisition + revenue + lapses):
 *       · "New donors" === 0 while donors are active → a `no_conversions`
 *         secondary (existing-donor count as context) with the misleading
 *         period delta suppressed.
 *       · currency cards at a real zero → `zeroFallback` count context.
 *       · "Total donation revenue" → its one-time/recurring breakdown
 *         line drops the empty mode rather than printing "$0 recurring".
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonorsWindow } from '../../api/donors';
import type { DateRange } from '../../state/useDateRange';
import EmptyMetricSection from '../components/EmptyMetricSection';
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import { formatCurrency, formatNumber } from '../components/format';

export interface WindowedSectionProps {
	range: DateRange;
	current: DonorsWindow;
	previous: DonorsWindow | null;
	/**
	 * Window-independent count of active donors (trailing 12 months,
	 * from `snapshot.active_donors`). Supplies the `{N}` context for the
	 * "New donors" no-acquisition state — the publisher has existing
	 * donors, just none new in this timeframe.
	 */
	activeDonors: number;
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

/**
 * One-time/recurring breakdown line for the Total donation revenue card.
 * Drops the empty mode (NPPD-1696 mode suppression) so a recurring-only
 * window reads "$X recurring" rather than "$0 one-time + $X recurring".
 * Returns undefined when neither mode has revenue — the card's
 * `zeroFallback` owns that state instead.
 */
const revenueBreakdown = ( current: DonorsWindow ): string | undefined => {
	const hasOneTime = current.one_time_revenue > 0;
	const hasRecurring = current.recurring_revenue > 0;
	const oneTime = formatCurrency( current.one_time_revenue ).display;
	const recurring = formatCurrency( current.recurring_revenue ).display;
	if ( hasOneTime && hasRecurring ) {
		return sprintf(
			/* translators: 1: one-time gift revenue formatted as currency, 2: recurring renewal revenue formatted as currency */
			__( '%1$s one-time + %2$s recurring', 'newspack-plugin' ),
			oneTime,
			recurring
		);
	}
	if ( hasOneTime ) {
		/* translators: %s: one-time gift revenue formatted as currency */
		return sprintf( __( '%s one-time', 'newspack-plugin' ), oneTime );
	}
	if ( hasRecurring ) {
		/* translators: %s: recurring renewal revenue formatted as currency */
		return sprintf( __( '%s recurring', 'newspack-plugin' ), recurring );
	}
	return undefined;
};

const WindowedSection = ( { range, current, previous, activeDonors }: WindowedSectionProps ) => {
	// Whole-section empty: nothing happened this window. Collapse the grid to a
	// single callout (NPPD-1694 `EmptyMetricSection`). Checked first so the
	// per-card states below only fire inside a section that has real data —
	// mirroring Gates' "no_opportunity before everything" ordering.
	// Edge: `has_window_activity` keys off net revenue, so a window whose only
	// donation was later fully refunded (net 0, no new/lapsed donors) reads as
	// "no donations" here. Accepted — this is display-only and gates nothing.
	if ( ! current.has_window_activity ) {
		return (
			<EmptyMetricSection
				title={ getHeading( range ) }
				state="no_opportunity"
				body={ __(
					'No donations in this timeframe. Your donation flow is configured, but no readers contributed in this timeframe. Worth expanding the date range or checking the donation flow placement on the site.',
					'newspack-plugin'
				) }
			/>
		);
	}

	// Per-card no-acquisition state: the section has activity (revenue and/or a
	// lapse), but no FIRST-TIME donors landed. A whole-section empty here would
	// hide the real revenue cards, so this stays a single-card treatment.
	const noNewDonors = current.new_donors === 0 && activeDonors > 0;

	const donationsLabel = __( 'donations', 'newspack-plugin' );
	const oneTimeGiftsLabel = __( 'one-time gifts', 'newspack-plugin' );

	return (
		<section
			className="newspack-insights__section newspack-insights__section--windowed"
			aria-labelledby="newspack-insights-donors-windowed-heading"
		>
			<SectionHeading id="newspack-insights-donors-windowed-heading" title={ getHeading( range ) } />
			<div className="newspack-insights__metric-grid">
				<MetricCard
					label={ __( 'New donors', 'newspack-plugin' ) }
					value={ current.new_donors }
					format="number"
					// Drop the period delta when there are no new donors: a "↓ 100%" against
					// a real prior count would misread an honest zero (same rationale as
					// MetricCard's own zeroFallback delta suppression).
					previousValue={ noNewDonors ? undefined : previous?.new_donors }
					secondary={
						noNewDonors
							? sprintf(
									/* translators: %s: count of active donors (trailing 12 months) */
									__( '%s active donors, but none new this timeframe', 'newspack-plugin' ),
									formatNumber( activeDonors )
							  )
							: undefined
					}
					description={ __( 'First-time donors in selected timeframe', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Lapsed donors', 'newspack-plugin' ) }
					value={ current.lapsed_donors }
					format="number"
					previousValue={ previous?.lapsed_donors }
					lowerIsBetter
					description={ __( 'Donors who stopped recurring giving in this timeframe', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Total donation revenue', 'newspack-plugin' ) }
					value={ current.total_revenue }
					format="currency"
					previousValue={ previous?.total_revenue }
					secondary={ revenueBreakdown( current ) }
					// Real zero (e.g. a window where only lapses occurred): show "No
					// donations in this timeframe" rather than a bare $0.00.
					zeroFallback={
						current.total_revenue === 0 ? { denominator: 0, currencyRole: 'total', attemptsLabel: donationsLabel } : undefined
					}
					description={ __( 'One-time gifts + recurring renewals in this timeframe', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'Average one-time gift', 'newspack-plugin' ) }
					value={ current.average_gift }
					format="currency"
					previousValue={ previous?.average_gift }
					// Recurring-only window: no one-time gifts to average. Show "No one-time
					// gifts in this timeframe" instead of $0.00 (NPPD-1696 mode suppression).
					zeroFallback={
						current.one_time_revenue === 0 ? { denominator: 0, currencyRole: 'average', attemptsLabel: oneTimeGiftsLabel } : undefined
					}
					description={ __( 'Mean order total across one-time donation orders in this timeframe', 'newspack-plugin' ) }
				/>
			</div>
		</section>
	);
};

export default WindowedSection;
