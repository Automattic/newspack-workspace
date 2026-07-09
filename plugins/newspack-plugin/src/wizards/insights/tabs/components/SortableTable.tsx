/**
 * Shared SortableTable primitive (NPPD-1607/1609; DataViews migration NPPD-1889).
 *
 * Click-to-sort table used by Tab 3 (Conversion Journey) and Tab 5 (Prompts).
 * Now a thin adapter over the shared read-only DataViews table
 * (`InsightsDataView`) so every Insights table shares one treatment; the public
 * props (`SortableColumn`, `getRowKey`, `defaultSortKey`, `errorMessage`,
 * `initialRowLimit`) are unchanged, so the four consumers need no edits.
 *
 * Behavior preserved by the wrapper: numeric columns open DESC / string columns
 * open ASC, click-to-sort toggles direction, null cells sort last, and
 * `initialRowLimit` caps rows behind a "See more" toggle applied after sorting.
 * `errorMessage` (when set) replaces the empty-state copy.
 */

/**
 * Internal dependencies
 */
import { __ } from '@wordpress/i18n';
import { formatNumber, formatPercent } from './format';
import InsightsDataView from './InsightsDataView';

export type SortDir = 'asc' | 'desc';

export interface SortableColumn< Row > {
	/** Stable key; also the default sort target when it matches `defaultSortKey`. */
	key: string;
	label: string;
	numeric: boolean;
	/** Cell content. */
	render: ( row: Row ) => React.ReactNode;
	/** Value used for sorting. `null` always sorts last. */
	sortValue: ( row: Row ) => number | string | null;
}

export interface SortableTableProps< Row > {
	columns: SortableColumn< Row >[];
	rows: Row[];
	getRowKey: ( row: Row ) => string | number;
	defaultSortKey: string;
	emptyMessage: string;
	/**
	 * When set, replaces the empty-state copy with a publisher-friendly error
	 * message. Pass when the table's wrapper envelope reports `state === 'error'`
	 * so a failed query renders the shared error treatment instead of the
	 * neutral "no data yet" copy.
	 */
	errorMessage?: string;
	/**
	 * When set and the table has more rows than this, only the first N (by the
	 * active sort) render, with a "See more" toggle that reveals the rest. The
	 * cap is applied after sorting, so collapsing always shows the current top N.
	 */
	initialRowLimit?: number;
}

function SortableTable< Row >( {
	columns,
	rows,
	getRowKey,
	defaultSortKey,
	emptyMessage,
	errorMessage,
	initialRowLimit,
}: SortableTableProps< Row > ) {
	return (
		<InsightsDataView< Row >
			columns={ columns }
			rows={ rows }
			getRowKey={ row => String( getRowKey( row ) ) }
			defaultSortKey={ defaultSortKey }
			emptyMessage={ errorMessage ?? emptyMessage }
			expandable={ typeof initialRowLimit === 'number' }
			defaultRowLimit={ initialRowLimit }
		/>
	);
}

/** Renders an em-dash for non-applicable numeric cells, distinct from a real zero. */
export const NotApplicable = () => (
	<span className="newspack-insights__table-na" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
		—
	</span>
);

/** Cell renderer: a percentage, or a muted em-dash when the metric is not applicable (null). */
export const renderRate = ( v: number | null ) => ( v === null ? <NotApplicable /> : <>{ formatPercent( v ) }</> );

/** Cell renderer: a formatted count, or a muted em-dash when the metric is not applicable (null). */
export const renderCount = ( v: number | null ) => ( v === null ? <NotApplicable /> : <>{ formatNumber( v ) }</> );

export default SortableTable;
