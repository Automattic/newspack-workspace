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
import SectionHeading from '../components/SectionHeading';
import { NOT_CAPABLE_COPY } from './notCapableCopy';
import { NOT_COMPUTABLE_COPY } from './notComputableCopy';
import { scalarToMetricCardProps } from './scalarToCard';

export interface FreeReaderConversionSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const FreeReaderConversionSection = ( { current, previous }: FreeReaderConversionSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--free-reader" aria-labelledby="newspack-insights-prompts-free-heading">
		<SectionHeading
			id="newspack-insights-prompts-free-heading"
			title={ __( 'Free reader conversion', 'newspack-plugin' ) }
			description={ __(
				'How effectively prompts convert readers into registered readers and newsletter subscribers. Direct counts conversions in the same session as a prompt impression. Influenced counts conversions in a later session within 7 days of seeing a prompt.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Registration Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Sessions with a registration after a prompt impression with a registration block ÷ sessions with a prompt impression with a registration block',
						'newspack-plugin'
					),
					current: current.registration_conversion_direct,
					previous: previous?.registration_conversion_direct,
					notCapableMessage: NOT_CAPABLE_COPY.registration,
					notComputableMessage: NOT_COMPUTABLE_COPY.registration,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Registration Conversion (Influenced, 7d)', 'newspack-plugin' ),
					description: __(
						'Registrants whose registration followed a registration-prompt exposure in a prior session within 7 days ÷ all new registrations',
						'newspack-plugin'
					),
					current: current.registration_conversion_influenced_7d,
					previous: previous?.registration_conversion_influenced_7d,
					notCapableMessage: NOT_CAPABLE_COPY.registration,
					notComputableMessage: NOT_COMPUTABLE_COPY.registrationInfluenced,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Newsletter Signup Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Sessions with a newsletter signup after a prompt impression with a newsletter block ÷ sessions with a prompt impression with a newsletter block',
						'newspack-plugin'
					),
					current: current.newsletter_signup_conversion_direct,
					previous: previous?.newsletter_signup_conversion_direct,
					notCapableMessage: NOT_CAPABLE_COPY.newsletter,
					notComputableMessage: NOT_COMPUTABLE_COPY.newsletter,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Newsletter Signup Conversion (Influenced, 7d)', 'newspack-plugin' ),
					description: __(
						'New newsletter subscribers whose signup followed a newsletter-prompt exposure in a prior session within 7 days ÷ all new newsletter signups',
						'newspack-plugin'
					),
					current: current.newsletter_signup_conversion_influenced_7d,
					previous: previous?.newsletter_signup_conversion_influenced_7d,
					notCapableMessage: NOT_CAPABLE_COPY.newsletter,
					notComputableMessage: NOT_COMPUTABLE_COPY.newsletterInfluenced,
				} ) }
			/>
		</div>
	</section>
);

export default FreeReaderConversionSection;
