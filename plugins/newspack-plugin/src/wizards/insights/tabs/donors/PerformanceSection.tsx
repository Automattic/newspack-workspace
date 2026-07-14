/**
 * PerformanceSection (NPPD-1617).
 *
 * Donations by tier — table identical in shape to Tab 6's Performance
 * by product, with nested variation rows. Parent rows aggregate the
 * SUM of their variations; standalone products render as a single
 * row. Sorted by lifetime_donation_revenue DESC, top 50 server-side.
 *
 * Column order — current state → window-scoped activity → lifetime:
 *   Product | Active recurring | Lapsed | New | One-time gifts |
 *   Recurring revenue | Lifetime revenue
 *
 * Mixed temporal scope (current state + window + lifetime) is called
 * out in the section caption. Cells that don't apply to a row —
 * recurring donors / lapsed donors / recurring revenue on a row with
 * no recurring nature, one-time gifts on a row with no one-time nature
 * — render as em-dash ("—") rather than 0/$0.00, which would read as
 * "could be higher but isn't" instead of "doesn't apply."
 *
 * A product's nature is two INDEPENDENT flags (`has_recurring`,
 * `has_one_time`), not one enum, so a MIXED parent — a variable product
 * with recurring variations that also took a one-time gift at the parent
 * level (the "(no variation)" row) — shows BOTH its recurring columns and
 * its one-time gifts. Leaf variation rows are purely one nature.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonorsTierRow, DonorsTierVariationRow } from '../../api/donors';
import InsightsDataView from '../components/InsightsDataView';
import type { InsightsColumn } from '../components/InsightsDataView';
import Section from '../components/Section';
import SectionEmpty from '../components/SectionEmpty';
import SectionHeading from '../components/SectionHeading';
import { getPostEditUrl } from '../components/adminLinks';
import { formatCurrency, formatNumber } from '../components/format';
import { Card } from '../../../../../packages/components/src';

export interface PerformanceSectionProps {
	rows: DonorsTierRow[];
}

/**
 * The nested parent → variation table flattened into a single ordered row list
 * for DataViews (which has no native indented-child-row layout). Parent rows keep
 * the product link; leaf variation rows render an indented, de-emphasized label.
 * The list is left in server order (lifetime revenue DESC, variations under their
 * parent) and the table is intentionally not user-sortable so the grouping holds.
 */
type FlatRow = { kind: 'parent'; row: DonorsTierRow } | { kind: 'variation'; parentId: number; row: DonorsTierVariationRow };

const NotApplicable = () => (
	<span className="newspack-insights__table-na" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
		—
	</span>
);

const renderCount = ( applies: boolean, value: number ) => ( applies ? formatNumber( value ) : <NotApplicable /> );
const renderCurrency = ( applies: boolean, value: number ) => ( applies ? formatCurrency( value ).display : <NotApplicable /> );

const flatten = ( rows: DonorsTierRow[] ): FlatRow[] => {
	const flat: FlatRow[] = [];
	rows.forEach( row => {
		flat.push( { kind: 'parent', row } );
		if ( row.is_parent ) {
			row.variations?.forEach( v => flat.push( { kind: 'variation', parentId: row.product_id, row: v } ) );
		}
	} );
	return flat;
};

// Not user-sortable (columns omit `sortValue`): the server order groups each
// parent with its variations, which a re-sort would scramble.
const columns: InsightsColumn< FlatRow >[] = [
	{
		key: 'product',
		label: __( 'Product', 'newspack-plugin' ),
		render: item =>
			item.kind === 'parent' ? (
				<a href={ getPostEditUrl( item.row.product_id ) }>{ item.row.name }</a>
			) : (
				<span className="newspack-insights__dataview-subrow">{ item.row.label }</span>
			),
	},
	{
		key: 'active_recurring_donors',
		label: __( 'Active recurring donors', 'newspack-plugin' ),
		numeric: true,
		render: ( { row } ) => renderCount( row.has_recurring, row.active_recurring_donors ),
	},
	{
		key: 'lapsed_donors',
		label: __( 'Lapsed donors', 'newspack-plugin' ),
		numeric: true,
		render: ( { row } ) => renderCount( row.has_recurring, row.lapsed_donors_in_window ),
	},
	{
		key: 'new_donors',
		label: __( 'New donors', 'newspack-plugin' ),
		numeric: true,
		render: ( { row } ) => formatNumber( row.new_donors_in_window ),
	},
	{
		key: 'one_time_gifts',
		label: __( 'One-time gifts', 'newspack-plugin' ),
		numeric: true,
		render: ( { row } ) => renderCount( row.has_one_time, row.one_time_gifts_in_window ),
	},
	{
		key: 'recurring_revenue',
		label: __( 'Recurring revenue', 'newspack-plugin' ),
		numeric: true,
		render: ( { row } ) => renderCurrency( row.has_recurring, row.recurring_revenue_in_window ),
	},
	{
		key: 'lifetime_revenue',
		label: __( 'Lifetime revenue', 'newspack-plugin' ),
		numeric: true,
		render: ( { row } ) => formatCurrency( row.lifetime_donation_revenue ).display,
	},
];

const PerformanceSection = ( { rows }: PerformanceSectionProps ) => {
	if ( rows.length === 0 ) {
		return (
			<Section
				className="newspack-insights__section newspack-insights__section--performance"
				aria-labelledby="newspack-insights-donors-performance-heading"
			>
				<SectionHeading
					id="newspack-insights-donors-performance-heading"
					title={ __( 'Donations by Tier', 'newspack-plugin' ) }
					description={ __( 'Recurring, one-time, and lifetime revenue broken out by donation tier.', 'newspack-plugin' ) }
				/>
				<SectionEmpty>{ __( 'No donation activity yet.', 'newspack-plugin' ) }</SectionEmpty>
			</Section>
		);
	}

	return (
		<Section
			className="newspack-insights__section newspack-insights__section--performance"
			aria-labelledby="newspack-insights-donors-performance-heading"
		>
			<SectionHeading
				id="newspack-insights-donors-performance-heading"
				title={ __( 'Donations by Tier', 'newspack-plugin' ) }
				description={ __(
					'Current state plus activity in the selected timeframe. Lifetime revenue is the all-time total per product.',
					'newspack-plugin'
				) }
			/>
			<Card __experimentalCoreCard className="newspack-insights__chart-card">
				<InsightsDataView< FlatRow >
					columns={ columns }
					rows={ flatten( rows ) }
					getRowKey={ item =>
						item.kind === 'parent' ? `product:${ item.row.product_id }` : `${ item.parentId }-${ item.row.variation_id }`
					}
					emptyMessage={ __( 'No donation activity yet.', 'newspack-plugin' ) }
				/>
				<p className="newspack-insights__table-footnote">
					{ __(
						'“(no variation)”: Donations recorded at the product level without a specific variation — e.g. one-time or name-your-price gifts made against this product.',
						'newspack-plugin'
					) }
				</p>
			</Card>
		</Section>
	);
};

export default PerformanceSection;
