/**
 * LineChart — shared time-series line viz for Insights tabs (NPPD-1609, NPPD-1649).
 *
 * Hoisted from the per-tab copies in audience/viz/ and conversion/viz/ into the
 * shared tabs/components/ directory. This is the superset: it includes everything
 * both copies had plus their individual additions merged cleanly.
 *
 * Features:
 *   - Single-series via `points`; multi-series via `series` (takes precedence).
 *   - X-axis spine driven by the longest series so unequal-length series stay
 *     column-aligned and the hit-band geometry is never off a short first series.
 *   - Empty only when *every* series is empty.
 *   - Optional horizontal reference line (`referenceLine`) — e.g. a cohort target.
 *   - Optional y-axis ceiling (`yMax`) — data always wins if it exceeds the pin.
 *   - Configurable empty-state copy (`emptyMessage`).
 *   - `formatLabel` humanizes x-axis labels in tooltips and the meta row.
 *   - Dark hover panel anchored to each x-index; one hit band per column for
 *     forgiving mouse targeting.
 *   - Dependency-free SVG.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { formatNumber } from './format';

export interface LinePoint {
	label: string;
	value: number;
}

export interface LineSeries {
	name: string;
	points: LinePoint[];
}

export interface LineReferenceLine {
	value: number;
	label: string;
}

export interface LineChartProps {
	/** Single-series convenience. */
	points?: LinePoint[];
	/** Multi-series (shared x-axis / labels). Takes precedence over `points`. */
	series?: LineSeries[];
	/** Humanize an x-axis label for tooltips and the meta row. Defaults to identity. */
	formatLabel?: ( label: string ) => string;
	/** Optional horizontal target line (value in the same units as the y-axis). */
	referenceLine?: LineReferenceLine;
	/**
	 * Optional pin for the top of the y-axis (e.g. `1` for a fixed 0–100% scale
	 * on share/percentage curves). Data still wins if it exceeds the pin. When
	 * unset, the axis auto-scales to the data — right for small-magnitude rate
	 * series (e.g. weekly conversion rates) that would otherwise be flattened.
	 */
	yMax?: number;
	/** Empty-state copy shown when there's no data. Defaults to the generic line. */
	emptyMessage?: string;
}

const W = 600;
const H = 160;
const PAD = 8;

const LineChart = ( { points, series, formatLabel = ( l: string ) => l, referenceLine, yMax, emptyMessage }: LineChartProps ) => {
	const [ active, setActive ] = useState< number | null >( null );

	const allSeries: LineSeries[] = series && series.length ? series : [ { name: '', points: points ?? [] } ];

	// Empty only when *every* series is empty — a single empty series must not
	// blank a chart whose other series carry data.
	if ( allSeries.every( s => s.points.length === 0 ) ) {
		return <p className="newspack-insights__chart-empty">{ emptyMessage ?? __( 'No data in this timeframe.', 'newspack-plugin' ) }</p>;
	}

	// The x-axis spine is the longest series, so unequal-length series stay
	// column-aligned (each plots on its own 0-based index) and the empty-state /
	// hit-band / tooltip-label geometry can't be driven off a short first series.
	const base = allSeries.reduce( ( longest, s ) => ( s.points.length > longest.length ? s.points : longest ), allSeries[ 0 ].points );

	const isMulti = allSeries.length > 1;
	const n = base.length;
	const allValues = allSeries.flatMap( s => s.points.map( p => p.value || 0 ) );
	// Pull the reference value into the domain so the target line stays on-canvas.
	if ( referenceLine ) {
		allValues.push( referenceLine.value );
	}
	const dataMax = allValues.length ? Math.max( ...allValues ) : 0;
	// `yMax` pins the ceiling; data always wins if it exceeds the pin. The `|| 1`
	// only guards a zero-height domain (all values and pin are 0).
	const max = Math.max( dataMax, yMax ?? 0 ) || 1;
	const min = Math.min( ...allValues, 0 );
	const span = max - min || 1;
	const stepX = n > 1 ? ( W - PAD * 2 ) / ( n - 1 ) : 0;

	const xAt = ( i: number ) => PAD + i * stepX;
	const yAt = ( v: number ) => H - PAD - ( ( ( v || 0 ) - min ) / span ) * ( H - PAD * 2 );

	const tooltipTop = active === null ? 0 : Math.min( ...allSeries.map( s => yAt( s.points[ active ]?.value ?? 0 ) ) );

	return (
		<div className="newspack-insights__line" onMouseLeave={ () => setActive( null ) }>
			<div className="newspack-insights__line-plot">
				<svg
					viewBox={ `0 0 ${ W } ${ H }` }
					className="newspack-insights__line-svg"
					role="img"
					aria-label={ __( 'Time-series chart', 'newspack-plugin' ) }
					preserveAspectRatio="none"
				>
					{ referenceLine && (
						<line
							className="newspack-insights__line-reference"
							x1={ PAD }
							x2={ W - PAD }
							y1={ yAt( referenceLine.value ) }
							y2={ yAt( referenceLine.value ) }
						/>
					) }
					{ allSeries.map( ( s, si ) => {
						const coords = s.points.map( ( p, i ) => [ xAt( i ), yAt( p.value ) ] as const );
						const linePts = coords.map( ( [ x, y ] ) => `${ x.toFixed( 1 ) },${ y.toFixed( 1 ) }` ).join( ' ' );
						const area = `${ PAD },${ H - PAD } ${ linePts } ${ xAt( n - 1 ).toFixed( 1 ) },${ H - PAD }`;
						return (
							<g key={ `series-${ si }` }>
								{ ! isMulti && <polygon className={ `newspack-insights__line-area is-series-${ si }` } points={ area } /> }
								<polyline className={ `newspack-insights__line-stroke is-series-${ si }` } points={ linePts } fill="none" />
								{ coords.map( ( [ x, y ], i ) => (
									<circle
										key={ `pt-${ si }-${ i }` }
										className={ `newspack-insights__line-point is-series-${ si }` }
										cx={ x }
										cy={ y }
										r={ active === i ? 4.5 : 3 }
									/>
								) ) }
							</g>
						);
					} ) }
					{ /* One transparent hit band per x-index so hovering anywhere in a column is forgiving. */ }
					{ base.map( ( _p, i ) => (
						<rect
							key={ `hit-${ i }` }
							className="newspack-insights__line-hit"
							x={ n > 1 ? xAt( i ) - stepX / 2 : 0 }
							y="0"
							width={ n > 1 ? stepX : W }
							height={ H }
							onMouseEnter={ () => setActive( i ) }
						/>
					) ) }
				</svg>
				{ referenceLine && <span className="newspack-insights__line-reference-label">{ referenceLine.label }</span> }
				{ active !== null && (
					<div
						className="newspack-insights__chart-tooltip"
						style={ { left: `${ ( xAt( active ) / W ) * 100 }%`, top: `${ ( tooltipTop / H ) * 100 }%` } }
					>
						<span className="newspack-insights__chart-tooltip-label">{ formatLabel( base[ active ].label ) }</span>
						{ allSeries.map( ( s, si ) => (
							<span key={ `tt-${ si }` } className="newspack-insights__chart-tooltip-row">
								{ isMulti && <span className={ `newspack-insights__chart-tooltip-swatch is-series-${ si }` } aria-hidden="true" /> }
								{ isMulti && <span className="newspack-insights__chart-tooltip-name">{ s.name }</span> }
								<span className="newspack-insights__chart-tooltip-value">{ formatNumber( s.points[ active ]?.value ?? 0 ) }</span>
							</span>
						) ) }
					</div>
				) }
			</div>
			{ isMulti ? (
				<div className="newspack-insights__line-legend">
					{ allSeries.map( ( s, si ) => (
						<span key={ `lg-${ si }` } className="newspack-insights__line-legend-item">
							<span className={ `newspack-insights__legend-swatch is-series-${ si }` } aria-hidden="true" />
							<span>{ s.name }</span>
						</span>
					) ) }
				</div>
			) : (
				<div className="newspack-insights__line-meta">
					<span>{ formatLabel( base[ 0 ].label ) }</span>
					<span>
						{ __( 'peak', 'newspack-plugin' ) }: { formatNumber( dataMax ) }
					</span>
					<span>{ formatLabel( base[ n - 1 ].label ) }</span>
				</div>
			) }
		</div>
	);
};

export default LineChart;
