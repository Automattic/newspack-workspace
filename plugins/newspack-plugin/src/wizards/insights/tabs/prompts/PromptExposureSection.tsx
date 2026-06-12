/**
 * PromptExposureSection (NPPD-1607, Section 1).
 *
 * Top-of-funnel exposure scorecards. Three cards in a single row.
 * The Direct vs Influenced explainer lives at the tab top (above this
 * section) so publishers encounter the framing before any section
 * that uses the terms — see {@see PromptsTab}.
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
import { scalarToMetricCardProps } from './scalarToCard';

export interface PromptExposureSectionProps {
	current: PromptsWindow;
	previous: PromptsWindow | null;
	lastUpdated?: ReactNode;
}

const PromptExposureSection = ( { current, previous, lastUpdated }: PromptExposureSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--exposure" aria-labelledby="newspack-insights-prompts-exposure-heading">
		<div className="newspack-insights__section-header-container">
			<div className="newspack-insights__section-header-text">
				<h2 id="newspack-insights-prompts-exposure-heading" className="newspack-insights__section-heading">
					{ __( 'Prompt exposure', 'newspack-plugin' ) }
				</h2>
				<p className="newspack-insights__section-caption">
					{ __( 'Top of the funnel. How many readers see prompts in this timeframe.', 'newspack-plugin' ) }
				</p>
			</div>
			{ lastUpdated }
		</div>
		<div className="newspack-insights__metric-grid">
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
