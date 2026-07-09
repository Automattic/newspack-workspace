/**
 * PromptExposureSection (NPPD-1607, Section 1).
 *
 * Top-of-funnel exposure scorecards. Three cards in a single row.
 * The tab-level Direct vs Influenced explainer that used to sit above this
 * section was removed: Direct means a different thing per metric (free =
 * submitted through the prompt's own form; paid = purchased through the
 * prompt's own block), so each card now describes its own mechanism.
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

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

export interface PromptExposureSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
	lastUpdated?: ReactNode;
}

const PromptExposureSection = ( { current, previous, lastUpdated }: PromptExposureSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--exposure" aria-labelledby="newspack-insights-prompts-exposure-heading">
		<SectionHeading
			id="newspack-insights-prompts-exposure-heading"
			title={ __( 'Prompt exposure', 'newspack-plugin' ) }
			description={ __( 'Top of the funnel. How many readers see prompts in this timeframe.', 'newspack-plugin' ) }
			actions={ lastUpdated }
		/>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-3">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Total Prompt Impressions', 'newspack-plugin' ),
					description: __( 'Every prompt view in this timeframe', 'newspack-plugin' ),
					current: current.total_prompt_impressions,
					previous: previous?.total_prompt_impressions,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Unique Readers Reached', 'newspack-plugin' ),
					description: __( 'Distinct readers who saw at least one prompt', 'newspack-plugin' ),
					current: current.unique_readers_reached,
					previous: previous?.unique_readers_reached,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Avg Prompts per Reader', 'newspack-plugin' ),
					description: __( 'How many prompts a typical reader sees', 'newspack-plugin' ),
					current: current.avg_prompts_per_reader,
					previous: previous?.avg_prompts_per_reader,
				} ) }
			/>
		</div>
	</section>
);

export default PromptExposureSection;
