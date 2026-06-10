/**
 * Tab-local SortableTable primitive (NPPD-1607, Phase 1).
 *
 * Generic click-to-sort table used by the three Performance breakdown
 * tables on Tab 5 (by prompt, by intent, by placement). Factors the
 * sort behavior that the Gates tab inlines in its single
 * PerformanceByGateSection — Tab 5 has three such tables, so a shared
 * primitive is warranted rather than triplicating the logic.
 *
 * Behavior matches the Gates table chrome exactly:
 *   - Click a column header to sort; numeric columns open DESC, string
 *     columns open ASC; clicking the active column toggles direction.
 *   - Null cells (em-dash) always sort to the bottom regardless of
 *     direction — a non-applicable metric should never claim the top
 *     of an ascending sort.
 *   - When `rows` is empty (Phase 1, always), the empty-state row is
 *     shown but the sort affordances stay visible so the chrome is
 *     identical between phases.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { Icon, chevronUp, chevronDown } from '@wordpress/icons';

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
	 * When set and the table has more rows than this, only the first N (by the
	 * active sort) render, with a "See more" toggle that reveals the rest. The
	 * cap is applied after sorting, so collapsing always shows the current top N.
	 */
	initialRowLimit?: number;
}

const ariaSortFor = ( isActive: boolean, activeDir: SortDir ): 'ascending' | 'descending' | 'none' => {
	if ( ! isActive ) {
		return 'none';
	}
	return activeDir === 'asc' ? 'ascending' : 'descending';
};

interface SortableHeaderProps< Row > {
	column: SortableColumn< Row >;
	activeKey: string;
	activeDir: SortDir;
	onSort: ( key: string ) => void;
}

function SortableHeader< Row >( { column, activeKey, activeDir, onSort }: SortableHeaderProps< Row > ) {
	const isActive = column.key === activeKey;
	const ariaSort = ariaSortFor( isActive, activeDir );
	const className = column.numeric
		? 'newspack-insights__table-num newspack-insights__prompts-table-sort-cell'
		: 'newspack-insights__prompts-table-sort-cell';
	return (
		<th scope="col" className={ className } aria-sort={ ariaSort }>
			<button
				type="button"
				className={ `newspack-insights__prompts-table-sort${ isActive ? ' is-active' : '' }` }
				onClick={ () => onSort( column.key ) }
			>
				<span className="newspack-insights__prompts-table-sort-label">{ column.label }</span>
				<span className="newspack-insights__prompts-table-sort-indicator" aria-hidden="true">
					<Icon icon={ isActive && activeDir === 'asc' ? chevronUp : chevronDown } size={ 14 } />
				</span>
			</button>
		</th>
	);
}

function SortableTable< Row >( { columns, rows, getRowKey, defaultSortKey, emptyMessage, initialRowLimit }: SortableTableProps< Row > ) {
	const [ sortKey, setSortKey ] = useState< string >( defaultSortKey );
	const [ sortDir, setSortDir ] = useState< SortDir >( () => {
		const def = columns.find( c => c.key === defaultSortKey );
		return def?.numeric ? 'desc' : 'asc';
	} );
	const [ expanded, setExpanded ] = useState( false );

	const handleSort = ( key: string ) => {
		if ( key === sortKey ) {
			setSortDir( prev => ( prev === 'asc' ? 'desc' : 'asc' ) );
			return;
		}
		setSortKey( key );
		// Default direction depends on column type: numeric columns open
		// DESC (biggest first), string columns open ASC.
		const def = columns.find( c => c.key === key );
		setSortDir( def?.numeric ? 'desc' : 'asc' );
	};

	const sortedRows = useMemo( () => {
		const column = columns.find( c => c.key === sortKey );
		if ( ! column ) {
			return rows;
		}
		return [ ...rows ].sort( ( a, b ) => {
			const av = column.sortValue( a );
			const bv = column.sortValue( b );
			// Nulls last (regardless of direction).
			if ( av === null && bv === null ) {
				return 0;
			}
			if ( av === null ) {
				return 1;
			}
			if ( bv === null ) {
				return -1;
			}
			let cmp: number;
			if ( typeof av === 'string' && typeof bv === 'string' ) {
				cmp = av.localeCompare( bv );
			} else {
				cmp = ( av as number ) - ( bv as number );
			}
			return sortDir === 'asc' ? cmp : -cmp;
		} );
	}, [ rows, columns, sortKey, sortDir ] );

	const isEmpty = sortedRows.length === 0;

	// Row cap + "See more" toggle (only when a limit is set and exceeded). The
	// cap is applied to the already-sorted rows, so collapsing shows the current
	// top N; the toggle persists across re-sorts.
	const isCollapsible = typeof initialRowLimit === 'number' && sortedRows.length > initialRowLimit;
	const visibleRows = isCollapsible && ! expanded ? sortedRows.slice( 0, initialRowLimit ) : sortedRows;
	const hiddenCount = sortedRows.length - ( initialRowLimit ?? 0 );

	return (
		<>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table newspack-insights__prompts-table--sortable">
					<thead>
						<tr>
							{ columns.map( col => (
								<SortableHeader key={ col.key } column={ col } activeKey={ sortKey } activeDir={ sortDir } onSort={ handleSort } />
							) ) }
						</tr>
					</thead>
					<tbody>
						{ isEmpty ? (
							<tr>
								<td colSpan={ columns.length } className="newspack-insights__prompts-performance-empty">
									{ emptyMessage }
								</td>
							</tr>
						) : (
							visibleRows.map( row => (
								<tr key={ getRowKey( row ) }>
									{ columns.map( col => (
										<td key={ col.key } className={ col.numeric ? 'newspack-insights__table-num' : undefined }>
											{ col.render( row ) }
										</td>
									) ) }
								</tr>
							) )
						) }
					</tbody>
				</table>
			</div>
			{ isCollapsible && (
				<button
					type="button"
					className="newspack-insights__prompts-table-more"
					aria-expanded={ expanded }
					onClick={ () => setExpanded( prev => ! prev ) }
				>
					{ expanded
						? __( 'See less', 'newspack-plugin' )
						: sprintf(
								/* translators: %d: number of additional rows revealed by expanding the table. */
								__( 'See more (%d)', 'newspack-plugin' ),
								hiddenCount
						  ) }
				</button>
			) }
		</>
	);
}

/** Renders an em-dash for non-applicable numeric cells, distinct from a real zero. */
export const NotApplicable = () => (
	<span className="newspack-insights__table-na" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
		—
	</span>
);

export default SortableTable;
