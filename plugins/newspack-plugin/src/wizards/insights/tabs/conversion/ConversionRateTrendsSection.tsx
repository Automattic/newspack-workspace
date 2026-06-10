/**
 * ConversionRateTrendsSection (NPPD-1609, Section 6).
 *
 * A single full-width multi-series weekly LineChart: registration
 * conversion rate and subscription attempt rate over the window. Phase 1
 * renders the empty state.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWeeklyTrendsData } from '../../api/conversion';
import LineChart, { type LineSeries } from './viz/LineChart';

export interface ConversionRateTrendsSectionProps {
	current: {
		weekly_conversion_rates: ConversionWeeklyTrendsData;
	};
}

/** Split the weekly rows into the two tracked rate series. */
const toTrendSeries = ( data: ConversionWeeklyTrendsData ): LineSeries[] => [
	{
		name: __( 'Registration rate', 'newspack-plugin' ),
		points: data.weeks.map( w => ( { label: w.week, value: w.registration_rate } ) ),
	},
	{
		name: __( 'Subscription attempt rate', 'newspack-plugin' ),
		points: data.weeks.map( w => ( { label: w.week, value: w.subscription_attempt_rate } ) ),
	},
];

const ConversionRateTrendsSection = ( { current }: ConversionRateTrendsSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--rate-trends"
		aria-labelledby="newspack-insights-conversion-rate-trends-heading"
	>
		<h2 id="newspack-insights-conversion-rate-trends-heading" className="newspack-insights__section-heading">
			{ __( 'Conversion rate trends', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'Weekly conversion rates across the selected window. Useful for spotting acceleration, plateaus, or seasonality.',
				'newspack-plugin'
			) }
		</p>
		<LineChart
			series={ toTrendSeries( current.weekly_conversion_rates ) }
			emptyMessage={ __( 'Weekly trends will appear once the window contains at least 4 weeks of data.', 'newspack-plugin' ) }
		/>
	</section>
);

export default ConversionRateTrendsSection;
