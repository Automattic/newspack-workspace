/**
 * FreeReaderConversionSection (NPPD-1607, Section 3).
 *
 * Four scorecards covering free-conversion intents — registration and
 * newsletter signup — each in Direct and Influenced (7-day lookback)
 * attribution.
 *
 * Direct here is NOT session-scoped. The hub counts `np_reader_registered` /
 * `np_newsletter_subscribed` events carrying a `newspack_popup_id` param — the
 * param is present because the reader submitted the form the prompt itself
 * rendered. There is no session join anywhere in that query. Copy must say
 * "submitted through a prompt's ... block", never "in the same session".
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../packages/components/src';
import type { PromptsWindow } from '../../api/prompts';
import MetricCard from '../components/MetricCard';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import { NOT_CAPABLE_COPY } from './notCapableCopy';
import { NOT_COMPUTABLE_COPY } from './notComputableCopy';
import { scalarToMetricCardProps } from './scalarToCard';

export interface FreeReaderConversionSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const FreeReaderConversionSection = ( { current, previous }: FreeReaderConversionSectionProps ) => (
	<Section className="newspack-insights__section newspack-insights__section--free-reader" aria-labelledby="newspack-insights-prompts-free-heading">
		<SectionHeading
			id="newspack-insights-prompts-free-heading"
			title={ __( 'Free Reader Conversion', 'newspack-plugin' ) }
			description={ __(
				'How effectively prompts convert readers into registered readers and newsletter subscribers. Direct counts conversions submitted through a prompt’s own form. Influenced counts conversions in a later session within 7 days of seeing a prompt.',
				'newspack-plugin'
			) }
		/>
		<Grid columns={ 4 } gutter={ 16 } noMargin>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Registration Conversion (Direct)', 'newspack-plugin' ),
					description: __(
						'Registrations submitted through a prompt’s registration block ÷ impressions of prompts with a registration block',
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
						'Newsletter signups submitted through a prompt’s newsletter block ÷ impressions of prompts with a newsletter block',
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
		</Grid>
	</Section>
);

export default FreeReaderConversionSection;
