/**
 * ConversionRateTrendsSection (NPPD-1609, Section 6).
 *
 * A single full-width multi-series weekly LineChart (registration rate,
 * subscription attempt rate). Window-scoped. Scaffold renders header +
 * caption + an empty placeholder; the LineChart viz is wired in the
 * following commit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';

export interface ConversionRateTrendsSectionProps {
	current: ConversionWindow;
}

const ConversionRateTrendsSection = ( { current }: ConversionRateTrendsSectionProps ) => {
	const pending = current.weekly_conversion_rates.pending;
	return (
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
			<div className="newspack-insights__viz-placeholder" data-pending={ pending } />
		</section>
	);
};

export default ConversionRateTrendsSection;
