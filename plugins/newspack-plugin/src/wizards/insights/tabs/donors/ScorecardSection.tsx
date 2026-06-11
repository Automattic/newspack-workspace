/**
 * ScorecardSection (NPPD-1617).
 *
 * "Donors at a glance" — current-state metrics that ignore the date
 * picker. Four cards mirroring Tab 6's glance:
 *   - Active Donors (with active recurring count as secondary)
 *   - Donation MRR (with annualized as secondary)
 *   - Upcoming renewals (donation subs due to renew in next 30d)
 *   - Upcoming endings (donation subs set to end in next 30d)
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonorsSnapshot } from '../../api/donors';
import MetricCard from '../components/MetricCard';
import { formatCurrency, formatNumber } from '../components/format';

export interface ScorecardSectionProps {
	snapshot: DonorsSnapshot;
}

const ScorecardSection = ( { snapshot }: ScorecardSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--scorecard"
		aria-labelledby="newspack-insights-donors-scorecard-heading"
	>
		<h2 id="newspack-insights-donors-scorecard-heading" className="newspack-insights__section-heading">
			{ __( 'Donors at a glance', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __( 'Current state and recurring revenue, independent of selected timeframe.', 'newspack-plugin' ) }
		</p>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				label={ __( 'Active donors', 'newspack-plugin' ) }
				value={ snapshot.active_donors }
				format="number"
				secondary={ sprintf(
					/* translators: %s: count of distinct active recurring donors */
					__( '%s active recurring', 'newspack-plugin' ),
					formatNumber( snapshot.active_recurring_donors )
				) }
				description={ __( 'Distinct readers with an active recurring donation or a one-time gift in the last 12 months', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Donation MRR', 'newspack-plugin' ) }
				value={ snapshot.donation_mrr }
				format="currency"
				secondary={ sprintf(
					/* translators: %s: annualized donation revenue (MRR × 12), formatted as currency */
					__( '%s annualized', 'newspack-plugin' ),
					formatCurrency( snapshot.donation_arr )
				) }
				description={ __( 'Active recurring donations normalized to a monthly rate', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Upcoming renewals (30d)', 'newspack-plugin' ) }
				value={ snapshot.upcoming_donation_renewals_30d.count }
				format="number"
				description={ __( 'Active recurring donations due to renew in the next 30 days', 'newspack-plugin' ) }
			/>
			<MetricCard
				label={ __( 'Upcoming endings (30d)', 'newspack-plugin' ) }
				value={ snapshot.upcoming_donation_cancellations_30d.count }
				format="number"
				lowerIsBetter
				description={ __( 'Donation subscriptions set to end in the next 30 days', 'newspack-plugin' ) }
			/>
		</div>
	</section>
);

export default ScorecardSection;
