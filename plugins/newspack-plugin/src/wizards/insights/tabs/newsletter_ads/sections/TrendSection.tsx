/**
 * Newsletter Ads › Performance trend (NPPD-1861).
 *
 * Impressions and Clicks as two side-by-side ChartCards (the shared
 * chart-grid --cols-2 pattern, e.g. Audience's TimeTrendsSection) rather than
 * two series on one chart: impressions run orders of magnitude above clicks,
 * so a shared y-axis flattens the clicks series into an unreadable baseline.
 * Splitting also restores previous-period compare parity with Advertising's
 * RevenueTrendSection — each chart overlays "Previous period" when comparison
 * is on, staying at two lines per chart. A zero-activity window falls through
 * to LineChart's own empty state.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/newsletter_ads';
import ChartCard from '../../components/ChartCard';
import SectionHeading from '../../components/SectionHeading';
import LineChart from '../../components/LineChart';
import { toSeries } from '../../components/metrics';
import { formatShortDate } from '../../components/format';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

// Rows carry 'YYYY-MM-DD' dates; formatShortDate expects GA4's 'YYYYMMDD'.
// Strip all non-digits so any separator variation still yields the 8-digit form.
const formatDateLabel = ( date: string ) => formatShortDate( date.replace( /\D/g, '' ) );

/** Build the this-period series plus the previous-period overlay when comparing. */
const buildSeries = ( current: InsightsWindow, previous: InsightsWindow | null, valueKey: 'impressions' | 'clicks' ) => {
	const previousPoints = previous ? toSeries( previous.performance_by_day, 'date', valueKey ) : [];
	return [
		{ name: __( 'This period', 'newspack-plugin' ), points: toSeries( current.performance_by_day, 'date', valueKey ) },
		...( previousPoints.length ? [ { name: __( 'Previous period', 'newspack-plugin' ), points: previousPoints } ] : [] ),
	];
};

const TrendSection = ( { current, previous }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-newsletter-ads-trend">
		<SectionHeading
			id="newspack-insights-newsletter-ads-trend"
			title={ __( 'Performance trend', 'newspack-plugin' ) }
			description={ __( 'Daily impressions and clicks across the selected timeframe.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">
			<ChartCard title={ __( 'Impressions', 'newspack-plugin' ) } payload={ current.performance_by_day }>
				<LineChart series={ buildSeries( current, previous, 'impressions' ) } formatLabel={ formatDateLabel } />
			</ChartCard>
			<ChartCard title={ __( 'Clicks', 'newspack-plugin' ) } payload={ current.performance_by_day }>
				<LineChart series={ buildSeries( current, previous, 'clicks' ) } formatLabel={ formatDateLabel } />
			</ChartCard>
		</div>
	</section>
);

export default TrendSection;
