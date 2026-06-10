/**
 * MetricTable (NPPD-1649).
 *
 * Renders a rows-shaped metric payload (`type: 'table'`) using the canonical
 * Insights table chrome (`.newspack-insights__table-wrap` + `.newspack-insights__table`
 * from sections.scss), and routes every graceful-failure state through the
 * shared MetricNote / section-empty treatments. Hidden-in-v1 payloads are
 * skipped by the caller.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { formatCurrency, formatDecimal, formatDuration, formatNumber, formatPercent } from './format';
import MetricNote from './MetricNote';
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
				return formatCurrency( value );
			default:
				return formatNumber( value );
		}
	}
	return String( value );
};

const MetricTable = ( { payload, columns, emptyMessage, rowLimit = 10, collapseColumn, defaultRowLimit, expandable = false }: MetricTableProps ) => {
	// Hook must run unconditionally, before any early return.
	const [ expanded, setExpanded ] = useState( false );

	// A degraded payload also carries an overlay object, but it's an informational
	// note over a still-valid table (rendered below with an inline note) — not a
	// replacement state. Only the non-degraded overlay (missing custom dimension)
	// short-circuits to the overlay note.
	if ( payload?.overlay && ! payload.degraded ) {
		return <MetricNote overlay={ payload.overlay } />;
	}
	if ( payload?.error ) {
		return <MetricNote error />;
	}
	if ( payload?.not_configured ) {
		return <MetricNote notConfigured />;
	}

	const rows: MetricRow[] = payload && Array.isArray( payload.rows ) ? payload.rows.slice( 0, rowLimit ) : [];

	if ( rows.length === 0 ) {
		return <p className="newspack-insights__section-empty">{ emptyMessage }</p>;
	}

	// Collapse to `defaultRowLimit` rows behind a toggle when there are more.
	const collapsible = expandable && typeof defaultRowLimit === 'number' && rows.length > defaultRowLimit;
	const visibleRows = collapsible && ! expanded ? rows.slice( 0, defaultRowLimit ) : rows;

	// Hide a uniform column (e.g. country) — the consumer surfaces the value as a
	// scope pill next to the title. Computed over the full set so the column set
	// stays stable when expanding.
	const collapsedValue = collapseColumn ? uniformValue( rows, collapseColumn ) : null;
	const displayColumns = collapsedValue !== null ? columns.filter( col => col.key !== collapseColumn ) : columns;

	const numClass = ( col: MetricTableColumn ) => ( col.align === 'right' ? 'newspack-insights__table-num' : undefined );

	return (
		<>
			{ payload?.degraded && (
				<p className="newspack-insights__metric-note">
					<span className="newspack-insights__metric-note-icon" aria-hidden="true">
						&#9432;
					</span>
					<span>{ __( 'Singular content filter unavailable; showing all URLs.', 'newspack-plugin' ) }</span>
				</p>
			) }
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table">
					<thead>
						<tr>
							{ displayColumns.map( col => (
								<th key={ col.key } className={ numClass( col ) }>
									{ col.label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ visibleRows.map( ( row, i ) => (
							<tr key={ i }>
								{ displayColumns.map( col => (
									<td key={ col.key } className={ numClass( col ) }>
										{ formatCell( row[ col.key ] ?? null, col.format ) }
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			{ collapsible && (
				<button
					type="button"
					className="newspack-insights__table-toggle"
					aria-expanded={ expanded }
					onClick={ () => setExpanded( ! expanded ) }
				>
					{ expanded ? __( 'See less', 'newspack-plugin' ) : __( 'See more', 'newspack-plugin' ) }
				</button>
			) }
		</>
	);
};

export default MetricTable;
