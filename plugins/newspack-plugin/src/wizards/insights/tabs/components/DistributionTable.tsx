/**
 * Shared DistributionTable component (Task 6, viz-consolidation).
 *
 * Bucket distribution table used by the Gates tab (Tab 4) and the Prompts
 * tab (Tab 5). Both tabs render an identical 3-column table
 * (label / count / % of total) with an optional caption below.
 *
 * Unified from tab-local copies in `tabs/gates/viz/DistributionTable.tsx`
 * and `tabs/prompts/viz/DistributionTable.tsx`. The only real differences
 * between the two were the prop wrapping (`buckets` vs `data.buckets`),
 * the CSS wrapper class, and the caption string — all resolved here by
 * accepting a flat `buckets` array + optional `caption` and using the
 * canonical `newspack-insights__distribution*` class namespace.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber, formatPercent } from './format';

export interface DistributionBucket {
	label: string;
	count: number;
	pct: number;
}

export interface DistributionTableProps {
	buckets: DistributionBucket[];
	/** Optional explanatory text rendered below the table. */
	caption?: string;
}

const DistributionTable = ( { buckets, caption }: DistributionTableProps ) => (
	<div className="newspack-insights__distribution">
		<div className="newspack-insights__table-wrap">
			<table className="newspack-insights__table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Exposures before conversion', 'newspack-plugin' ) }</th>
						<th scope="col" className="newspack-insights__table-num">
							{ __( 'Converters', 'newspack-plugin' ) }
						</th>
						<th scope="col" className="newspack-insights__table-num">
							{ __( '% of total', 'newspack-plugin' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ buckets.map( bucket => (
						<tr key={ bucket.label }>
							<td>{ bucket.label }</td>
							<td className="newspack-insights__table-num">{ formatNumber( bucket.count ) }</td>
							<td className="newspack-insights__table-num">{ formatPercent( bucket.pct ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
		{ caption && <p className="newspack-insights__distribution-caption">{ caption }</p> }
	</div>
);

export default DistributionTable;
