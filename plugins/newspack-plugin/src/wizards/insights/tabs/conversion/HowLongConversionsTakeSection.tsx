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
import { Card, Grid, Notice } from '../../../../../packages/components/src';
import type { ConversionCumulativeMulti, ConversionCumulativePoint, ConversionWindow } from '../../api/conversion';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import { sourceLabel, dayLabel } from './labels';
import { formatPercent } from '../components/format';
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
	caption?: string;
	children: React.ReactNode;
}

const CurveCell = ( { title, caption, children }: CurveCellProps ) => (
	<Card __experimentalCoreCard className="newspack-insights__chart-card">
		<h3 className="newspack-insights__chart-card-title">{ title }</h3>
		{ caption && <p className="newspack-insights__chart-card-caption">{ caption }</p> }
		<div className="newspack-insights__chart-card-body">{ children }</div>
	</Card>
);

const HowLongConversionsTakeSection = ( { current }: HowLongConversionsTakeSectionProps ) => {
	const lag = current.subscriber_to_donor_lag_distribution;
	const snapshotCaption = __( 'This view always uses all available history, regardless of the selected date range.', 'newspack-plugin' );
	return (
		<Section
			className="newspack-insights__section newspack-insights__section--time-to-convert"
			aria-labelledby="newspack-insights-conversion-time-to-convert-heading"
		>
			<SectionHeading
				id="newspack-insights-conversion-time-to-convert-heading"
				title={ __( 'How Long Conversions Take', 'newspack-plugin' ) }
				description={ __(
					'Cumulative conversion curves per cohort. Each line shows what percentage of readers had converted by day N. Steeper early curves mean faster conversion; flatter curves mean longer tails. Median is where the line crosses 50%.',
					'newspack-plugin'
				) }
			/>
			<Grid columns={ 2 } gutter={ 16 } noMargin>
				{ /* 4.1 — time to register: Phase A, state-gated */ }
				<CurveCell title={ __( 'Time to Register', 'newspack-plugin' ) }>
					<SectionState
						state={ current.time_to_register_distribution.state }
						emptyMessage={ __( 'Time-to-register data will appear once registrations occur in this timeframe.', 'newspack-plugin' ) }
					>
						<LineChart
							points={ toLinePoints( current.time_to_register_distribution.points ) }
							yMax={ 1 }
							formatLabel={ dayLabel }
							formatValue={ formatPercent }
							xAxisLabel={ __( 'Days', 'newspack-plugin' ) }
							yAxisLabel={ __( 'Percent registered', 'newspack-plugin' ) }
							emptyMessage={ __( 'Time-to-register data will appear once registrations occur in this timeframe.', 'newspack-plugin' ) }
						/>
					</SectionState>
				</CurveCell>
				{ /* 4.2 — time to subscribe: Phase B, coming_soon */ }
				<CurveCell title={ __( 'Time to Subscribe', 'newspack-plugin' ) } caption={ snapshotCaption }>
					<SectionState
						state={ current.time_to_subscribe_distribution.state }
						emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this timeframe.', 'newspack-plugin' ) }
					>
						<LineChart
							series={ toLineSeries( current.time_to_subscribe_distribution ) }
							yMax={ 1 }
							formatLabel={ dayLabel }
							formatValue={ formatPercent }
							xAxisLabel={ __( 'Days', 'newspack-plugin' ) }
							yAxisLabel={ __( 'Percent subscribed', 'newspack-plugin' ) }
							emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this timeframe.', 'newspack-plugin' ) }
						/>
					</SectionState>
				</CurveCell>
				{ /* 4.3 — time to donate: Phase B, coming_soon */ }
				<CurveCell title={ __( 'Time to Donate', 'newspack-plugin' ) } caption={ snapshotCaption }>
					<SectionState
						state={ current.time_to_donate_distribution.state }
						emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this timeframe.', 'newspack-plugin' ) }
					>
						<LineChart
							series={ toLineSeries( current.time_to_donate_distribution ) }
							yMax={ 1 }
							formatLabel={ dayLabel }
							formatValue={ formatPercent }
							xAxisLabel={ __( 'Days', 'newspack-plugin' ) }
							yAxisLabel={ __( 'Percent donated', 'newspack-plugin' ) }
							emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this timeframe.', 'newspack-plugin' ) }
						/>
					</SectionState>
				</CurveCell>
				{ /* 4.4 — subscriber → donor lag: Phase B, coming_soon + visibility gate */ }
				<CurveCell title={ __( 'Subscriber → Donor Lag', 'newspack-plugin' ) } caption={ snapshotCaption }>
					{ lag.visibility === 'hidden' ? (
						<Notice
							isWarning
							className="newspack-insights__conversion-notice"
							noticeText={ __(
								'Subscriber-to-donor lag appears when at least 50 readers have both subscribed and donated.',
								'newspack-plugin'
							) }
						/>
					) : (
						<SectionState
							state={ lag.state }
							emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this timeframe.', 'newspack-plugin' ) }
						>
							<LineChart
								points={ toLinePoints( lag.points ) }
								yMax={ 1 }
								formatLabel={ dayLabel }
								formatValue={ formatPercent }
								xAxisLabel={ __( 'Days', 'newspack-plugin' ) }
								yAxisLabel={ __( 'Percent who donated', 'newspack-plugin' ) }
								emptyMessage={ __( 'Time-to-convert data will appear once conversions occur in this timeframe.', 'newspack-plugin' ) }
							/>
						</SectionState>
					) }
				</CurveCell>
			</Grid>
		</Section>
	);
};

export default HowLongConversionsTakeSection;
