/**
 * HowReadersConvertSection (NPPD-1604, Section 4).
 *
 * Funnel (left) + Distribution (right), side-by-side at equal width.
 * Both viz components are tab-local for Phase 1 — when canonical
 * data-viz components land in `packages/components/src/`, swap them
 * in here.
 *
 * Per spec: comparison overlays on these visualizations are deferred
 * to v1.1 — no `previous` consumption here.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { GatesWindow } from '../../api/gates';
import SectionHeading from '../components/SectionHeading';
import Funnel from './viz/Funnel';
import DistributionTable from './viz/DistributionTable';
import SectionState from './SectionState';

export interface HowReadersConvertSectionProps {
	current: GatesWindow;
}

const HowReadersConvertSection = ( { current }: HowReadersConvertSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--convert" aria-labelledby="newspack-insights-gates-convert-heading">
		<SectionHeading
			id="newspack-insights-gates-convert-heading"
			title={ __( 'How readers convert', 'newspack-plugin' ) }
			description={ __(
				'The journey from gate impression to conversion. The funnel shows where readers drop off; the distribution shows how many touches it typically takes before conversion.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__gates-convert-grid">
			<div className="newspack-insights__gates-convert-col">
				<SectionState
					state={ current.conversion_funnel.state }
					emptyMessage={ __(
						'No funnel data yet. The funnel will populate once readers begin moving through your gates.',
						'newspack-plugin'
					) }
				>
					<Funnel stages={ current.conversion_funnel.stages } />
				</SectionState>
			</div>
			<div className="newspack-insights__gates-convert-col">
				<SectionState
					state={ current.exposures_distribution.state }
					emptyMessage={ __( 'No distribution data yet. This will populate once readers begin converting.', 'newspack-plugin' ) }
				>
					<DistributionTable buckets={ current.exposures_distribution.buckets } />
				</SectionState>
			</div>
		</div>
	</section>
);

export default HowReadersConvertSection;
