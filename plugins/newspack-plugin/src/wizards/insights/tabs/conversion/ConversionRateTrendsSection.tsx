/**
 * ConversionRateTrendsSection (NPPD-1609, Section 6).
 *
 * A single full-width multi-series weekly LineChart: registration
 * conversion rate and subscription attempt rate over the window.
 *
 * Phase 2: rendering is gated on the metric's `state` envelope
 * (populated / empty / error / coming_soon).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWeekPoint, ConversionWeeklyTrendsData } from '../../api/conversion';
import SectionHeading from '../components/SectionHeading';
import LineChart, { type LineSeries } from '../components/LineChart';
import { formatPercent } from '../components/format';
import { weekLabel } from './labels';
import SectionState from './SectionState';

export interface ConversionRateTrendsSectionProps {
	current: {
		weekly_conversion_rates: ConversionWeeklyTrendsData;
	};
}

/**
 * The server's `series` array is authoritative for which rate series render
 * and in what order. Each key maps to its display label and the week-row field
 * it reads; unknown keys are skipped so a Phase 2 series addition can't crash
 * the chart before the UI knows about it.
 */
const SERIES_BY_KEY: Record< string, { label: string; value: ( w: ConversionWeekPoint ) => number } > = {
	registration_rate: {
		label: __( 'Registrations (per active reader)', 'newspack-plugin' ),
		value: w => w.registration_conversion_rate,
	},
	subscription_conversion_rate: {
		label: __( 'Subscriptions (per registered reader)', 'newspack-plugin' ),
		value: w => w.subscription_conversion_rate,
	},
};

/** Build the LineChart series from the payload's declared series keys. */
const toTrendSeries = ( data: ConversionWeeklyTrendsData ): LineSeries[] =>
	data.series
		.filter( key => SERIES_BY_KEY[ key ] )
		.map( key => ( {
			name: SERIES_BY_KEY[ key ].label,
			points: data.weeks.map( w => ( { label: w.week, value: SERIES_BY_KEY[ key ].value( w ) } ) ),
		} ) );

const ConversionRateTrendsSection = ( { current }: ConversionRateTrendsSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--rate-trends"
		aria-labelledby="newspack-insights-conversion-rate-trends-heading"
	>
		<SectionHeading
			id="newspack-insights-conversion-rate-trends-heading"
			title={ __( 'Conversion rate trends', 'newspack-plugin' ) }
			description={ __(
				'Weekly conversion rates across the selected timeframe. Useful for spotting acceleration, plateaus, or seasonality.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__conversion-rate-trends-cell">
			<SectionState
				state={ current.weekly_conversion_rates.state }
				emptyMessage={ __( 'Weekly trends will appear once the timeframe contains at least 4 weeks of data.', 'newspack-plugin' ) }
			>
				<LineChart
					series={ toTrendSeries( current.weekly_conversion_rates ) }
					formatLabel={ weekLabel }
					formatValue={ formatPercent }
					xAxisLabel={ __( 'Week', 'newspack-plugin' ) }
					yAxisLabel={ __( 'Conversion rate', 'newspack-plugin' ) }
					emptyMessage={ __( 'Weekly trends will appear once the timeframe contains at least 4 weeks of data.', 'newspack-plugin' ) }
				/>
			</SectionState>
		</div>
	</section>
);

export default ConversionRateTrendsSection;
