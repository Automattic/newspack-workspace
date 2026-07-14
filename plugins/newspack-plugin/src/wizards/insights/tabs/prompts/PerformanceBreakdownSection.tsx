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
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import type { PromptsWindow } from '../../api/prompts';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import PerformanceByPromptTable from './viz/PerformanceByPromptTable';
import PerformanceByIntentTable from './viz/PerformanceByIntentTable';
import PerformanceByPlacementTable from './viz/PerformanceByPlacementTable';

export interface PerformanceBreakdownSectionProps {
	current: PromptsWindow;
}

const PerformanceBreakdownSection = ( { current }: PerformanceBreakdownSectionProps ) => (
	<Section
		className="newspack-insights__section newspack-insights__section--performance"
		aria-labelledby="newspack-insights-prompts-performance-heading"
	>
		<SectionHeading
			id="newspack-insights-prompts-performance-heading"
			title={ __( 'Performance breakdown', 'newspack-plugin' ) }
			description={ __(
				'Per-prompt, per-intent, and per-placement breakdowns for the selected timeframe. Click any column to re-sort.',
				'newspack-plugin'
			) }
		/>
		<VStack spacing={ 4 }>
			<PerformanceByPromptTable data={ current.performance_by_prompt } />
			<PerformanceByIntentTable data={ current.performance_by_intent } />
			<PerformanceByPlacementTable data={ current.performance_by_placement } />
		</VStack>
	</Section>
);

export default PerformanceBreakdownSection;
