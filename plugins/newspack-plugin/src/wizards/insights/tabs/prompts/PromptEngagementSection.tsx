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
import SectionHeading from '../components/SectionHeading';
import { scalarToMetricCardProps } from './scalarToCard';

export interface PromptEngagementSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
}

const PromptEngagementSection = ( { current, previous }: PromptEngagementSectionProps ) => (
	<section
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
		<div className="newspack-insights__metric-grid">
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
		</div>
	</section>
);

export default PromptEngagementSection;
