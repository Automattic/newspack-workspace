/**
 * PerformanceByPromptTable (NPPD-1607, Table 7.1).
 *
 * One row per prompt, sorted by impressions descending by default.
 * Thin wrapper over the tab-local {@see SortableTable}. Donation and
 * subscription columns report *conversions* (Woo-completed outcomes) —
 * count + rate for each — matching the Gates v1.1 decision (NPPD-1684).
 * Count and rate cells render a muted em-dash for non-applicable
 * prompts (e.g. donation conversions on a registration prompt),
 * distinct from a real 0 / 0%.
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
import { formatNumber } from '../../components/format';
import SortableTable, { renderCount, renderRate, type SortableColumn } from './SortableTable';
import { humanizeTerm } from './humanize';

export interface PerformanceByPromptTableProps {
	data: TableData;
}

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
		key: 'donation_conversions',
		label: __( 'Donation conversions', 'newspack-plugin' ),
		numeric: true,
		render: r => renderCount( r.donation_conversions ),
		sortValue: r => r.donation_conversions,
	},
	{
		key: 'donation_conversion_rate',
		label: __( 'Donation conversion rate', 'newspack-plugin' ),
		numeric: true,
		render: r => renderRate( r.donation_conversion_rate ),
		sortValue: r => r.donation_conversion_rate,
	},
	{
		key: 'subscription_conversions',
		label: __( 'Subscription conversions', 'newspack-plugin' ),
		numeric: true,
		render: r => renderCount( r.subscription_conversions ),
		sortValue: r => r.subscription_conversions,
	},
	{
		key: 'subscription_conversion_rate',
		label: __( 'Subscription conversion rate', 'newspack-plugin' ),
		numeric: true,
		render: r => renderRate( r.subscription_conversion_rate ),
		sortValue: r => r.subscription_conversion_rate,
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
			initialRowLimit={ 10 }
			emptyMessage={ __(
				'No prompt data yet. Performance metrics will appear once readers begin interacting with your prompts.',
				'newspack-plugin'
			) }
		/>
		<p className="newspack-insights__prompts-subsection-note">
			{ __(
				'Showing the top 10 prompts by the sorted column; use “See more” to reveal the rest. Capped at the top 50 prompts by impressions — lower-traffic prompts beyond that may not appear.',
				'newspack-plugin'
			) }
		</p>
	</div>
);

export default PerformanceByPromptTable;
