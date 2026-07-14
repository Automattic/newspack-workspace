/**
 * Audience › Newsletter subscriber value (NEWS-2603 Phase 3).
 *
 * A single at-a-glance card: the modeled 3-year reader-revenue value of a
 * newsletter subscriber — the expected value across the subscription and
 * donation conversion paths (newsletter→paid conversion rate × 3-year supporter
 * CLV, summed). Sourced from local Woo + the hub conversion rates, so — like
 * Registered readers — it is GA4-independent and renders in both AudienceTab
 * branches, including under the connect banner. A window-independent snapshot.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../../packages/components/src';
import type { MetricPayload } from '../../components/metrics';
import MetricCard from '../../components/MetricCard';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';

export interface NewsletterValueSectionProps {
	value: MetricPayload | null | undefined;
}

const NewsletterValueSection = ( { value }: NewsletterValueSectionProps ) => {
	// Usable when the server could model it (either revenue path computable).
	const amount = value && value.computable !== false && typeof value.value === 'number' ? value.value : null;
	// A hub failure or a no-WooCommerce setup is not the same as "insufficient
	// history" — surface those distinctly so the em-dash copy isn't misleading.
	const hasError = !! value?.error;
	const notConfigured = !! value?.not_configured;
	// Hub snapshot cache miss being backfilled (NEWS-2603): a transient, expected
	// condition — render the "still calculating" note, not the insufficient-
	// history copy.
	const warming = value?.state === 'warming';

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-newsletter-value">
			<SectionHeading
				id="newspack-insights-audience-newsletter-value"
				title={ __( 'Newsletter Subscriber Value', 'newspack-plugin' ) }
				description={ __( 'What your newsletter audience is worth as future reader revenue.', 'newspack-plugin' ) }
			/>
			<Grid columns={ 4 } gutter={ 16 } noMargin>
				<MetricCard
					label={ __( 'Value per Newsletter Subscriber', 'newspack-plugin' ) }
					value={ amount ?? 0 }
					format="currency"
					error={ value?.error }
					notConfigured={ notConfigured }
					warming={ warming }
					notComputableMessage={
						amount === null && ! hasError && ! notConfigured && ! warming
							? __( 'Not enough newsletter or supporter history to model yet.', 'newspack-plugin' )
							: undefined
					}
					description={ __(
						'Modeled 3-year reader revenue per newsletter signup, across subscriptions and donations. An estimate.',
						'newspack-plugin'
					) }
				/>
			</Grid>
		</Section>
	);
};

export default NewsletterValueSection;
