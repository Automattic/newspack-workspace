/**
 * PerformanceByPromptTable (NPPD-1607, Table 7.1).
 *
 * One row per prompt, sorted by impressions descending by default.
 * Thin wrapper over the tab-local {@see SortableTable}. Donation and
 * subscription columns show *attempts* in v1 (completions are a v1.1
 * candidate via the Woo join). Rate cells render an em-dash for
 * non-applicable prompts (e.g. CTR on a button-less prompt), distinct
 * from a real 0%.
 *
 * Phase 1 renders the spec's empty-state row; the sort chrome stays
 * visible so it's identical between phases.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsPerformanceByPromptRow, PromptsPerformanceByPromptTable as TableData } from '../../../api/prompts';
import { formatNumber, formatPercent } from '../../components/format';
import SortableTable, { NotApplicable, type SortableColumn } from './SortableTable';
import { humanizeTerm } from './humanize';

export interface PerformanceByPromptTableProps {
	data: TableData;
}

const renderRate = ( v: number | null ) => ( v === null ? <NotApplicable /> : <>{ formatPercent( v ) }</> );

const columns: SortableColumn< PromptsPerformanceByPromptRow >[] = [
	{ key: 'prompt_title', label: __( 'Prompt', 'newspack-plugin' ), numeric: false, render: r => r.prompt_title, sortValue: r => r.prompt_title },
	{ key: 'intent', label: __( 'Intent', 'newspack-plugin' ), numeric: false, render: r => humanizeTerm( r.intent ), sortValue: r => r.intent },
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
	{
		key: 'registrations',
		label: __( 'Registrations', 'newspack-plugin' ),
		numeric: true,
		render: r => formatNumber( r.registrations ),
		sortValue: r => r.registrations,
	},
	{
		key: 'newsletter_signups',
		label: __( 'Newsletter signups', 'newspack-plugin' ),
		numeric: true,
		render: r => formatNumber( r.newsletter_signups ),
		sortValue: r => r.newsletter_signups,
	},
	{
		key: 'donation_attempts',
		label: __( 'Donation attempts', 'newspack-plugin' ),
		numeric: true,
		render: r => formatNumber( r.donation_attempts ),
		sortValue: r => r.donation_attempts,
	},
	{
		key: 'subscription_attempts',
		label: __( 'Subscription attempts', 'newspack-plugin' ),
		numeric: true,
		render: r => formatNumber( r.subscription_attempts ),
		sortValue: r => r.subscription_attempts,
	},
];

const PerformanceByPromptTable = ( { data }: PerformanceByPromptTableProps ) => (
	<div className="newspack-insights__prompts-subsection">
		<h3 className="newspack-insights__prompts-subsection-heading">{ __( 'Performance by prompt', 'newspack-plugin' ) }</h3>
		<SortableTable
			columns={ columns }
			rows={ data.rows }
			getRowKey={ row => row.newspack_popup_id }
			defaultSortKey="impressions"
			emptyMessage={ __(
				'No prompt data yet. Performance metrics will appear once readers begin interacting with your prompts.',
				'newspack-plugin'
			) }
		/>
		<p className="newspack-insights__prompts-subsection-note">
			{ __(
				'Showing top 50 prompts by impressions. If you have more than 50 active prompts, lower-traffic prompts may not appear.',
				'newspack-plugin'
			) }
		</p>
	</div>
);

export default PerformanceByPromptTable;
