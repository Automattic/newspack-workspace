/**
 * The impact table shared by the editor preview and the catalog panel.
 *
 * Every column prices a NEW subscriber — the calculator projects with no
 * customer at acquisition intent — so a first-time-only/locked rule shows in
 * every segment column even though existing subscribers are excluded at
 * checkout. The note below the table spells this out whenever segment columns
 * are present (NPPD-1853).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useMemo, useId } from '@wordpress/element';
import {
	Button,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
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

function indexById( rows: CatalogImpactRow[] ): Record< number, CatalogImpactRow > {
	const map: Record< number, CatalogImpactRow > = {};
	for ( const row of rows ) {
		map[ row.product_id ] = row;
	}
	return map;
}

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
	framed?: boolean;
	collapsible?: boolean;
}

export default function ImpactTable( {
	baseline,
	segmentGroups,
	currency,
	showCycleNote = true,
	framed = true,
	collapsible = true,
}: ImpactTableProps ) {
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

	// DataViews' own pagination is off; the See More slice is what shortens the table.
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

	// perPage and the segment columns follow the data; in view state, an effect
	// would land them a paint late.
	const tableView = useMemo( () => ( { ...view, perPage, fields: fieldIds } ), [ view, perPage, fieldIds ] );

	const { data: sorted, paginationInfo } = useMemo( () => filterSortAndPaginate( baseline, tableView, fields ), [ baseline, tableView, fields ] );

	// A different product set re-collapses the table; keyed on ids alone so a
	// refetch repricing the same products keeps the expansion. During render, so
	// a wider sample never paints expanded first.
	const sampleKey = useMemo( () => baseline.map( row => row.product_id ).join( ',' ), [ baseline ] );
	const [ lastSample, setLastSample ] = useState( sampleKey );
	if ( lastSample !== sampleKey ) {
		setLastSample( sampleKey );
		setExpanded( false );
	}

	// Sliced after the sort, so collapsing keeps the current top rows.
	const canCollapse = collapsible && sorted.length > ROW_LIMIT;
	const data = canCollapse && ! expanded ? sorted.slice( 0, ROW_LIMIT ) : sorted;

	const seeMore = canCollapse ? (
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
	) : null;

	const table = (
		<div
			id={ tableId }
			className={ `newspack-pricing-rules__impact-table${ framed ? ' newspack-pricing-rules__impact-table--framed' : '' }` }
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
	);

	return (
		<VStack spacing={ 6 }>
			{ showCycleNote && hasCycles && <p className="newspack-pricing-rules__muted">{ cycleMarkerNote() }</p> }
			{ hasSegments && (
				<p className="newspack-pricing-rules__muted">
					{ __(
						'Each column shows what a new subscriber would pay — overall, or assuming membership in that segment. First-time-only and locked rules apply to new sign-ups only, so existing subscribers are not modeled here.',
						'newspack-plugin'
					) }
				</p>
			) }
			{ framed ? (
				<TableCard after={ seeMore ?? undefined }>{ table }</TableCard>
			) : (
				<>
					{ table }
					{ seeMore }
				</>
			) }
		</VStack>
	);
}
