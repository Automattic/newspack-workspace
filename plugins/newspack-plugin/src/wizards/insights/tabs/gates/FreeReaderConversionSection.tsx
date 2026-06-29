/**
 * FreeReaderConversionSection (NPPD-1604, Section 2; empty states NPPD-1702).
 *
 * Two scorecards side-by-side covering registration-gate conversion
 * (Direct attribution and Influenced 7-day lookback).
 *
 * Mirrors the Paid section's empty-state treatment (NPPD-1694), with one
 * production-safety distinction that is the whole point of NPPD-1702: the count
 * fields this section reads (`registration_impressions_total` /
 * `registrations_total`) come from a hub BigQuery change that may ship AFTER
 * this code. So detection is THREE-way, not two:
 *   - fields ABSENT (hub not deployed) → render exactly today's behavior: the
 *     regwall percentage scorecards, no empty state. Graceful degradation.
 *   - fields present, impressions === 0 → `no_opportunity`
 *   - fields present, impressions > 0 but registrations === 0 → `no_conversions`
 *     (with the impressions count as {N})
 *   - fields present, normal data → the scorecards, each carrying its count
 *     fallback so an individual zero card reads as "0 of N" rather than 0%.
 *
 * An absent field is NOT a zero: a present 0 is a real signal, a missing field
 * is "the hub hasn't shipped." Collapsing the two would silently degrade a
 * working production section — the exact failure this ticket exists to prevent.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { GatesWindow } from '../../api/gates';
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import EmptyMetricSection from '../components/EmptyMetricSection';
import { scalarToMetricCardProps } from './scalarToCard';

export interface FreeReaderConversionSectionProps {
	current: GatesWindow;
	previous: GatesWindow | null;
}

const HEADING_ID = 'newspack-insights-gates-free-heading';

const FreeReaderConversionSection = ( { current, previous }: FreeReaderConversionSectionProps ) => {
	const title = __( 'Free reader conversion', 'newspack-plugin' );
	const caption = __(
		'How effectively registration gates convert visitors into registered readers. Direct counts registrations that happened in the same session as a registration gate impression. Influenced counts registrations that happened in a later session within 7 days of a registration gate impression.',
		'newspack-plugin'
	);
	const impressionsLabel = __( 'registration gate impressions', 'newspack-plugin' );
	// NPPD-1821: the Influenced rate is converter-denominated, so its count fallback
	// reads "0 of N new registrations" (the denominator is all new registrations),
	// not gate impressions like the Direct card.
	const newRegistrationsLabel = __( 'new registrations', 'newspack-plugin' );

	const impressions = current.registration_impressions_total;
	const registrations = current.registrations_total;

	// Field PRESENCE, not value. The hub count fields land in a separate Newspack
	// Manager deploy that may follow this PR; until then they are absent (null) and
	// the section must render exactly as it does today — percentages, no empty
	// state. `typeof === 'number'` distinguishes a present 0 (a real "no
	// impressions" signal) from an absent field (null). Keyed on impressions (the
	// denominator / {N}), mirroring the Paid section keying off the Direct denominator.
	const fieldsPresent = typeof impressions === 'number';

	// As on the Paid side: a zero total is only a *genuine* empty state when both
	// source scalars actually computed. If either regwall query errored we fall
	// through to the scorecards so each card surfaces its own error treatment
	// rather than a misleading empty state. (Direct and Influenced are separate
	// queries and can fail independently.)
	const dataKnown = current.regwall_conversion_direct.state !== 'error' && current.regwall_conversion_influenced_7d.state !== 'error';

	// Empty states (NPPD-1702). Gated on field presence first — absent fields never
	// reach here. Order matters: no opportunity before no conversions.
	if ( fieldsPresent && dataKnown && impressions === 0 ) {
		return (
			<EmptyMetricSection
				title={ title }
				caption={ caption }
				state="no_opportunity"
				body={ __(
					'No registration gate impressions in this timeframe. Your registration gates may not be reaching readers in this date range. See the per-gate breakdown below.',
					'newspack-plugin'
				) }
			/>
		);
	}
	if ( fieldsPresent && dataKnown && registrations === 0 ) {
		return (
			<EmptyMetricSection
				title={ title }
				caption={ caption }
				state="no_conversions"
				signalCount={ impressions }
				body={ __(
					'No registrations from gates in this timeframe. Your registration gates reached {N} readers, but none completed registration within the 7-day attribution window. Worth a look at your registration prompt or value proposition. See the per-gate breakdown below.',
					'newspack-plugin'
				) }
			/>
		);
	}

	return (
		<section className="newspack-insights__section newspack-insights__section--free-reader" aria-labelledby={ HEADING_ID }>
			<SectionHeading id={ HEADING_ID } title={ title } description={ caption } />
			<div className="newspack-insights__metric-grid newspack-insights__metric-grid--pair">
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Regwall Conversion (Direct)', 'newspack-plugin' ),
						description: __(
							'Sessions with a registration after a registration gate impression ÷ sessions with a registration gate impression',
							'newspack-plugin'
						),
						current: current.regwall_conversion_direct,
						previous: previous?.regwall_conversion_direct,
						// Count fallback (NPPD-1702). When the hub fields are absent the
						// scalar's numerator/denominator are null → undefined here, and
						// MetricCard falls through to the normal percentage render — today's
						// behavior. When present, a zero card reads "0 of N" instead of "0%".
						zeroFallback: {
							numerator: current.regwall_conversion_direct.numerator ?? undefined,
							denominator: current.regwall_conversion_direct.denominator ?? undefined,
							attemptsLabel: impressionsLabel,
						},
					} ) }
				/>
				<MetricCard
					{ ...scalarToMetricCardProps( {
						label: __( 'Regwall Conversion (Influenced, 7d)', 'newspack-plugin' ),
						description: __(
							'Registrants whose registration followed a registration-gate view in a prior session within 7 days ÷ all new registrations',
							'newspack-plugin'
						),
						current: current.regwall_conversion_influenced_7d,
						previous: previous?.regwall_conversion_influenced_7d,
						zeroFallback: {
							numerator: current.regwall_conversion_influenced_7d.numerator ?? undefined,
							denominator: current.regwall_conversion_influenced_7d.denominator ?? undefined,
							attemptsLabel: newRegistrationsLabel,
						},
					} ) }
				/>
			</div>
		</section>
	);
};

export default FreeReaderConversionSection;
