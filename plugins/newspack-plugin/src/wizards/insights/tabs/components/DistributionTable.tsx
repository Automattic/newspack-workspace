/**
 * Shared DistributionTable component (Task 6; DataViews migration NPPD-1889).
 *
 * Bucket distribution table used by the Gates tab (Tab 4) and the Prompts
 * tab (Tab 5) — an identical 3-column table (exposures / converters / % of
 * total) with an optional caption below. Now renders through the shared
 * read-only DataViews table (`InsightsDataView`); the public props
 * (`buckets`, `caption`) are unchanged. Columns are intentionally unsortable
 * so the exposure-bucket order stays meaningful.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber, formatPercent } from './format';
import InsightsDataView from './InsightsDataView';
import type { InsightsColumn } from './InsightsDataView';

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

const DistributionTable = ( { buckets, caption }: DistributionTableProps ) => {
	const columns: InsightsColumn< DistributionBucket >[] = [
		{
			key: 'label',
			label: __( 'Exposures before conversion', 'newspack-plugin' ),
			render: bucket => bucket.label,
		},
		{
			key: 'count',
			label: __( 'Converters', 'newspack-plugin' ),
			numeric: true,
			render: bucket => formatNumber( bucket.count ),
		},
		{
			key: 'pct',
			label: __( '% of total', 'newspack-plugin' ),
			numeric: true,
			render: bucket => formatPercent( bucket.pct ),
		},
	];

	return (
		<div className="newspack-insights__distribution">
			<InsightsDataView< DistributionBucket >
				columns={ columns }
				rows={ buckets }
				getRowKey={ bucket => bucket.label }
				emptyMessage={ __( 'No conversion distribution data in this timeframe.', 'newspack-plugin' ) }
			/>
			{ caption && <p className="newspack-insights__distribution-caption">{ caption }</p> }
		</div>
	);
};

export default DistributionTable;
