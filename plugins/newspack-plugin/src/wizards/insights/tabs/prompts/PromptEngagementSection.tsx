/**
 * PromptEngagementSection (NPPD-1607, Section 2).
 *
 * Three scorecards in a single row covering how readers respond to
 * prompts they see (click-through, form submission, dismissal).
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
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import { NOT_CAPABLE_COPY } from './notCapableCopy';
import { NOT_COMPUTABLE_COPY } from './notComputableCopy';
import { scalarToMetricCardProps } from './scalarToCard';
import { Grid } from '../../../../../packages/components/src';

export interface PromptEngagementSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const PromptEngagementSection = ( { current, previous }: PromptEngagementSectionProps ) => (
	<Section
		className="newspack-insights__section newspack-insights__section--engagement"
		aria-labelledby="newspack-insights-prompts-engagement-heading"
	>
		<SectionHeading
			id="newspack-insights-prompts-engagement-heading"
			title={ __( 'Prompt engagement', 'newspack-plugin' ) }
			description={ __(
				'How readers respond to prompts they see. Engagement is any interaction beyond just seeing the prompt.',
				'newspack-plugin'
			) }
		/>
		<Grid columns={ 4 } gutter={ 16 } noMargin>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Click-Through Rate', 'newspack-plugin' ),
					description: __( 'Clicks ÷ prompt impressions', 'newspack-plugin' ),
					current: current.click_through_rate,
					previous: previous?.click_through_rate,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Form Submission Rate', 'newspack-plugin' ),
					description: __( 'Form submissions ÷ impressions on form-bearing prompts', 'newspack-plugin' ),
					current: current.form_submission_rate,
					previous: previous?.form_submission_rate,
					notCapableMessage: NOT_CAPABLE_COPY.formBearing,
					notComputableMessage: NOT_COMPUTABLE_COPY.formBearing,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Dismissal Rate', 'newspack-plugin' ),
					description: __( 'Explicit dismissals ÷ prompt impressions', 'newspack-plugin' ),
					current: current.dismissal_rate,
					previous: previous?.dismissal_rate,
				} ) }
			/>
		</Grid>
	</Section>
);

export default PromptEngagementSection;
