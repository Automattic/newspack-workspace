/**
 * The impact table shared by the editor preview and the catalog panel: one row
 * per product, one resulting-price column per reader segment. The first price
 * column is the "Everyone else" baseline (no segment / not-logged-in); each
 * segment the preview computed adds a column, so prices compare side by side.
 * Flat rules show a bare price; stepped rules join cycles with ` · `.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatPrice, formatSegment } from './impact-format';

interface PriceColumn {
	key: string;
	label: string;
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
					{ i > 0 ? ' · ' : '' }
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
}

export default function ImpactTable( { baseline, segmentGroups, currency }: ImpactTableProps ) {
	const hasSegments = segmentGroups.length > 0;
	const columns: PriceColumn[] = [
		{
			key: 'baseline',
			label: hasSegments ? __( 'Everyone else', 'newspack-plugin' ) : __( 'Resulting price', 'newspack-plugin' ),
			byId: indexById( baseline ),
		},
		...segmentGroups.map( group => ( {
			key: `seg-${ group.segment_id }`,
			label: group.segment_label,
			byId: indexById( group.sample ),
		} ) ),
	];

	return (
		<table className="newspack-pricing-rules__impact-table">
			<thead>
				<tr>
					<th>{ __( 'Product', 'newspack-plugin' ) }</th>
					<th>{ __( 'Regular', 'newspack-plugin' ) }</th>
					{ columns.map( col => (
						<th key={ col.key }>{ col.label }</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ baseline.map( row => (
					<tr key={ row.product_id }>
						<td>{ row.edit_link ? <a href={ row.edit_link }>{ row.name }</a> : row.name }</td>
						<td>{ formatPrice( row.regular, currency ) }</td>
						{ columns.map( col => {
							const cell = col.byId[ row.product_id ];
							return (
								<td key={ col.key } className={ cell?.changed ? 'is-changed' : undefined }>
									<ResultingCell row={ cell } currency={ currency } />
								</td>
							);
						} ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
