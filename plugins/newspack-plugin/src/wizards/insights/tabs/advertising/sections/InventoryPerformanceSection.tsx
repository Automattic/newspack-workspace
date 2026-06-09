/**
 * Advertising › Inventory performance (NPPD-1618, Section 2).
 *
 * Efficiency scorecards: eCPM, fill rate, and viewability. Viewability degrades
 * to a `data_unavailable` overlay (rendered via the shared MetricNote) when the
 * publisher hasn't enabled Active View — handled centrally by Scorecard.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/advertising';
import Scorecard from '../../components/Scorecard';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const InventoryPerformanceSection = ( { current, previous }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-advertising-inventory">
		<h2 id="newspack-insights-advertising-inventory" className="newspack-insights__section-heading">
			{ __( 'Inventory performance', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'How efficiently your inventory monetized.', 'newspack-plugin' ) }</p>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-3">
			<Scorecard
				label={ __( 'Average eCPM', 'newspack-plugin' ) }
				description={ __( 'Your ad rate', 'newspack-plugin' ) }
				current={ current.avg_ecpm }
				previous={ previous?.avg_ecpm }
			/>
			<Scorecard
				label={ __( 'Fill Rate', 'newspack-plugin' ) }
				description={ __( 'How often slots fill', 'newspack-plugin' ) }
				current={ current.fill_rate }
				previous={ previous?.fill_rate }
			/>
			<Scorecard
				label={ __( 'Viewability Rate', 'newspack-plugin' ) }
				description={ __( 'How often ads are seen', 'newspack-plugin' ) }
				current={ current.viewability_rate }
				previous={ previous?.viewability_rate }
			/>
		</div>
	</section>
);

export default InventoryPerformanceSection;
