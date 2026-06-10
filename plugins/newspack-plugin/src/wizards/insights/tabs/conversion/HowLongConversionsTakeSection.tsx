/**
 * HowLongConversionsTakeSection (NPPD-1609, Section 4).
 *
 * A 2×2 grid of cumulative-distribution LineCharts: time to register, time
 * to subscribe (by source), time to donate (by source), and the
 * visibility-gated subscriber → donor lag. Scaffold renders header +
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

export interface HowLongConversionsTakeSectionProps {
	current: ConversionWindow;
}

const HowLongConversionsTakeSection = ( { current }: HowLongConversionsTakeSectionProps ) => {
	const lagHidden = current.subscriber_to_donor_lag_distribution.visibility === 'hidden';
	return (
		<section
			className="newspack-insights__section newspack-insights__section--time-to-convert"
			aria-labelledby="newspack-insights-conversion-time-to-convert-heading"
		>
			<h2 id="newspack-insights-conversion-time-to-convert-heading" className="newspack-insights__section-heading">
				{ __( 'How long conversions take', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __(
					'Cumulative conversion curves per cohort. Each line shows what percentage of readers had converted by day N. Steeper early curves mean faster conversion; flatter curves mean longer tails. Median is where the line crosses 50%.',
					'newspack-plugin'
				) }
			</p>
			<div className="newspack-insights__viz-placeholder" data-lag-hidden={ lagHidden } />
		</section>
	);
};

export default HowLongConversionsTakeSection;
