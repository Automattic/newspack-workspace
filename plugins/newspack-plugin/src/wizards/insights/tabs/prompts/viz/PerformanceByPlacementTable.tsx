/**
 * PerformanceByPlacementTable (NPPD-1607, Table 7.3).
 *
 * One row per placement (overlay / inline / above-header / etc.),
 * aggregated across all prompts at that placement. Thin wrapper over
 * the tab-local {@see SortableTable}, sorted by impressions descending.
 *
 * Phase 1 renders an empty-state row; the sort chrome stays visible.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsPerformanceByPlacementRow, PromptsPerformanceByPlacementTable as TableData } from '../../../api/prompts';
import { formatNumber } from '../../components/format';
import SortableTable, { renderRate, type SortableColumn } from './SortableTable';
import { humanizeTerm } from './humanize';
import { SECTION_ERROR_MESSAGE } from '../SectionState';

export interface PerformanceByPlacementTableProps {
	data: TableData;
}

const columns: SortableColumn< PromptsPerformanceByPlacementRow >[] = [
	{
		key: 'placement',
		label: __( 'Placement', 'newspack-plugin' ),
		numeric: false,
		render: r => humanizeTerm( r.placement ),
		sortValue: r => r.placement,
	},
	{
		key: 'impressions',
		label: __( 'Impressions', 'newspack-plugin' ),
		numeric: true,
		render: r => formatNumber( r.impressions ),
		sortValue: r => r.impressions,
	},
	{
		key: 'unique_viewers',
		label: __( 'Unique viewers', 'newspack-plugin' ),
		numeric: true,
		render: r => formatNumber( r.unique_viewers ),
		sortValue: r => r.unique_viewers,
	},
	{ key: 'ctr', label: __( 'CTR', 'newspack-plugin' ), numeric: true, render: r => renderRate( r.ctr ), sortValue: r => r.ctr },
	{
		key: 'dismissal_rate',
		label: __( 'Dismissal rate', 'newspack-plugin' ),
		numeric: true,
		render: r => renderRate( r.dismissal_rate ),
		sortValue: r => r.dismissal_rate,
	},
];

const PerformanceByPlacementTable = ( { data }: PerformanceByPlacementTableProps ) => (
	<div className="newspack-insights__prompts-subsection">
		<h3 className="newspack-insights__prompts-subsection-heading">{ __( 'Performance by prompt placement', 'newspack-plugin' ) }</h3>
		<SortableTable
			columns={ columns }
			rows={ data.rows }
			getRowKey={ row => row.placement }
			defaultSortKey="impressions"
			emptyMessage={ __(
				'No prompt data yet. Placement performance will appear once readers begin interacting with your prompts.',
				'newspack-plugin'
			) }
			errorMessage={ 'error' === data.state ? SECTION_ERROR_MESSAGE : undefined }
		/>
		<p className="newspack-insights__prompts-subsection-note">
			{ __( "Answers 'do my overlay prompts perform better than inline?' Useful for choosing placement defaults.", 'newspack-plugin' ) }
		</p>
	</div>
);

export default PerformanceByPlacementTable;
