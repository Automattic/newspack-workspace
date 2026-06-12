/**
 * GateExposureSection (NPPD-1604, Section 1).
 *
 * Top-of-funnel exposure scorecards. Four cards in a single row.
 * The Direct vs Influenced explainer used to live below this
 * section's caption but moved to the tab top (above Section 1) so
 * publishers encounter the framing before any section that uses
 * the terms — see {@see GatesTab}.
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
import type { GatesWindow } from '../../api/gates';
import MetricCard from '../components/MetricCard';
import SectionHeading from '../components/SectionHeading';
import { scalarToMetricCardProps } from './scalarToCard';

export interface GateExposureSectionProps {
	current: GatesWindow;
	previous: GatesWindow | null;
	lastUpdated?: ReactNode;
}

const GateExposureSection = ( { current, previous, lastUpdated }: GateExposureSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--exposure" aria-labelledby="newspack-insights-gates-exposure-heading">
		<div className="newspack-insights__section-header-container">
			<div className="newspack-insights__section-header-text">
				<SectionHeading
					id="newspack-insights-gates-exposure-heading"
					title={ __( 'Gate exposure', 'newspack-plugin' ) }
					description={ __( 'Top of the funnel. How many readers see gates in this timeframe.', 'newspack-plugin' ) }
				/>
			</div>
			{ lastUpdated }
		</div>
		<div className="newspack-insights__metric-grid">
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Total Gate Impressions', 'newspack-plugin' ),
					description: __( 'Every gate view in this timeframe', 'newspack-plugin' ),
					current: current.total_gate_impressions,
					previous: previous?.total_gate_impressions,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Unique Readers Reached', 'newspack-plugin' ),
					description: __( 'Distinct readers who saw at least one gate', 'newspack-plugin' ),
					current: current.unique_readers_reached,
					previous: previous?.unique_readers_reached,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Avg Exposures per Reader', 'newspack-plugin' ),
					description: __( 'How many times a typical reader sees a gate', 'newspack-plugin' ),
					current: current.avg_exposures_per_reader,
					previous: previous?.avg_exposures_per_reader,
				} ) }
			/>
			<MetricCard
				{ ...scalarToMetricCardProps( {
					label: __( 'Sessions With a Gate', 'newspack-plugin' ),
					description: __( '% of sessions that hit at least one gate', 'newspack-plugin' ),
					current: current.sessions_with_gate,
					previous: previous?.sessions_with_gate,
				} ) }
			/>
		</div>
	</section>
);

export default GateExposureSection;
