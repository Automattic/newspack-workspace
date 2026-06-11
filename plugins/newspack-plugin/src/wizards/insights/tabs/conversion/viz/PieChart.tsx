/**
 * PieChart (NPPD-1609) — tab-local donut for the Section 3 source-mix
 * breakdowns. A copy of the Audience tab's PieChart (NPPD-1649), extended
 * with a `centerLabel` (the spec's "total in window" at the donut center)
 * and a configurable `emptyMessage` so the Phase 1 empty state shows the
 * section's spec copy. Viz stay tab-local until a canonical PieChart lands
 * in packages/components/src/ (a v1.1 ticket, per the Audience PieChart's
 * own note).
 *
 * Dependency-free SVG donut + legend. Series colors come from the shared
 * `.is-series-N` CSS classes in `tabs/components/_insights-charts.scss`.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber, formatPercent } from '../../components/format';

export interface PieSegment {
	label: string;
	value: number;
}

export interface PieChartProps {
	segments: PieSegment[];
	/** Optional label rendered at the donut center (e.g. the window total). */
	centerLabel?: string;
	/** Empty-state copy shown when there's no data. Defaults to the generic line. */
	emptyMessage?: string;
}

const RADIUS = 16;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const PieChart = ( { segments, centerLabel, emptyMessage }: PieChartProps ) => {
	const total = segments.reduce( ( sum, s ) => sum + ( s.value || 0 ), 0 );
	if ( total <= 0 ) {
		return <p className="newspack-insights__chart-empty">{ emptyMessage ?? __( 'No data in this timeframe.', 'newspack-plugin' ) }</p>;
	}

	let offset = 0;
	return (
		<div className="newspack-insights__pie">
			{ /* Pie centered in the middle slot; legend anchored to the bottom (NPPD-1649 alignment). */ }
			<div className="newspack-insights__pie-figure">
				<svg viewBox="0 0 42 42" className="newspack-insights__pie-svg" role="img" aria-label={ __( 'Breakdown chart', 'newspack-plugin' ) }>
					<circle className="newspack-insights__pie-track" cx="21" cy="21" r={ RADIUS } />
					{ segments.map( ( segment, i ) => {
						const fraction = segment.value / total;
						const dash = fraction * CIRCUMFERENCE;
						// No hover tooltip on pie segments — the legend already shows
						// label + value + percent (NPPD-1649 fix #6).
						const circle = (
							<circle
								key={ segment.label }
								className={ `newspack-insights__pie-segment is-series-${ i % 7 }` }
								cx="21"
								cy="21"
								r={ RADIUS }
								strokeDasharray={ `${ dash } ${ CIRCUMFERENCE - dash }` }
								strokeDashoffset={ CIRCUMFERENCE / 4 - offset }
							/>
						);
						offset += dash;
						return circle;
					} ) }
					{ centerLabel && (
						<text className="newspack-insights__pie-center" x="21" y="21" textAnchor="middle" dominantBaseline="central">
							{ centerLabel }
						</text>
					) }
				</svg>
			</div>
			<ul className="newspack-insights__pie-legend">
				{ segments.map( ( segment, i ) => (
					<li key={ segment.label }>
						<span className={ `newspack-insights__legend-swatch is-series-${ i % 7 }` } aria-hidden="true" />
						<span className="newspack-insights__legend-label">{ segment.label }</span>
						<span className="newspack-insights__legend-value">
							{ formatNumber( segment.value ) } ({ formatPercent( segment.value / total ) })
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
};

export default PieChart;
