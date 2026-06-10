/**
 * FreeReaderConversionSection (NPPD-1607, Section 3).
 *
 * Four scorecards covering free-conversion intents — registration and
 * newsletter signup — each in Direct and Influenced (7-day lookback)
 * attribution.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsWindow } from '../../api/prompts';
import MetricCard from '../components/MetricCard';
import { scalarToMetricCardProps } from './scalarToCard';

export interface FreeReaderConversionSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const FreeReaderConversionSection = ( { current, previous }: FreeReaderConversionSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--free-reader" aria-labelledby="newspack-insights-prompts-free-heading">
		<h2 id="newspack-insights-prompts-free-heading" className="newspack-insights__section-heading">
			{ __( 'Free reader conversion', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'How effectively prompts convert readers into registered readers and newsletter subscribers. Direct counts conversions in the same session as a prompt impression. Influenced counts conversions in a later session within 7 days of seeing a prompt.',
				'newspack-plugin'
			) }
		</p>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Registration Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Sessions with a registration after a registration-intent prompt impression ÷ sessions with a registration-intent prompt impression',
						'newspack-plugin'
					),
					current: current.registration_conversion_direct,
					previous: previous?.registration_conversion_direct,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Registration Conversion (Influenced, 7d)', 'newspack-plugin' ),
					description: __(
						'Readers who registered in a later session within 7 days of seeing a registration-intent prompt ÷ readers who saw a registration-intent prompt',
						'newspack-plugin'
					),
					current: current.registration_conversion_influenced_7d,
					previous: previous?.registration_conversion_influenced_7d,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Newsletter Signup Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Sessions with a newsletter signup after a newsletter-intent prompt impression ÷ sessions with a newsletter-intent prompt impression',
						'newspack-plugin'
					),
					current: current.newsletter_signup_conversion_direct,
					previous: previous?.newsletter_signup_conversion_direct,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Newsletter Signup Conversion (Influenced, 7d)', 'newspack-plugin' ),
					description: __(
						'Readers who signed up for a newsletter in a later session within 7 days of seeing a newsletter-intent prompt ÷ readers who saw a newsletter-intent prompt',
						'newspack-plugin'
					),
					current: current.newsletter_signup_conversion_influenced_7d,
					previous: previous?.newsletter_signup_conversion_influenced_7d,
				} ) }
			/>
		</div>
	</section>
);

export default FreeReaderConversionSection;
