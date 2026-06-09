/**
 * BarChart (NPPD-1649) — tab-local vertical bar chart.
 *
 * Dependency-free SVG. Used for categorical breakdowns (readership by day of
 * week, by hour of day). Bars scale to the max value; labels render beneath.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber } from '../../components/format';

export interface Bar {
	label: string;
	value: number;
}

export interface BarChartProps {
	bars: Bar[];
}

const BarChart = ( { bars }: BarChartProps ) => {
	if ( bars.length === 0 ) {
		return <p className="newspack-insights__chart-empty">{ __( 'No data in this timeframe.', 'newspack-plugin' ) }</p>;
	}
	const max = Math.max( ...bars.map( b => b.value || 0 ), 1 );

	return (
		<div className="newspack-insights__bars" role="img" aria-label={ __( 'Bar chart', 'newspack-plugin' ) }>
			{ bars.map( bar => (
				<div className="newspack-insights__bar-col" key={ bar.label }>
					{ /* Dark hover panel (NPPD-1649 fix #5), shown on column hover via CSS. */ }
					<div className="newspack-insights__chart-tooltip newspack-insights__chart-tooltip--bar">
						<span className="newspack-insights__chart-tooltip-label">{ bar.label }</span>
						<span className="newspack-insights__chart-tooltip-value">{ formatNumber( bar.value ) }</span>
					</div>
					<div className="newspack-insights__bar-track">
						<div
							className="newspack-insights__bar-fill is-series-0"
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
