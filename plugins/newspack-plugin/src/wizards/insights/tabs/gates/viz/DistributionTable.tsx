/**
 * Tab-local Distribution table viz (NPPD-1604, Phase 1).
 *
 * Bucket distribution table used inside Tab 4 only. Mirrors the
 * pattern the canonical Table component will use when it lands in
 * `packages/components/src/`, so swap-in later is mechanical.
 *
 * Phase 1 behavior: every bucket renders 0 / 0% in the standard
 * table chrome; the section caption below the table explains the
 * cohort definition regardless of phase.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { GatesDistributionData } from '../../../api/gates';
import { formatNumber, formatPercent } from '../../components/format';

export interface DistributionTableProps {
	data: GatesDistributionData;
}

const DistributionTable = ( { data }: DistributionTableProps ) => (
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
					{ data.buckets.map( bucket => (
						<tr key={ bucket.label }>
							<td>{ bucket.label }</td>
							<td className="newspack-insights__table-num">{ formatNumber( bucket.count ) }</td>
							<td className="newspack-insights__table-num">{ formatPercent( bucket.pct ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
		<p className="newspack-insights__distribution-caption">
			{ __( 'Of readers who converted, this is how many gates they saw first.', 'newspack-plugin' ) }
		</p>
	</div>
);

export default DistributionTable;
