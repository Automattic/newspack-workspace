/**
 * CohortHeatmap — cohort retention/conversion as a shaded grid (Conversion tab viz cleanup).
 *
 * Replaces the many-series cohort LineChart (unreadable "spaghetti" past a handful of
 * cohorts) with the standard cohort-matrix view: one row per cohort, one column per
 * period-since-start, each cell shaded by its value. Reading DOWN a column compares
 * cohorts at the same age (the core cohort question); reading ACROSS a row ages a
 * single cohort. The upper-right blanks are cohorts too young to have reached that
 * period yet.
 *
 * Color is scaled to the visible value range (relative shading) so both a high-
 * magnitude series (retention, ~60–100%) and a low-magnitude one (early conversion,
 * a few %) read with contrast; the exact value is printed in every cell so magnitude
 * stays honest. Dependency-free.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatPercent } from './format';

export interface CohortHeatmapRow {
	label: string;
	points: { period: number; value: number }[];
}

export interface CohortHeatmapProps {
	cohorts: CohortHeatmapRow[];
	/** Format a cell value. Defaults to `formatPercent` (values are 0–1 shares). */
	formatValue?: ( value: number ) => string;
	/** Column header for a period index. Defaults to the raw period number. */
	formatPeriod?: ( period: number ) => string;
	/** Caption naming what the columns are (e.g. "Months since cohort start"). */
	columnsLabel?: string;
	/** Optional target callout shown under the grid (e.g. "70% at 12 months"). */
	referenceLabel?: string;
	/** Empty-state copy. */
	emptyMessage?: string;
}

// The sequential ramp keys off the admin accent: each cell mixes
// `--wp-admin-theme-color` with white by its normalized value `t` (in [0, 1])
// via color-mix in the stylesheet. Cells expose `t` as the `--cell-t` custom
// property and dark cells flip to white text.
const cellStyle = ( t: number ): React.CSSProperties =>
	( { '--cell-t': t, color: t > 0.55 ? '#fff' : 'var(--wp-admin-theme-color)' } ) as React.CSSProperties;

const CohortHeatmap = ( {
	cohorts,
	formatValue = formatPercent,
	formatPeriod = ( period: number ) => String( period ),
	columnsLabel,
	referenceLabel,
	emptyMessage,
}: CohortHeatmapProps ) => {
	const rows = cohorts.filter( c => c.points.length > 0 );
	if ( ! rows.length ) {
		return <p className="newspack-insights__chart-empty">{ emptyMessage ?? __( 'No cohort data available yet.', 'newspack-plugin' ) }</p>;
	}

	// Columns = the sorted union of every period present across cohorts.
	const periods = Array.from( new Set( rows.flatMap( c => c.points.map( p => p.period ) ) ) ).sort( ( a, b ) => a - b );

	// Value range drives the relative shading; the printed value keeps magnitude honest.
	const values = rows.flatMap( c => c.points.map( p => p.value ) );
	const min = Math.min( ...values );
	const span = Math.max( ...values ) - min || 1;

	return (
		<div className="newspack-insights__cohort-heatmap">
			<table className="newspack-insights__cohort-heatmap-table">
				{ columnsLabel && <caption>{ columnsLabel }</caption> }
				<thead>
					<tr>
						<th className="newspack-insights__cohort-heatmap-corner" scope="col">
							{ __( 'Cohort', 'newspack-plugin' ) }
						</th>
						{ periods.map( period => (
							<th key={ `col-${ period }` } className="newspack-insights__cohort-heatmap-colhead" scope="col">
								{ formatPeriod( period ) }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( cohort => {
						const lookup = new Map( cohort.points.map( p => [ p.period, p.value ] ) );
						return (
							<tr key={ cohort.label }>
								<th className="newspack-insights__cohort-heatmap-rowhead" scope="row">
									{ cohort.label }
								</th>
								{ periods.map( period => {
									const value = lookup.get( period );
									if ( undefined === value ) {
										return (
											<td key={ `${ cohort.label }-${ period }` } className="newspack-insights__cohort-heatmap-cell is-empty" />
										);
									}
									const t = ( value - min ) / span;
									return (
										<td
											key={ `${ cohort.label }-${ period }` }
											className="newspack-insights__cohort-heatmap-cell"
											style={ cellStyle( t ) }
										>
											{ formatValue( value ) }
										</td>
									);
								} ) }
							</tr>
						);
					} ) }
				</tbody>
			</table>
			<div className="newspack-insights__cohort-heatmap-footer">
				<span className="newspack-insights__cohort-heatmap-scale">
					{ __( 'Lower', 'newspack-plugin' ) }
					<span className="newspack-insights__cohort-heatmap-swatches" aria-hidden="true">
						{ [ 0, 0.25, 0.5, 0.75, 1 ].map( t => (
							<span key={ `sw-${ t }` } style={ cellStyle( t ) } />
						) ) }
					</span>
					{ __( 'Higher', 'newspack-plugin' ) }
				</span>
				{ referenceLabel && (
					<span className="newspack-insights__cohort-heatmap-target">
						{ __( 'Target', 'newspack-plugin' ) }: { referenceLabel }
					</span>
				) }
			</div>
		</div>
	);
};

export default CohortHeatmap;
