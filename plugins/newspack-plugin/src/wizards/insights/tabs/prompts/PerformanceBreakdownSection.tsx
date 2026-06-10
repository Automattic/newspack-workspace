/**
 * PerformanceBreakdownSection (NPPD-1607, Section 7).
 *
 * Three stacked sortable tables: by prompt, by intent, by placement.
 * Each is a thin wrapper over the tab-local SortableTable primitive.
 * Phase 1 renders each table's empty-state row; the sort chrome stays
 * visible so it's identical between phases.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsWindow } from '../../api/prompts';
import PerformanceByPromptTable from './viz/PerformanceByPromptTable';
import PerformanceByIntentTable from './viz/PerformanceByIntentTable';
import PerformanceByPlacementTable from './viz/PerformanceByPlacementTable';

export interface PerformanceBreakdownSectionProps {
	current: PromptsWindow;
}

const PerformanceBreakdownSection = ( { current }: PerformanceBreakdownSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--performance"
		aria-labelledby="newspack-insights-prompts-performance-heading"
	>
		<h2 id="newspack-insights-prompts-performance-heading" className="newspack-insights__section-heading">
			{ __( 'Performance breakdown', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'Per-prompt, per-intent, and per-placement breakdowns for the selected timeframe. Click any column to re-sort.',
				'newspack-plugin'
			) }
		</p>
		<PerformanceByPromptTable data={ current.performance_by_prompt } />
		<PerformanceByIntentTable data={ current.performance_by_intent } />
		<PerformanceByPlacementTable data={ current.performance_by_placement } />
	</section>
);

export default PerformanceBreakdownSection;
