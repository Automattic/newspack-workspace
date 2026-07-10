/**
 * InsightsDataView (NPPD-1889).
 *
 * Shared read-only DataViews table used by every table across the Insights
 * tabs. Design-review feedback (Thomas) asked for one unified table treatment;
 * this wraps `@wordpress/dataviews` (via the Newspack `DataViews` component) in
 * a locked-down, read-only table layout:
 *
 *   - `view.type: 'table'`, a single page (`perPage` covers the whole set), no
 *     search / filters / bulk actions / field-visibility toggle. The DataViews
 *     header actions and pagination footer are hidden in SCSS
 *     (`.newspack-insights-dataview`, in the global `insights/style.scss`) so
 *     the only interactive affordance is click-to-sort on the column headers.
 *   - Native DataViews sorting, with a `sort` comparator per column that keeps
 *     null / em-dash cells at the bottom regardless of direction (preserving
 *     the old SortableTable contract).
 *   - Numeric columns are right-aligned via `view.layout.styles[key].align`.
 *   - The graceful-failure envelope (overlay / error / not-configured / empty)
 *     routes through the shared MetricNote / SectionEmpty treatments, matching
 *     the previous MetricTable behavior.
 *   - Optional `expandable` "See more" toggle: rows are sorted in full, then
 *     sliced to `defaultRowLimit` while collapsed (so collapsing always shows
 *     the current top N).
 *
 * The three former table primitives (MetricTable, SortableTable,
 * DistributionTable) now adapt their own public props onto this component, so
 * their consumers are untouched.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { filterSortAndPaginate } from '@wordpress/dataviews';
import type { Field, View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { DataViews } from '../../../../../packages/components/src';
import MetricNote from './MetricNote';
import SectionEmpty from './SectionEmpty';
import type { MetricCardOverlay } from './MetricCard';

export interface InsightsColumn< Row > {
	/** Stable key; also a valid sort target. */
	key: string;
	label: string;
	/** Right-align + numeric sort semantics. */
	numeric?: boolean;
	/** Cell content. */
	render: ( row: Row ) => React.ReactNode;
	/**
	 * Value used for sorting. `null` always sorts last. Omit to make the
	 * column unsortable.
	 */
	sortValue?: ( row: Row ) => number | string | null;
}

export interface InsightsDataViewProps< Row > {
	columns: InsightsColumn< Row >[];
	rows: Row[];
	/** Stable, unique id per row (used for React keys + DataViews identity). */
	getRowKey: ( row: Row, index: number ) => string;
	/** Column key to sort by initially. */
	defaultSortKey?: string;
	/** Defaults to `desc` for a numeric default column, `asc` otherwise. */
	defaultSortDirection?: 'asc' | 'desc';
	/** Shown (via SectionEmpty) when there are no rows. */
	emptyMessage: string;
	/** Graceful-failure envelope — routed through MetricNote. */
	overlay?: MetricCardOverlay;
	error?: boolean;
	notConfigured?: boolean;
	/** Enable the collapse/expand toggle. Requires `defaultRowLimit`. */
	expandable?: boolean;
	/** Rows shown while collapsed. */
	defaultRowLimit?: number;
	/**
	 * Freeze the first column while the rest scroll horizontally (opt-in). Use on
	 * wide tables where a row would otherwise scroll away from its identifying
	 * first column (e.g. Performance by prompt). Styled in `insights/style.scss`.
	 */
	stickyFirstColumn?: boolean;
}

/**
 * Compare two sort values, always pushing null / undefined to the bottom
 * regardless of direction. Mirrors the old SortableTable / MetricTable
 * em-dash-sorts-last behavior.
 */
const nullLastCompare = ( a: number | string | null, b: number | string | null, direction: 'asc' | 'desc' ): number => {
	const aEmpty = a === null || a === undefined;
	const bEmpty = b === null || b === undefined;
	if ( aEmpty && bEmpty ) {
		return 0;
	}
	if ( aEmpty ) {
		return 1;
	}
	if ( bEmpty ) {
		return -1;
	}
	const cmp = typeof a === 'number' && typeof b === 'number' ? a - b : String( a ).localeCompare( String( b ) );
	return direction === 'desc' ? -cmp : cmp;
};

const InsightsDataView = < Row, >( {
	columns,
	rows,
	getRowKey,
	defaultSortKey,
	defaultSortDirection,
	emptyMessage,
	overlay,
	error,
	notConfigured,
	expandable = false,
	defaultRowLimit,
	stickyFirstColumn = false,
}: InsightsDataViewProps< Row > ) => {
	const defaultColumn = columns.find( col => col.key === defaultSortKey );
	const initialDirection = defaultSortDirection ?? ( defaultColumn?.numeric ? 'desc' : 'asc' );

	// Sort / page state. `fields`, `layout` and the visible field set are always
	// derived from the current `columns` prop (below), so only the user's sort
	// choice is actually persisted here.
	const [ view, setView ] = useState< View >( {
		type: 'table',
		page: 1,
		perPage: 1000,
		search: '',
		filters: [],
		fields: columns.map( col => col.key ),
		...( defaultSortKey ? { sort: { field: defaultSortKey, direction: initialDirection } } : {} ),
	} );

	const [ expanded, setExpanded ] = useState( false );

	// Stable id per row, keyed by object identity so DataViews sorting (which
	// reorders the same row objects) keeps consistent ids.
	const idMap = useMemo( () => {
		const map = new WeakMap< object, string >();
		rows.forEach( ( row, i ) => {
			if ( row && typeof row === 'object' ) {
				map.set( row as object, getRowKey( row, i ) );
			}
		} );
		return map;
	}, [ rows, getRowKey ] );

	const fields: Field< Row >[] = useMemo(
		() =>
			columns.map( col => ( {
				id: col.key,
				label: col.label,
				enableHiding: false,
				enableGlobalSearch: false,
				filterBy: false,
				enableSorting: Boolean( col.sortValue ),
				getValue: ( { item }: { item: Row } ) => ( col.sortValue ? col.sortValue( item ) ?? '' : '' ),
				render: ( { item }: { item: Row } ) => <>{ col.render( item ) }</>,
				...( col.sortValue
					? {
							sort: ( a: Row, b: Row, direction: 'asc' | 'desc' ) =>
								nullLastCompare( col.sortValue!( a ), col.sortValue!( b ), direction ),
					  }
					: {} ),
			} ) ),
		[ columns ]
	);

	// Authoritative view: props drive the field set and column alignment; the
	// user's interaction drives sort/page (from state).
	const activeView: View = useMemo(
		() =>
			( {
				...view,
				fields: columns.map( col => col.key ),
				layout: {
					styles: Object.fromEntries( columns.filter( col => col.numeric ).map( col => [ col.key, { align: 'end' as const } ] ) ),
				},
			} ) as View,
		[ view, columns ]
	);

	const { data: sorted } = useMemo( () => filterSortAndPaginate( rows, activeView, fields ), [ rows, activeView, fields ] );

	// Graceful-failure envelope (after hooks so hook order stays stable).
	if ( overlay ) {
		return <MetricNote overlay={ overlay } />;
	}
	if ( error ) {
		return <MetricNote error />;
	}
	if ( notConfigured ) {
		return <MetricNote notConfigured />;
	}
	if ( rows.length === 0 ) {
		return <SectionEmpty>{ emptyMessage }</SectionEmpty>;
	}

	const collapsible = expandable && typeof defaultRowLimit === 'number' && sorted.length > defaultRowLimit;
	const visibleRows = collapsible && ! expanded ? sorted.slice( 0, defaultRowLimit ) : sorted;

	const getItemId = ( item: Row ): string => ( item && typeof item === 'object' && idMap.get( item as object ) ) || String( getRowKey( item, 0 ) );

	return (
		<>
			<DataViews
				className={ `newspack-insights-dataview${ stickyFirstColumn ? ' newspack-insights-dataview--sticky-first' : '' }` }
				data={ visibleRows }
				// The Newspack DataViews wrapper collapses its item type to `unknown`;
				// our fields/getItemId are typed against `Row`, so narrow at the boundary.
				fields={ fields as unknown as Field< unknown >[] }
				view={ activeView }
				onChangeView={ setView }
				paginationInfo={ { totalItems: visibleRows.length, totalPages: 1 } }
				defaultLayouts={ { table: {} } }
				getItemId={ getItemId as ( item: unknown ) => string }
				search={ false }
			/>
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

export default InsightsDataView;
