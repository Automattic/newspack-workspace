/**
 * The impact table shared by the editor preview and the catalog panel: one row
 * per product, one resulting-price column per reader segment. The first price
 * column is the "Everyone else" baseline (no segment / not-logged-in); each
 * segment the preview computed adds a column, so prices compare side by side.
 * Flat rules show a bare price; stepped rules join cycles with ` · `.
 *
 * Every column prices a NEW subscriber — the calculator projects with no
 * customer at acquisition intent — so a first-time-only/locked rule shows in
 * every segment column even though existing subscribers are excluded at
 * checkout. A caption spells this out whenever segment columns are present, so a
 * segment named for existing subscribers isn't misread as modeling their
 * lifecycle (NPPD-1853).
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
			isSegment: false,
			byId: indexById( baseline ),
		},
		...segmentGroups.map( group => ( {
			key: `seg-${ group.segment_id }`,
			label: group.segment_label,
			isSegment: true,
			byId: indexById( group.sample ),
		} ) ),
	];

	return (
		<>
			<table className="newspack-pricing-rules__impact-table">
				<caption className="screen-reader-text">{ __( 'Resulting prices by product and reader segment', 'newspack-plugin' ) }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Product', 'newspack-plugin' ) }</th>
						<th scope="col">{ __( 'Regular', 'newspack-plugin' ) }</th>
						{ columns.map( col => (
							<th scope="col" key={ col.key } className={ col.isSegment ? 'is-segment-col' : undefined }>
								{ col.label }
							</th>
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
								const cellClass = [ col.isSegment && 'is-segment-col', cell?.changed && 'is-changed' ].filter( Boolean ).join( ' ' );
								return (
									<td key={ col.key } className={ cellClass || undefined }>
										<ResultingCell row={ cell } currency={ currency } />
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>
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
