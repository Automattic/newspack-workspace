/**
 * PerformanceByIntentTable (NPPD-1607, Table 7.2).
 *
 * One row per intent (donation / registration / newsletter signup),
 * aggregated across all prompts of that intent. Thin wrapper over the
 * tab-local {@see SortableTable}, sorted by impressions descending.
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
import type { PromptsPerformanceByIntentRow, PromptsPerformanceByIntentTable as TableData } from '../../../api/prompts';
import { formatNumber } from '../../components/format';
import SortableTable, { renderRate, type SortableColumn } from './SortableTable';
import { humanizeTerm } from './humanize';
import { SECTION_ERROR_MESSAGE } from '../SectionState';

export interface PerformanceByIntentTableProps {
	data: TableData;
}

const columns: SortableColumn< PromptsPerformanceByIntentRow >[] = [
	{ key: 'intent', label: __( 'Intent', 'newspack-plugin' ), numeric: false, render: r => humanizeTerm( r.intent ), sortValue: r => r.intent },
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
		key: 'form_submission_rate',
		label: __( 'Form submission rate', 'newspack-plugin' ),
		numeric: true,
		render: r => renderRate( r.form_submission_rate ),
		sortValue: r => r.form_submission_rate,
	},
	{
		key: 'dismissal_rate',
		label: __( 'Dismissal rate', 'newspack-plugin' ),
		numeric: true,
		render: r => renderRate( r.dismissal_rate ),
		sortValue: r => r.dismissal_rate,
	},
];

const PerformanceByIntentTable = ( { data }: PerformanceByIntentTableProps ) => (
	<div className="newspack-insights__prompts-subsection">
		<h3 className="newspack-insights__prompts-subsection-heading">{ __( 'Performance by prompt intent', 'newspack-plugin' ) }</h3>
		<SortableTable
			columns={ columns }
			rows={ data.rows }
			getRowKey={ row => row.intent }
			defaultSortKey="impressions"
			emptyMessage={ __(
				'No prompt data yet. Intent performance will appear once readers begin interacting with your prompts.',
				'newspack-plugin'
			) }
			errorMessage={ 'error' === data.state ? SECTION_ERROR_MESSAGE : undefined }
		/>
		<p className="newspack-insights__prompts-subsection-note">
			{ __( "Answers 'are my donation prompts working better than my registration prompts?' at a glance.", 'newspack-plugin' ) }
		</p>
	</div>
);

export default PerformanceByIntentTable;
