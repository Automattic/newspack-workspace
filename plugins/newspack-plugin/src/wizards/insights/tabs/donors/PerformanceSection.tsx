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
 * out in the section caption. Cells that don't apply to the row's
 * billing model — recurring donors / lapsed donors / recurring
 * revenue on a one-time product, one-time gifts on a recurring
 * product — render as em-dash ("—") rather than 0/$0.00, which
 * would read as "could be higher but isn't" instead of "doesn't
 * apply."
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { BillingModel, DonorsTierRow, DonorsTierVariationRow } from '../../api/donors';
import { formatCurrency, formatNumber } from '../components/format';

export interface PerformanceSectionProps {
	rows: DonorsTierRow[];
}

const NotApplicable = () => (
	<span className="newspack-insights__table-na" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
		—
	</span>
);

const renderCount = ( applies: boolean, value: number ) => ( applies ? formatNumber( value ) : <NotApplicable /> );
const renderCurrency = ( applies: boolean, value: number ) => ( applies ? formatCurrency( value ) : <NotApplicable /> );

const isRecurring = ( m: BillingModel ) => m === 'recurring';
const isOneTime = ( m: BillingModel ) => m === 'one_time';

const renderRowCells = ( row: DonorsTierRow | DonorsTierVariationRow ) => (
	<>
		<td className="newspack-insights__table-num">{ renderCount( isRecurring( row.billing_model ), row.active_recurring_donors ) }</td>
		<td className="newspack-insights__table-num">{ renderCount( isRecurring( row.billing_model ), row.lapsed_donors_in_window ) }</td>
		<td className="newspack-insights__table-num">{ formatNumber( row.new_donors_in_window ) }</td>
		<td className="newspack-insights__table-num">{ renderCount( isOneTime( row.billing_model ), row.one_time_gifts_in_window ) }</td>
		<td className="newspack-insights__table-num">{ renderCurrency( isRecurring( row.billing_model ), row.recurring_revenue_in_window ) }</td>
		<td className="newspack-insights__table-num">{ formatCurrency( row.lifetime_donation_revenue ) }</td>
	</>
);

const PerformanceSection = ( { rows }: PerformanceSectionProps ) => {
	if ( rows.length === 0 ) {
		return (
			<section
				className="newspack-insights__section newspack-insights__section--performance"
				aria-labelledby="newspack-insights-donors-performance-heading"
			>
				<h2 id="newspack-insights-donors-performance-heading" className="newspack-insights__section-heading">
					{ __( 'Donations by tier', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-empty">{ __( 'No donation activity yet.', 'newspack-plugin' ) }</p>
			</section>
		);
	}

	return (
		<section
			className="newspack-insights__section newspack-insights__section--performance"
			aria-labelledby="newspack-insights-donors-performance-heading"
		>
			<h2 id="newspack-insights-donors-performance-heading" className="newspack-insights__section-heading">
				{ __( 'Donations by tier', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __(
					'Current state plus activity in the selected timeframe. Lifetime revenue is the all-time total per product.',
					'newspack-plugin'
				) }
			</p>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Product', 'newspack-plugin' ) }</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Active recurring donors', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Lapsed donors', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'New donors', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'One-time gifts', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Recurring revenue', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Lifetime revenue', 'newspack-plugin' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( row => (
							<Fragment key={ row.product_id }>
								<tr>
									<td>{ row.name }</td>
									{ renderRowCells( row ) }
								</tr>
								{ row.is_parent &&
									row.variations?.map( v => (
										<tr key={ `${ row.product_id }-${ v.variation_id }` } className="newspack-insights__table-row--variation">
											<td>{ v.label }</td>
											{ renderRowCells( v ) }
										</tr>
									) ) }
							</Fragment>
						) ) }
					</tbody>
				</table>
			</div>
		</section>
	);
};

export default PerformanceSection;
