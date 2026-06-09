/**
 * LineChart (NPPD-1649) — tab-local time-series line(s).
 *
 * Dependency-free SVG. Renders one line from `points`, or several color-coded
 * lines from `series` (e.g. New vs Returning on a shared date axis — fix
 * round 3). A custom dark hover panel anchored to each x-index shows the label
 * plus every series' value there. `formatLabel` humanizes x labels (e.g.
 * YYYYMMDD → "May 10").
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { formatNumber } from '../../components/format';

export interface LinePoint {
	label: string;
	value: number;
}

export interface LineSeries {
	name: string;
	points: LinePoint[];
}

export interface LineChartProps {
	/** Single-series convenience. */
	points?: LinePoint[];
	/** Multi-series (shared x-axis / labels). Takes precedence over `points`. */
	series?: LineSeries[];
	/** Humanize an x-axis label for tooltips and the meta row. Defaults to identity. */
	formatLabel?: ( label: string ) => string;
}

const W = 600;
const H = 160;
const PAD = 8;

const LineChart = ( { points, series, formatLabel = ( l: string ) => l }: LineChartProps ) => {
	const [ active, setActive ] = useState< number | null >( null );

	const allSeries: LineSeries[] = series && series.length ? series : [ { name: '', points: points ?? [] } ];
	const base = allSeries[ 0 ].points;

	if ( ! base || base.length === 0 ) {
		return <p className="newspack-insights__chart-empty">{ __( 'No data in this timeframe.', 'newspack-plugin' ) }</p>;
	}

	const isMulti = allSeries.length > 1;
	const n = base.length;
	const allValues = allSeries.flatMap( s => s.points.map( p => p.value || 0 ) );
	const max = Math.max( ...allValues, 1 );
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
						{ __( 'peak', 'newspack-plugin' ) }: { formatNumber( max ) }
					</span>
					<span>{ formatLabel( base[ n - 1 ].label ) }</span>
				</div>
			) }
		</div>
	);
};

export default LineChart;
