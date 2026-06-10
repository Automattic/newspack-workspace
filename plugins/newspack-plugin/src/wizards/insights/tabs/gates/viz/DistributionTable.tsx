/**
 * Tab-local Distribution table viz (NPPD-1604).
 *
 * Bucket distribution table used inside Tab 4 only. The parent section owns the
 * error / empty / populated treatment (via SectionState); this component just
 * renders the populated buckets.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { GatesDistributionBucket } from '../../../api/gates';
import { formatNumber, formatPercent } from '../../components/format';

export interface DistributionTableProps {
	buckets: GatesDistributionBucket[];
}

const DistributionTable = ( { buckets }: DistributionTableProps ) => (
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
		<p className="newspack-insights__distribution-caption">
			{ __( 'Of readers who converted, this is how many gates they saw first.', 'newspack-plugin' ) }
		</p>
	</div>
);

export default DistributionTable;
