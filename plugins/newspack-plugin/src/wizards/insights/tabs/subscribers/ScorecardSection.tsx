/**
 * ScorecardSection (NPPD-1616).
 *
 * "Subscribers at a glance" — current-state metrics that do NOT depend
 * on the date range picker. Four cards:
 *   - Active Subscribers
 *   - Subscriptions MRR (with annualized as secondary)
 *   - Upcoming renewals (active subs due to renew in next 30d)
 *   - Upcoming endings (subs set to end in next 30d)
 *
 * Window-scoped metrics (new/churned, gross/net revenue, refund rate,
 * retry rate) live in {@see WindowedSection} below this one.
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SubscribersSnapshot } from '../../api/subscribers';
import MetricCard from '../components/MetricCard';
import { formatCurrency } from '../components/format';

export interface ScorecardSectionProps {
	snapshot: SubscribersSnapshot;
	lastUpdated?: ReactNode;
}

const ScorecardSection = ( { snapshot, lastUpdated }: ScorecardSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--scorecard" aria-labelledby="newspack-insights-scorecard-heading">
		<div className="newspack-insights__section-header-container">
			<div className="newspack-insights__section-header-text">
				<h2 id="newspack-insights-scorecard-heading" className="newspack-insights__section-heading">
					{ __( 'Subscribers at a glance', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-caption">
					{ __( 'Current state and recurring revenue, independent of selected timeframe.', 'newspack-plugin' ) }
				</p>
			</div>
			{ lastUpdated }
		</div>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				label={ __( 'Active subscribers', 'newspack-plugin' ) }
				value={ snapshot.active_subscribers }
				format="number"
				description={ __( 'Distinct readers with at least one active subscription', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Subscriptions MRR', 'newspack-plugin' ) }
				value={ snapshot.mrr }
				format="currency"
				secondary={ sprintf(
					/* translators: %s: annualized subscription revenue (MRR × 12), formatted as currency */
					__( '%s annualized', 'newspack-plugin' ),
					formatCurrency( snapshot.arr ).display
				) }
				description={ __( 'Normalized across billing periods', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Upcoming renewals (30d)', 'newspack-plugin' ) }
				value={ snapshot.upcoming_renewals_30d.count }
				format="number"
				description={ __( 'Active subscriptions due to renew in the next 30 days', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Upcoming endings (30d)', 'newspack-plugin' ) }
				value={ snapshot.upcoming_cancellations_30d.count }
				format="number"
				lowerIsBetter
				description={ __( 'Subscriptions set to end in the next 30 days', 'newspack-plugin' ) }
			/>
		</div>
	</section>
);

export default ScorecardSection;
