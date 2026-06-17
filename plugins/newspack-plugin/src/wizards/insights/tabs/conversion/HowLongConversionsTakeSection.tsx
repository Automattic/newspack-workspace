/**
 * HowLongConversionsTakeSection (NPPD-1609, Section 4).
 *
 * A 2×2 grid of cumulative-distribution LineCharts:
 *   4.1 time to register (single series) — Phase A, wired to real data.
 *   4.2 time to subscribe (three series by source) — Phase B, coming_soon.
 *   4.3 time to donate (three series by source) — Phase B, coming_soon.
 *   4.4 subscriber → donor lag (visibility-gated single series) — Phase B, coming_soon.
 *
 * Phase 2: each chart's rendering is gated on the metric's `state` envelope.
 * Section 4.4 also respects `visibility`: when `visibility === 'hidden'` the
 * gated note is shown regardless of state.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionCumulativeMulti, ConversionCumulativePoint, ConversionWindow } from '../../api/conversion';
import SectionHeading from '../components/SectionHeading';
import { sourceLabel } from './labels';
import LineChart, { type LinePoint, type LineSeries } from '../components/LineChart';
import SectionState from './SectionState';

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
	return (
		<section
			className="newspack-insights__section newspack-insights__section--time-to-convert"
			aria-labelledby="newspack-insights-conversion-time-to-convert-heading"
		>
			<SectionHeading
				id="newspack-insights-conversion-time-to-convert-heading"
				title={ __( 'How long conversions take', 'newspack-plugin' ) }
				description={ __(
					'Cumulative conversion curves per cohort. Each line shows what percentage of readers had converted by day N. Steeper early curves mean faster conversion; flatter curves mean longer tails. Median is where the line crosses 50%.',
					'newspack-plugin'
				) }
			/>
			<div className="newspack-insights__conversion-curve-grid">
				{ /* 4.1 — time to register: Phase A, state-gated */ }
				<CurveCell title={ __( 'Time to register', 'newspack-plugin' ) }>
					<SectionState
						state={ current.time_to_register_distribution.state }
						emptyMessage={ __( 'Time-to-register data will appear once registrations occur in this window.', 'newspack-plugin' ) }
					>
						<LineChart
							points={ toLinePoints( current.time_to_register_distribution.points ) }
							yMax={ 1 }
							emptyMessage={ __( 'Time-to-register data will appear once registrations occur in this window.', 'newspack-plugin' ) }
						/>
					</SectionState>
				</CurveCell>
				{ /* 4.2 — time to subscribe: Phase B, coming_soon */ }
				<CurveCell title={ __( 'Time to subscribe', 'newspack-plugin' ) }>
					<SectionState
						state={ current.time_to_subscribe_distribution.state }
						emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
					>
						<LineChart
							series={ toLineSeries( current.time_to_subscribe_distribution ) }
							yMax={ 1 }
							emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
						/>
					</SectionState>
				</CurveCell>
				{ /* 4.3 — time to donate: Phase B, coming_soon */ }
				<CurveCell title={ __( 'Time to donate', 'newspack-plugin' ) }>
					<SectionState
						state={ current.time_to_donate_distribution.state }
						emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
					>
						<LineChart
							series={ toLineSeries( current.time_to_donate_distribution ) }
							yMax={ 1 }
							emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
						/>
					</SectionState>
				</CurveCell>
				{ /* 4.4 — subscriber → donor lag: Phase B, coming_soon + visibility gate */ }
				<CurveCell title={ __( 'Subscriber → donor lag', 'newspack-plugin' ) }>
					{ lag.visibility === 'hidden' ? (
						<p className="newspack-insights__conversion-gated-note">
							{ __( 'Subscriber-to-donor lag appears when at least 50 readers have both subscribed and donated.', 'newspack-plugin' ) }
						</p>
					) : (
						<SectionState
							state={ lag.state }
							emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
						>
							<LineChart
								points={ toLinePoints( lag.points ) }
								yMax={ 1 }
								emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this window.', 'newspack-plugin' ) }
							/>
						</SectionState>
					) }
				</CurveCell>
			</div>
		</section>
	);
};

export default HowLongConversionsTakeSection;
