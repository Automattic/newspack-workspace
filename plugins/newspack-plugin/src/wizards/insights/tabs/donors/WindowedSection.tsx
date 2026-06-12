/**
 * WindowedSection (NPPD-1617).
 *
 * Tab 7 metrics scoped to the date range picker: new/lapsed donor
 * counts, total donation revenue (with an inline one-time + recurring
 * breakdown as a secondary line), and the average gift. Heading is
 * dynamic ("In the last 30 days", "This month", etc.) — same pattern
 * as Tab 6's WindowedSection.
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
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import { formatCurrency } from '../components/format';

export interface WindowedSectionProps {
	range: DateRange;
	current: DonorsWindow;
	previous: DonorsWindow | null;
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

const WindowedSection = ( { range, current, previous }: WindowedSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--windowed" aria-labelledby="newspack-insights-donors-windowed-heading">
		<SectionHeading id="newspack-insights-donors-windowed-heading" title={ getHeading( range ) } />
		<div className="newspack-insights__metric-grid">
			<MetricCard
				label={ __( 'New donors', 'newspack-plugin' ) }
				value={ current.new_donors }
				format="number"
				previousValue={ previous?.new_donors }
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
				secondary={ sprintf(
					/* translators: 1: one-time gift revenue formatted as currency, 2: recurring renewal revenue formatted as currency */
					__( '%1$s one-time + %2$s recurring', 'newspack-plugin' ),
					formatCurrency( current.one_time_revenue ).display,
					formatCurrency( current.recurring_revenue ).display
				) }
				description={ __( 'One-time gifts + recurring renewals in this timeframe', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Average one-time gift', 'newspack-plugin' ) }
				value={ current.average_gift }
				format="currency"
				previousValue={ previous?.average_gift }
				description={ __( 'Mean order total across one-time donation orders in this timeframe', 'newspack-plugin' ) }
			/>
		</div>
	</section>
);

export default WindowedSection;
