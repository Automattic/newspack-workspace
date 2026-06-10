/**
 * Tab-local Distribution table viz (NPPD-1607, Phase 1).
 *
 * Bucket distribution table used inside Tab 5 only. A tab-local copy
 * of the Gates DistributionTable; reuses the shared table chrome
 * (`__table-wrap` / `__table`) and adds the tab-local
 * `__prompts-distribution` wrapper + caption.
 *
 * Phase 1 behavior: every bucket renders 0 / 0% in the standard table
 * chrome; the caption below the table explains the cohort definition
 * regardless of phase.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsDistributionData } from '../../../api/prompts';
import { formatNumber, formatPercent } from '../../components/format';

export interface DistributionTableProps {
	data: PromptsDistributionData;
}

const DistributionTable = ( { data }: DistributionTableProps ) => (
	<div className="newspack-insights__prompts-distribution">
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
		<p className="newspack-insights__prompts-distribution-caption">
			{ __( 'Of readers who converted, this is how many prompts they saw first.', 'newspack-plugin' ) }
		</p>
	</div>
);

export default DistributionTable;
