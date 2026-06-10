/**
 * HowLongConversionsTakeSection (NPPD-1609, Section 4).
 *
 * A 2×2 grid of cumulative-distribution LineCharts: time to register
 * (single series), time to subscribe and time to donate (three series by
 * source), and the visibility-gated subscriber → donor lag. Each line shows
 * the share of the cohort converted by day N. Phase 1 renders the empty
 * state per chart.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionCumulativeMulti, ConversionCumulativePoint, ConversionWindow } from '../../api/conversion';
import { sourceLabel } from './labels';
import LineChart, { type LinePoint, type LineSeries } from './viz/LineChart';

export interface HowLongConversionsTakeSectionProps {
	current: ConversionWindow;
}

/** Map cumulative points to the LineChart's x-label / y-value shape. */
const toLinePoints = ( points: ConversionCumulativePoint[] ): LinePoint[] =>
	points.map( p => ( { label: String( p.day ), value: p.cumulative_pct } ) );

/** Map a multi-series distribution to per-source LineChart series. */
const toLineSeries = ( data: ConversionCumulativeMulti ): LineSeries[] =>
	data.groups.map( group => ( { name: sourceLabel( group.label ), points: toLinePoints( group.points ) } ) );

interface CurveCellProps {
	title: string;
	children: React.ReactNode;
}

const CurveCell = ( { title, children }: CurveCellProps ) => (
	<div className="newspack-insights__conversion-curve-cell">
		<h3 className="newspack-insights__conversion-subheading">{ title }</h3>
		{ children }
	</div>
);

const HowLongConversionsTakeSection = ( { current }: HowLongConversionsTakeSectionProps ) => {
	const lag = current.subscriber_to_donor_lag_distribution;
	const lagHidden = lag.visibility === 'hidden';
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
			<div className="newspack-insights__conversion-curve-grid">
				<CurveCell title={ __( 'Time to register', 'newspack-plugin' ) }>
					<LineChart
						points={ toLinePoints( current.time_to_register_distribution.points ) }
						emptyMessage={ __( 'Time-to-register data will appear once registrations occur in this window.', 'newspack-plugin' ) }
					/>
				</CurveCell>
				<CurveCell title={ __( 'Time to subscribe', 'newspack-plugin' ) }>
					<LineChart
						series={ toLineSeries( current.time_to_subscribe_distribution ) }
						emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
					/>
				</CurveCell>
				<CurveCell title={ __( 'Time to donate', 'newspack-plugin' ) }>
					<LineChart
						series={ toLineSeries( current.time_to_donate_distribution ) }
						emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
					/>
				</CurveCell>
				<CurveCell title={ __( 'Subscriber → donor lag', 'newspack-plugin' ) }>
					{ lagHidden ? (
						<p className="newspack-insights__conversion-gated-note">
							{ __( 'Subscriber-to-donor lag appears when at least 50 readers have both subscribed and donated.', 'newspack-plugin' ) }
						</p>
					) : (
						<LineChart
							points={ toLinePoints( lag.points ) }
							emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
						/>
					) }
				</CurveCell>
			</div>
		</section>
	);
};

export default HowLongConversionsTakeSection;
