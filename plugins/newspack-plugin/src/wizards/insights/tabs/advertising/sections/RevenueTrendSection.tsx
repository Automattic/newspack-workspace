/**
 * Advertising › Revenue trend (NPPD-1674).
 *
 * A full-width daily-revenue line chart across the selected window — the shape
 * behind the Reach & revenue scorecards. Under comparison, the prior period
 * overlays as a second (recessive) line. Currency-formatted Y-axis + tooltip,
 * date-formatted X-axis. Reuses the shared LineChart (NPPD-1649); a zero-revenue
 * window falls through to LineChart's own empty state.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/advertising';
import ChartCard from '../../components/ChartCard';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import LineChart from '../../components/LineChart';
import { toSeries } from '../../components/metrics';
import { formatCurrency, formatShortDate } from '../../components/format';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

// GAM's DATE dimension is 'YYYY-MM-DD'; formatShortDate expects GA4's 'YYYYMMDD'.
// Strip all non-digits so any separator variation still yields the 8-digit form.
const formatDateLabel = ( date: string ) => formatShortDate( date.replace( /\D/g, '' ) );
const formatDollars = ( value: number ) => formatCurrency( value ).display;

const RevenueTrendSection = ( { current, previous }: SectionProps ) => {
	const currentPoints = toSeries( current.revenue_by_day, 'date', 'value' );
	const previousPoints = previous ? toSeries( previous.revenue_by_day, 'date', 'value' ) : [];
	const series = [
		{ name: __( 'This period', 'newspack-plugin' ), points: currentPoints },
		...( previousPoints.length ? [ { name: __( 'Previous period', 'newspack-plugin' ), points: previousPoints } ] : [] ),
	];

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-revenue-trend">
			<SectionHeading
				id="newspack-insights-advertising-revenue-trend"
				title={ __( 'Revenue trend', 'newspack-plugin' ) }
				description={ __( 'Daily revenue across the selected period.', 'newspack-plugin' ) }
			/>
			<ChartCard payload={ current.revenue_by_day }>
				<LineChart
					series={ series }
					formatLabel={ formatDateLabel }
					formatValue={ formatDollars }
					yAxisLabel={ __( 'Revenue', 'newspack-plugin' ) }
				/>
			</ChartCard>
		</Section>
	);
};

export default RevenueTrendSection;
