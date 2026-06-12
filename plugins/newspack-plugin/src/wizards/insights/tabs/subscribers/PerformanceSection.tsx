/**
 * PerformanceSection (NPPD-1616).
 *
 * Per-product breakdown for subscription products. Top 50 parents (or
 * standalone simple subs) by active subscriber count (server-limited).
 * Variable products render as a parent row with their variations
 * indented underneath. The parent row's aggregates equal the SUM of
 * its variation rows.
 *
 * lifetime_revenue is an approximation (sum of renewal-amount rows
 * across active + churned subs); a true LTV waits on the BigQuery
 * wrapper in v1.1.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { PerformanceRow } from '../../api/subscribers';
import SectionEmpty from '../components/SectionEmpty';
import SectionHeading from '../components/SectionHeading';
import { formatCurrency, formatNumber } from '../components/format';

export interface PerformanceSectionProps {
	rows: PerformanceRow[];
}

const PerformanceSection = ( { rows }: PerformanceSectionProps ) => {
	if ( rows.length === 0 ) {
		return (
			<section
				className="newspack-insights__section newspack-insights__section--performance"
				aria-labelledby="newspack-insights-performance-heading"
			>
				<SectionHeading id="newspack-insights-performance-heading" title={ __( 'Subscriptions by product', 'newspack-plugin' ) } />
				<SectionEmpty>{ __( 'No subscription products configured yet.', 'newspack-plugin' ) }</SectionEmpty>
			</section>
		);
	}

	return (
		<section
			className="newspack-insights__section newspack-insights__section--performance"
			aria-labelledby="newspack-insights-performance-heading"
		>
			<SectionHeading
				id="newspack-insights-performance-heading"
				title={ __( 'Subscriptions by product', 'newspack-plugin' ) }
				description={ __(
					'Active subscriptions per product (subscriptions, not unique customers). Lifetime revenue is the all-time total per product.',
					'newspack-plugin'
				) }
			/>
			<div className="newspack-insights__table-wrap">
				<table className="newspack-insights__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Product', 'newspack-plugin' ) }</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Active subs', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Churned subs', 'newspack-plugin' ) }
							</th>
							<th scope="col" className="newspack-insights__table-num">
								{ __( 'Active value', 'newspack-plugin' ) }
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
									<td className="newspack-insights__table-num">{ formatNumber( row.active_subs ) }</td>
									<td className="newspack-insights__table-num">{ formatNumber( row.churned_subs ) }</td>
									<td className="newspack-insights__table-num">{ formatCurrency( row.active_value ).display }</td>
									<td className="newspack-insights__table-num">{ formatCurrency( row.lifetime_revenue ).display }</td>
								</tr>
								{ row.is_parent &&
									row.variations?.map( v => (
										<tr key={ `${ row.product_id }-${ v.variation_id }` } className="newspack-insights__table-row--variation">
											<td>{ v.label }</td>
											<td className="newspack-insights__table-num">{ formatNumber( v.active_subs ) }</td>
											<td className="newspack-insights__table-num">{ formatNumber( v.churned_subs ) }</td>
											<td className="newspack-insights__table-num">{ formatCurrency( v.active_value ).display }</td>
											<td className="newspack-insights__table-num">{ formatCurrency( v.lifetime_revenue ).display }</td>
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
