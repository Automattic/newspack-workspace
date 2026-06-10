/**
 * FreeReaderConversionSection (NPPD-1604, Section 2).
 *
 * Two scorecards side-by-side covering registration-gate conversion
 * (Direct attribution and Influenced 7-day lookback).
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
import { scalarToMetricCardProps } from './scalarToCard';

export interface FreeReaderConversionSectionProps {
	current: GatesWindow;
	previous: GatesWindow | null;
}

const FreeReaderConversionSection = ( { current, previous }: FreeReaderConversionSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--free-reader" aria-labelledby="newspack-insights-gates-free-heading">
		<h2 id="newspack-insights-gates-free-heading" className="newspack-insights__section-heading">
			{ __( 'Free reader conversion', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'How effectively registration gates convert visitors into registered readers. Direct counts registrations that happened in the same session as a registration gate impression. Influenced counts registrations that happened in a later session within 7 days of a registration gate impression.',
				'newspack-plugin'
			) }
		</p>
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
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Regwall Conversion (Influenced, 7d)', 'newspack-plugin' ),
					description: __(
						'Readers who registered in a later session within 7 days of seeing a registration gate ÷ readers who saw a registration gate',
						'newspack-plugin'
					),
					current: current.regwall_conversion_influenced_7d,
					previous: previous?.regwall_conversion_influenced_7d,
				} ) }
			/>
		</div>
	</section>
);

export default FreeReaderConversionSection;
