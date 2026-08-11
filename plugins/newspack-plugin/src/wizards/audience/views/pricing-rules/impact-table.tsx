/**
 * The impact table shared by the editor preview and the catalog panel: one row
 * per product, one resulting-price column per reader segment. The first price
 * column is the "Everyone else" baseline (no segment / not-logged-in); each
 * segment the preview computed adds a column, so prices compare side by side.
 * Flat rules show a bare price; stepped rules chain cycles with ` → `.
 *
 * Every column prices a NEW subscriber — the calculator projects with no
 * customer at acquisition intent — so a first-time-only/locked rule shows in
 * every segment column even though existing subscribers are excluded at
 * checkout. A note below the table spells this out whenever segment columns are
 * present, so a segment named for existing subscribers isn't misread as
 * modeling their lifecycle (NPPD-1853).
 *
 * Long samples collapse to the first ROW_LIMIT rows behind a See More toggle,
 * matching Insights' InsightsDataView: rows are sorted in full and then sliced,
 * so a collapsed table always shows the current top N rather than whichever
 * rows happened to come first.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useMemo, useId } from '@wordpress/element';
import {
	Button,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
// Not the Newspack wrapper: with-wizard-screen/style.scss gives `.newspack-dataviews`
// a -48px page bleed that hangs this embedded table past the form column.
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import type { Field, View } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { TableCard } from '../../../../../packages/components/src';
import { cycleMarkerNote, formatPrice, formatSegment } from './impact-format';

/** Rows shown before the publisher asks for the rest. */
const ROW_LIMIT = 10;

interface PriceColumn {
	key: string;
	label: string;
	isSegment: boolean;
	byId: Record< number, CatalogImpactRow >;
}

/** Index a sample's rows by product id for per-column lookup. */
function indexById( rows: CatalogImpactRow[] ): Record< number, CatalogImpactRow > {
	const map: Record< number, CatalogImpactRow > = {};
	for ( const row of rows ) {
		map[ row.product_id ] = row;
	}
	return map;
}

/** One product's resulting price in one column: bare, stepped, or — when absent. */
function ResultingCell( { row, currency }: { row?: CatalogImpactRow; currency: PricingRulesCurrency } ) {
	if ( ! row ) {
		return <span className="newspack-pricing-rules__muted">—</span>;
	}
	if ( row.segments.length <= 1 ) {
		return <>{ formatPrice( row.adjusted, currency ) }</>;
	}
	return (
		<>
			{ row.segments.map( ( seg, i ) => (
				<span key={ i } className={ seg.changed ? 'is-changed' : undefined }>
					{ i > 0 ? ' → ' : '' }
					{ formatSegment( seg, currency ) }
				</span>
			) ) }
		</>
	);
}

interface ImpactTableProps {
	baseline: CatalogImpactRow[];
	segmentGroups: SegmentImpactGroup[];
	currency: PricingRulesCurrency;
	// The editor carries this note in its section header instead, where it can
	// appear before the preview has loaded.
	showCycleNote?: boolean;
}

export default function ImpactTable( { baseline, segmentGroups, currency, showCycleNote = true }: ImpactTableProps ) {
	const hasSegments = segmentGroups.length > 0;
	const [ expanded, setExpanded ] = useState( false );
	const tableId = useId();

	const columns: PriceColumn[] = useMemo(
		() => [
			{
				key: 'baseline',
				label: hasSegments ? __( 'Everyone else', 'newspack-plugin' ) : __( 'Resulting price', 'newspack-plugin' ),
				isSegment: false,
				byId: indexById( baseline ),
			},
			...segmentGroups.map( group => ( {
				key: `seg-${ group.segment_id }`,
				label: group.segment_label,
				isSegment: true,
				byId: indexById( group.sample ),
			} ) ),
		],
		[ baseline, segmentGroups, hasSegments ]
	);

	// Both rewrite view.fields, which is derived below and would snap back.
	const fields: Field< CatalogImpactRow >[] = useMemo(
		() => [
			{
				id: 'product',
				label: __( 'Product', 'newspack-plugin' ),
				enableHiding: false,
				getValue: ( { item }: { item: CatalogImpactRow } ) => item.name,
				render: ( { item }: { item: CatalogImpactRow } ) =>
					item.edit_link ? <a href={ item.edit_link }>{ item.name }</a> : <span>{ item.name }</span>,
			},
			{
				id: 'regular',
				label: __( 'Regular', 'newspack-plugin' ),
				enableHiding: false,
				getValue: ( { item }: { item: CatalogImpactRow } ) => item.regular,
				render: ( { item }: { item: CatalogImpactRow } ) => <>{ formatPrice( item.regular, currency ) }</>,
			},
			...columns.map( col => ( {
				id: col.key,
				label: col.label,
				enableHiding: false,
				// Stepped rules render one value per cycle, so there is no number to sort on.
				enableSorting: false,
				getValue: ( { item }: { item: CatalogImpactRow } ) => col.byId[ item.product_id ]?.adjusted ?? 0,
				render: ( { item }: { item: CatalogImpactRow } ) => {
					const cell = col.byId[ item.product_id ];
					// A stepped cell marks each changed cycle itself, so the wrapper must not mark it again.
					const isMarked = !! cell?.changed && cell.segments.length <= 1;
					return (
						<span className={ isMarked ? 'is-changed' : undefined }>
							<ResultingCell row={ cell } currency={ currency } />
						</span>
					);
				},
			} ) ),
		],
		[ columns, currency ]
	);

	const hasCycles = useMemo( () => columns.some( col => Object.values( col.byId ).some( row => row.segments.length > 1 ) ), [ columns ] );

	const fieldIds = useMemo( () => [ 'regular', ...columns.map( col => col.key ) ], [ columns ] );

	// One page of everything the server sent: DataViews' own pagination is off, and
	// the See More slice below is what actually shortens the table.
	const perPage = Math.max( baseline.length, 1 );

	const [ view, setView ] = useState< View >( () => ( {
		type: 'table',
		page: 1,
		search: '',
		filters: [],
		layout: { density: 'compact', enableMoving: false },
		titleField: 'product',
		fields: fieldIds,
	} ) );

	// A segment column can appear or vanish while the publisher is editing, and the
	// page holds the whole sample. Both follow the data rather than living in view
	// state, where an effect would land them a paint late — long enough for a wider
	// sample to paint once as a short, un-collapsible table.
	const tableView = useMemo( () => ( { ...view, perPage, fields: fieldIds } ), [ view, perPage, fieldIds ] );

	const { data: sorted, paginationInfo } = useMemo( () => filterSortAndPaginate( baseline, tableView, fields ), [ baseline, tableView, fields ] );

	// A different set of products is a fresh answer to "show me the rest", so the
	// collapse returns. Keyed on the ids alone: a refetch that reprices the same
	// products is the publisher watching their own edit, and keeps the expansion.
	// During render, so the wider sample never paints expanded before collapsing.
	const sampleKey = useMemo( () => baseline.map( row => row.product_id ).join( ',' ), [ baseline ] );
	const [ lastSample, setLastSample ] = useState( sampleKey );
	if ( lastSample !== sampleKey ) {
		setLastSample( sampleKey );
		setExpanded( false );
	}

	// Sliced after the sort, so collapsing keeps the current top rows.
	const collapsible = sorted.length > ROW_LIMIT;
	const data = collapsible && ! expanded ? sorted.slice( 0, ROW_LIMIT ) : sorted;

	return (
		<>
			<TableCard
				after={
					collapsible ? (
						<HStack justify="flex-start">
							<Button
								className="newspack-pricing-rules__see-more"
								variant="link"
								aria-expanded={ expanded }
								aria-controls={ tableId }
								onClick={ () => setExpanded( ! expanded ) }
							>
								{ expanded ? __( 'See Less', 'newspack-plugin' ) : __( 'See More', 'newspack-plugin' ) }
							</Button>
						</HStack>
					) : undefined
				}
			>
				<div
					id={ tableId }
					className="newspack-pricing-rules__impact-table"
					role="region"
					aria-label={ __( 'Resulting prices by product and reader segment', 'newspack-plugin' ) }
				>
					<DataViews
						data={ data }
						fields={ fields }
						view={ tableView }
						onChangeView={ setView }
						paginationInfo={ paginationInfo }
						defaultLayouts={ { table: {} } }
						getItemId={ ( item: CatalogImpactRow ) => String( item.product_id ) }
						empty={ <p className="newspack-pricing-rules__muted">{ __( 'No products to show.', 'newspack-plugin' ) }</p> }
					>
						<DataViews.Layout />
					</DataViews>
				</div>
			</TableCard>
			{ showCycleNote && hasCycles && <p className="newspack-pricing-rules__muted">{ cycleMarkerNote() }</p> }
			{ hasSegments && (
				<p className="newspack-pricing-rules__muted">
					{ __(
						'Each column shows what a new subscriber would pay — overall, or assuming membership in that segment. First-time-only and locked rules apply to new sign-ups only, so existing subscribers are not modeled here.',
						'newspack-plugin'
					) }
				</p>
			) }
		</>
	);
}
