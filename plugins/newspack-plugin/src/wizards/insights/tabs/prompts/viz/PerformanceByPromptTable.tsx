/**
 * PerformanceByPromptTable (NPPD-1607, Table 7.1).
 *
 * One row per prompt, sorted by impressions descending by default.
 * Thin wrapper over the tab-local {@see SortableTable}.
 *
 * The four conversion outcomes (registrations, newsletter signups, and
 * Woo-completed donation / subscription conversions — the last two count + rate,
 * per the Gates v1.1 decision, NPPD-1684) are merged into a single, field-driven
 * "Conversions" column: each row stacks only the types it actually drove, so the
 * table no longer carries six mostly-empty per-type columns. The identifying
 * Prompt column is frozen while the metric columns scroll.
 *
 * Phase 1 renders the spec's empty-state row; the sort chrome stays
 * visible so it's identical between phases.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Card } from '../../../../../../packages/components/src';
import type { PromptsPerformanceByPromptRow, PromptsPerformanceByPromptTable as TableData } from '../../../api/prompts';
import { getPostEditUrl } from '../../components/adminLinks';
import { formatNumber, formatPercent } from '../../components/format';
import SortableTable, { NotApplicable, renderRate, type SortableColumn } from '../../components/SortableTable';
import { humanizeTerm } from './humanize';
import { SECTION_ERROR_MESSAGE } from '../SectionState';

export interface PerformanceByPromptTableProps {
	data: TableData;
}

/**
 * The conversion outcomes a prompt can drive, in a fixed display order. A prompt
 * can legitimately drive more than one (a register-then-pay membership prompt
 * logs both `registrations` and `subscription_conversions`, because the paid
 * subscription is attributed back to the popup by id regardless of the prompt's
 * `action_type` — see class-prompts-metric.php), so the Conversions cell stacks
 * every type that actually fired rather than picking one by intent. `count` and
 * (where it exists) `rate` are read straight off the row; `null`/`0` counts are
 * treated as "did not fire" and omitted. Registration and newsletter signups
 * carry no rate; donation and subscription do.
 */
const CONVERSION_TYPES: {
	key: string;
	label: string;
	count: ( row: PromptsPerformanceByPromptRow ) => number | null;
	rate?: ( row: PromptsPerformanceByPromptRow ) => number | null;
}[] = [
	{ key: 'registrations', label: __( 'Registrations', 'newspack-plugin' ), count: r => r.registrations },
	{ key: 'newsletter', label: __( 'Newsletter', 'newspack-plugin' ), count: r => r.newsletter_signups },
	{ key: 'donation', label: __( 'Donation', 'newspack-plugin' ), count: r => r.donation_conversions, rate: r => r.donation_conversion_rate },
	{
		key: 'subscription',
		label: __( 'Subscription', 'newspack-plugin' ),
		count: r => r.subscription_conversions,
		rate: r => r.subscription_conversion_rate,
	},
];

/** Sum of every conversion a prompt drove — the sort key for the merged column. */
const conversionTotal = ( row: PromptsPerformanceByPromptRow ): number =>
	CONVERSION_TYPES.reduce( ( sum, type ) => sum + ( type.count( row ) ?? 0 ), 0 );

/**
 * Stacked, field-driven Conversions cell: one line per conversion type the
 * prompt actually drove (count, plus rate in parens where applicable). Renders a
 * muted em-dash when the prompt converted nobody.
 */
const renderConversions = ( row: PromptsPerformanceByPromptRow ): React.ReactNode => {
	const lines = CONVERSION_TYPES.map( type => ( { type, count: type.count( row ) } ) )
		.filter( ( { count } ) => typeof count === 'number' && count > 0 )
		.map( ( { type, count } ) => {
			const rate = type.rate ? type.rate( row ) : null;
			return (
				<div className="newspack-insights__conversion-line" key={ type.key }>
					<span className="newspack-insights__conversion-label">{ type.label }</span> { formatNumber( count as number ) }
					{ rate !== null && rate !== undefined && (
						<span className="newspack-insights__conversion-rate"> ({ formatPercent( rate ) })</span>
					) }
				</div>
			);
		} );
	// Wrap in one block container: the DataViews cell content wrapper is a flex
	// row, so bare sibling lines would lay out horizontally — the container is a
	// single flex item whose children stack vertically in normal flow.
	return lines.length > 0 ? <div className="newspack-insights__conversions">{ lines }</div> : <NotApplicable />;
};

const columns: SortableColumn< PromptsPerformanceByPromptRow >[] = [
	{
		key: 'prompt_title',
		label: __( 'Prompt', 'newspack-plugin' ),
		numeric: false,
		render: r => <ExternalLink href={ getPostEditUrl( r.popup_id ) }>{ r.prompt_title }</ExternalLink>,
		sortValue: r => r.prompt_title,
	},
	{
		key: 'intent',
		label: __( 'Intent', 'newspack-plugin' ),
		numeric: false,
		render: r => r.intent_label || humanizeTerm( r.intent ),
		sortValue: r => r.intent_label || r.intent,
	},
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
		// Merged, field-driven column: replaces the six per-type conversion
		// columns (registrations / newsletter / donation count+rate / subscription
		// count+rate), which left most cells empty on any given row since a prompt
		// only drives the conversion types it's built for. `numeric: false` because
		// the cell holds labeled, sometimes-stacked lines, not a single figure; it
		// still sorts, by total conversions driven.
		key: 'conversions',
		label: __( 'Conversions', 'newspack-plugin' ),
		numeric: false,
		render: renderConversions,
		sortValue: r => conversionTotal( r ),
	},
];

const PerformanceByPromptTable = ( { data }: PerformanceByPromptTableProps ) => (
	<Card __experimentalCoreCard className="newspack-insights__chart-card">
		<h3 className="newspack-insights__chart-card-title">{ __( 'Performance by prompt', 'newspack-plugin' ) }</h3>
		<SortableTable
			columns={ columns }
			rows={ data.rows }
			getRowKey={ row => row.popup_id }
			defaultSortKey="impressions"
			initialRowLimit={ 10 }
			stickyFirstColumn
			emptyMessage={ __(
				'No prompt data yet. Performance metrics will appear once readers begin interacting with your prompts.',
				'newspack-plugin'
			) }
			errorMessage={ 'error' === data.state ? SECTION_ERROR_MESSAGE : undefined }
		/>
		<p className="newspack-insights__table-footnote">
			{ __(
				'Showing the top 10 prompts by the sorted column; use “See more” to reveal the rest. Capped at the top 50 prompts by impressions — lower-traffic prompts beyond that may not appear.',
				'newspack-plugin'
			) }
		</p>
	</Card>
);

export default PerformanceByPromptTable;
