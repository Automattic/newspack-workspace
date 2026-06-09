/**
 * RetentionSection (NPPD-1617).
 *
 * Donor retention metrics. Both rates are derived from cohorts that
 * may legitimately not exist yet on a fresh or young site (no donors
 * lapsed in the prior window; no recurring donors active at the
 * window start), and even when they do exist the cohort can be small
 * enough that a real 0% reads as catastrophic without context.
 *
 * Storage returns `{ value, computable, denominator }` for each rate
 * so the UI can:
 *
 *   both !computable    → single section-wide explanatory card
 *   one !computable     → keep the card with data + per-card empty
 *                         note on the other (preserves grid alignment)
 *   both computable     → render the rate as a card, with the
 *                         denominator surfaced inline ("0% of 2 donors")
 *                         so small-cohort 0% reads as honest math
 *                         rather than a catastrophe.
 *
 * - Lapsed donor recovery rate: of donors who lapsed in the prior
 *   window of equal length, the fraction who made a new donation in
 *   the current window. Higher is better.
 * - Recurring donor retention: of recurring donors active at the
 *   window start, the fraction still active now. Higher is better.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { DonorsRateValue, DonorsWindow } from '../../api/donors';
import MetricCard from '../components/MetricCard';
import { formatNumber } from '../components/format';

export interface RetentionSectionProps {
	current: DonorsWindow;
	previous: DonorsWindow | null;
}

const RECOVERY_LABEL = () => __( 'Lapsed donor recovery rate', 'newspack-plugin' );
const RECOVERY_DESCRIPTION = () => __( 'Donors who lapsed in the previous timeframe and returned to donate in this one', 'newspack-plugin' );

const RETENTION_LABEL = () => __( 'Recurring donor retention', 'newspack-plugin' );
const RETENTION_DESCRIPTION = () => __( 'Recurring donors active at the start of this timeframe who are still active now', 'newspack-plugin' );

const cohortSubtitle = ( denominator: number ): string =>
	sprintf(
		/* translators: %s: cohort denominator size, e.g. "of 2 donors" */
		__( 'of %s', 'newspack-plugin' ),
		sprintf(
			/* translators: %s: count of donors in the comparison cohort */
			_n( '%s donor', '%s donors', denominator, 'newspack-plugin' ),
			formatNumber( denominator )
		)
	);

const RetentionSection = ( { current, previous }: RetentionSectionProps ) => {
	const recovery: DonorsRateValue = current.lapsed_donor_recovery_rate;
	const retention: DonorsRateValue = current.recurring_donor_retention;

	const sectionProps = {
		className: 'newspack-insights__section newspack-insights__section--retention',
		'aria-labelledby': 'newspack-insights-donors-retention-heading',
	};

	const heading = (
		<h2 id="newspack-insights-donors-retention-heading" className="newspack-insights__section-heading">
			{ __( 'Retention', 'newspack-plugin' ) }
		</h2>
	);

	if ( ! recovery.computable && ! retention.computable ) {
		return (
			<section { ...sectionProps }>
				{ heading }
				<p className="newspack-insights__section-empty">
					{ __(
						'Retention metrics will appear once your data shows donors lapsing and returning, or recurring donors aging through the selected timeframe.',
						'newspack-plugin'
					) }
				</p>
			</section>
		);
	}

	return (
		<section { ...sectionProps }>
			{ heading }
			<div className="newspack-insights__metric-grid">
				{ recovery.computable ? (
					<MetricCard
						label={ RECOVERY_LABEL() }
						value={ recovery.value }
						format="percent"
						previousValue={ previous?.lapsed_donor_recovery_rate?.computable ? previous.lapsed_donor_recovery_rate.value : null }
						secondary={ cohortSubtitle( recovery.denominator ) }
						description={ RECOVERY_DESCRIPTION() }
					/>
				) : (
					<div className="newspack-insights__metric-card newspack-insights__metric-card--empty">
						<div className="newspack-insights__metric-card-label">{ RECOVERY_LABEL() }</div>
						<p className="newspack-insights__metric-card-empty-note">
							{ __( 'No donors lapsed in the prior timeframe yet.', 'newspack-plugin' ) }
						</p>
					</div>
				) }
				{ retention.computable ? (
					<MetricCard
						label={ RETENTION_LABEL() }
						value={ retention.value }
						format="percent"
						previousValue={ previous?.recurring_donor_retention?.computable ? previous.recurring_donor_retention.value : null }
						secondary={ cohortSubtitle( retention.denominator ) }
						description={ RETENTION_DESCRIPTION() }
					/>
				) : (
					<div className="newspack-insights__metric-card newspack-insights__metric-card--empty">
						<div className="newspack-insights__metric-card-label">{ RETENTION_LABEL() }</div>
						<p className="newspack-insights__metric-card-empty-note">
							{ __( 'No recurring donors at the start of this timeframe.', 'newspack-plugin' ) }
						</p>
					</div>
				) }
			</div>
		</section>
	);
};

export default RetentionSection;
