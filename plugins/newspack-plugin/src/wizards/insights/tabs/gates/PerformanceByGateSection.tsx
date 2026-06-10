/**
 * PerformanceByGateSection (NPPD-1604, Section 5).
 *
 * Full-width per-gate breakdown table. Sortable on every column;
 * default sort is impressions descending per spec. Click a column
 * header to toggle direction (numeric columns flip to DESC on first
 * click, ASC on second click; the gate-name column starts ASC).
 *
 * Null cells (em-dash) always sort to the bottom regardless of
 * direction — a gate without a registration block has no Regwall
 * conversion rate to compare, so a "—" should never claim the top of
 * an ascending sort.
 *
 * Phase 1 always renders the empty-state copy from spec since
 * `rows` is empty. The sort affordances stay visible so the chrome
 * is identical between phases — Phase 2 (NPPD-1630) populates
 * `performance_by_gate.rows` from BQ + a server-side
 * `wp_posts.post_title` enrichment, at which point the click-to-sort
 * UI starts shuffling rows.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { Icon, chevronUp, chevronDown } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { GatesPerformanceRow, GatesPerformanceTable } from '../../api/gates';
import { formatNumber, formatPercent } from '../components/format';

export interface PerformanceByGateSectionProps {
	data: GatesPerformanceTable;
}

/**
 * The columns we expose for sorting. Matches the visible column set
 * one-for-one.
 */
type SortKey =
	| 'gate_name'
	| 'impressions'
	| 'unique_viewers'
	| 'registrations'
	| 'regwall_conversion_rate'
	| 'paywall_attempts'
	| 'paywall_attempt_rate';

type SortDir = 'asc' | 'desc';

interface ColumnDef {
	key: SortKey;
	label: string;
	numeric: boolean;
}

const NotApplicable = () => (
	<span className="newspack-insights__table-na" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
		—
	</span>
);

const renderPercent = ( v: number | null ) => ( v === null ? <NotApplicable /> : formatPercent( v ) );

const renderRow = ( row: GatesPerformanceRow ) => (
	<tr key={ row.gate_post_id }>
		<td>{ row.gate_name }</td>
		<td className="newspack-insights__table-num">{ formatNumber( row.impressions ) }</td>
		<td className="newspack-insights__table-num">{ formatNumber( row.unique_viewers ) }</td>
		<td className="newspack-insights__table-num">{ formatNumber( row.registrations ) }</td>
		<td className="newspack-insights__table-num">{ renderPercent( row.regwall_conversion_rate ) }</td>
		<td className="newspack-insights__table-num">{ formatNumber( row.paywall_attempts ) }</td>
		<td className="newspack-insights__table-num">{ renderPercent( row.paywall_attempt_rate ) }</td>
	</tr>
);

/**
 * Compare two rows on a given column. Nulls always sort last
 * regardless of direction. String compare for `gate_name` uses the
 * browser locale.
 */
const compareRows = ( a: GatesPerformanceRow, b: GatesPerformanceRow, key: SortKey, dir: SortDir ): number => {
	const av = a[ key ];
	const bv = b[ key ];
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
	return dir === 'asc' ? cmp : -cmp;
};

interface SortableHeaderProps {
	column: ColumnDef;
	activeKey: SortKey;
	activeDir: SortDir;
	onSort: ( key: SortKey ) => void;
}

const ariaSortFor = ( isActive: boolean, activeDir: SortDir ): 'ascending' | 'descending' | 'none' => {
	if ( ! isActive ) {
		return 'none';
	}
	return activeDir === 'asc' ? 'ascending' : 'descending';
};

const SortableHeader = ( { column, activeKey, activeDir, onSort }: SortableHeaderProps ) => {
	const isActive = column.key === activeKey;
	const ariaSort = ariaSortFor( isActive, activeDir );
	const className = column.numeric ? 'newspack-insights__table-num newspack-insights__table-sort-cell' : 'newspack-insights__table-sort-cell';
	return (
		<th scope="col" className={ className } aria-sort={ ariaSort }>
			<button
				type="button"
				className={ `newspack-insights__table-sort${ isActive ? ' is-active' : '' }` }
				onClick={ () => onSort( column.key ) }
			>
				<span className="newspack-insights__table-sort-label">{ column.label }</span>
				<span className="newspack-insights__table-sort-indicator" aria-hidden="true">
					<Icon icon={ isActive && activeDir === 'asc' ? chevronUp : chevronDown } size={ 14 } />
				</span>
			</button>
		</th>
	);
};

const PerformanceByGateSection = ( { data }: PerformanceByGateSectionProps ) => {
	const columns: ColumnDef[] = [
		{ key: 'gate_name', label: __( 'Gate name', 'newspack-plugin' ), numeric: false },
		{ key: 'impressions', label: __( 'Impressions', 'newspack-plugin' ), numeric: true },
		{ key: 'unique_viewers', label: __( 'Unique viewers', 'newspack-plugin' ), numeric: true },
		{ key: 'registrations', label: __( 'Registrations', 'newspack-plugin' ), numeric: true },
		{ key: 'regwall_conversion_rate', label: __( 'Regwall conversion rate', 'newspack-plugin' ), numeric: true },
		{ key: 'paywall_attempts', label: __( 'Paywall attempts', 'newspack-plugin' ), numeric: true },
		{ key: 'paywall_attempt_rate', label: __( 'Paywall attempt rate', 'newspack-plugin' ), numeric: true },
	];

	const [ sortKey, setSortKey ] = useState< SortKey >( 'impressions' );
	const [ sortDir, setSortDir ] = useState< SortDir >( 'desc' );

	const handleSort = ( key: SortKey ) => {
		if ( key === sortKey ) {
			setSortDir( prev => ( prev === 'asc' ? 'desc' : 'asc' ) );
			return;
		}
		setSortKey( key );
		// Default direction depends on column type: numeric columns
		// open DESC (biggest first), string columns open ASC.
		const def = columns.find( c => c.key === key );
		setSortDir( def?.numeric ? 'desc' : 'asc' );
	};

	const sortedRows = useMemo( () => [ ...data.rows ].sort( ( a, b ) => compareRows( a, b, sortKey, sortDir ) ), [ data.rows, sortKey, sortDir ] );

	return (
		<section
			className="newspack-insights__section newspack-insights__section--performance"
			aria-labelledby="newspack-insights-gates-performance-heading"
		>
			<h2 id="newspack-insights-gates-performance-heading" className="newspack-insights__section-heading">
				{ __( 'Performance by gate', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __( 'Per-gate breakdown for the selected timeframe. Click any column to re-sort.', 'newspack-plugin' ) }
			</p>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table newspack-insights__table--sortable">
					<thead>
						<tr>
							{ columns.map( col => (
								<SortableHeader key={ col.key } column={ col } activeKey={ sortKey } activeDir={ sortDir } onSort={ handleSort } />
							) ) }
						</tr>
					</thead>
					<tbody>
						{ 'error' === data.state && (
							<tr>
								<td colSpan={ columns.length } className="newspack-insights__gates-performance-empty">
									{ __( 'Unable to load this section. Newspack Manager may need attention.', 'newspack-plugin' ) }
								</td>
							</tr>
						) }
						{ 'empty' === data.state && (
							<tr>
								<td colSpan={ columns.length } className="newspack-insights__gates-performance-empty">
									{ __(
										'No gate data yet. Performance metrics will appear once readers begin interacting with your gates.',
										'newspack-plugin'
									) }
								</td>
							</tr>
						) }
						{ 'populated' === data.state && sortedRows.map( renderRow ) }
					</tbody>
				</table>
			</div>
		</section>
	);
};

export default PerformanceByGateSection;
