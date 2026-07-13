/**
 * BarChart (NPPD-1649) — shared vertical bar chart.
 *
 * Dependency-free SVG. Used for categorical breakdowns (readership by day of
 * week, by hour of day). Bars scale to the max value; labels render beneath.
 */

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber } from './format';

export interface Bar {
	label: string;
	value: number;
}

export interface BarChartProps {
	bars: Bar[];
	/** Render the hover value, e.g. "98 seconds". Defaults to a plain number. */
	formatValue?: ( value: number ) => string;
}

const BarChart = ( { bars, formatValue = formatNumber }: BarChartProps ) => {
	if ( bars.length === 0 ) {
		return <p className="newspack-insights__chart-empty">{ __( 'No data in this timeframe.', 'newspack-plugin' ) }</p>;
	}
	const max = Math.max( ...bars.map( b => b.value || 0 ), 1 );

	// Colour encodes magnitude only for small categorical sets (<= 3 bars): the
	// tallest takes the primary series colour, each shorter steps down the scale
	// (8 shades, cycling to match PieChart). Larger sets (day-of-week, hour-of-day)
	// stay a single series colour so the scale doesn't read as noise.
	const seriesByIndex: number[] = bars.map( () => 0 );
	if ( bars.length <= 3 ) {
		bars.map( ( bar, index ) => ( { index, value: bar.value || 0 } ) )
			.sort( ( a, b ) => b.value - a.value )
			.forEach( ( item, rank ) => {
				seriesByIndex[ item.index ] = rank % 8;
			} );
	}

	return (
		<div
			className={ classnames( 'newspack-insights__bars', { 'is-dense': bars.length > 3 } ) }
			role="img"
			aria-label={ __( 'Bar chart', 'newspack-plugin' ) }
		>
			{ bars.map( ( bar, index ) => (
				<div className="newspack-insights__bar-col" key={ bar.label }>
					{ /* Dark hover panel (NPPD-1649 fix #5), shown on column hover via CSS. */ }
					<div className="newspack-insights__chart-tooltip newspack-insights__chart-tooltip--bar">
						<span className="newspack-insights__chart-tooltip-label">{ bar.label }</span>
						<span className="newspack-insights__chart-tooltip-value">{ formatValue( bar.value ) }</span>
					</div>
					<div className="newspack-insights__bar-track">
						<div
							className={ `newspack-insights__bar-fill is-series-${ seriesByIndex[ index ] }` }
							style={ { height: `${ Math.round( ( ( bar.value || 0 ) / max ) * 100 ) }%` } }
						/>
					</div>
					<div className="newspack-insights__bar-label">{ bar.label }</div>
				</div>
			) ) }
		</div>
	);
};

export default BarChart;
