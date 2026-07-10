/**
 * MetricTable (NPPD-1649, DataViews migration NPPD-1889).
 *
 * Renders a rows-shaped metric payload (`type: 'table'`) through the shared
 * read-only DataViews table (`InsightsDataView`), routing every graceful-failure
 * state through the shared MetricNote / section-empty treatments. The public
 * props are unchanged, so the ~9 consumers across the Advertising, App,
 * Audience, Engagement and Newsletter Ads tabs need no edits — only the table
 * rendering moved to DataViews.
 */

/**
 * Internal dependencies
 */
import { formatCurrency, formatDecimal, formatDuration, formatNumber, formatPercent } from './format';
import InsightsDataView from './InsightsDataView';
import type { InsightsColumn } from './InsightsDataView';
import { uniformValue } from './metrics';
import type { MetricPayload, MetricRow } from './metrics';

export interface MetricTableColumn {
	key: string;
	label: string;
	/** How to format a numeric cell. Omit for plain strings. */
	format?: 'number' | 'percent' | 'decimal' | 'duration' | 'currency';
	align?: 'left' | 'right';
}

export interface MetricTableProps {
	payload?: MetricPayload;
	columns: MetricTableColumn[];
	emptyMessage: string;
	rowLimit?: number;
	/**
	 * Key of a column to hide when every displayed row shares the same
	 * meaningful value (e.g. "country"). The consumer renders the scope label
	 * (see ScopePill) next to the title; this just drops the redundant column.
	 * Unset / empty / "(not set)" values never collapse, so data-quality gaps
	 * stay visible.
	 */
	collapseColumn?: string;
	/**
	 * When `expandable`, the number of rows shown collapsed. If the table has
	 * more rows than this (up to `rowLimit`), a "See more"/"See less" toggle is
	 * rendered. Collapsed state is per-render (not persisted).
	 */
	defaultRowLimit?: number;
	/** Enable the collapse/expand toggle. Requires `defaultRowLimit`. */
	expandable?: boolean;
}

const formatCell = ( value: string | number | null, format?: MetricTableColumn[ 'format' ] ): string => {
	if ( value === null || value === undefined ) {
		return '—';
	}
	if ( format && typeof value === 'number' ) {
		switch ( format ) {
			case 'percent':
				return formatPercent( value );
			case 'decimal':
				return formatDecimal( value );
			case 'duration':
				return formatDuration( value );
			case 'currency':
				return formatCurrency( value ).display;
			default:
				return formatNumber( value );
		}
	}
	return String( value );
};

const MetricTable = ( { payload, columns, emptyMessage, rowLimit = 10, collapseColumn, defaultRowLimit, expandable = false }: MetricTableProps ) => {
	const rows: MetricRow[] = payload && Array.isArray( payload.rows ) ? payload.rows.slice( 0, rowLimit ) : [];

	// Hide a uniform column (e.g. country) — the consumer surfaces the value as a
	// scope pill next to the title. Computed over the full displayed set so the
	// column set stays stable when expanding.
	const collapsedValue = collapseColumn ? uniformValue( rows, collapseColumn ) : null;
	const displayColumns = collapsedValue !== null ? columns.filter( col => col.key !== collapseColumn ) : columns;

	const dataViewColumns: InsightsColumn< MetricRow >[] = displayColumns.map( col => ( {
		key: col.key,
		label: col.label,
		numeric: col.align === 'right',
		render: ( row: MetricRow ) => formatCell( row[ col.key ] ?? null, col.format ),
		sortValue: ( row: MetricRow ) => row[ col.key ] ?? null,
	} ) );

	return (
		<InsightsDataView< MetricRow >
			columns={ dataViewColumns }
			rows={ rows }
			getRowKey={ ( _row, index ) => String( index ) }
			emptyMessage={ emptyMessage }
			overlay={ payload?.overlay }
			error={ Boolean( payload?.error ) }
			notConfigured={ payload?.not_configured }
			expandable={ expandable }
			defaultRowLimit={ defaultRowLimit }
		/>
	);
};

export default MetricTable;
