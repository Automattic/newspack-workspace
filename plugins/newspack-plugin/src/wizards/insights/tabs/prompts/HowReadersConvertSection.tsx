/**
 * HowReadersConvertSection (NPPD-1607, Section 6).
 *
 * Funnel (left) + Distribution (right), side-by-side at equal width,
 * matching the Gates treatment. Both viz components are tab-local.
 *
 * Per spec, comparison overlays on these visualizations are deferred
 * — no `previous` consumption here.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsWindow } from '../../api/prompts';
import SectionHeading from '../components/SectionHeading';
import Funnel from './viz/Funnel';
import DistributionTable from './viz/DistributionTable';
import SectionState from './SectionState';

export interface HowReadersConvertSectionProps {
	current: PromptsWindow;
}

const HowReadersConvertSection = ( { current }: HowReadersConvertSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--convert" aria-labelledby="newspack-insights-prompts-convert-heading">
		<SectionHeading
			id="newspack-insights-prompts-convert-heading"
			title={ __( 'How readers convert', 'newspack-plugin' ) }
			description={ __(
				'The journey from prompt impression to conversion. The funnel shows where readers drop off; the distribution shows how many touches it typically takes before conversion.',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__prompts-convert-grid">
			<div className="newspack-insights__prompts-convert-col">
				<SectionState
					state={ current.conversion_funnel.state }
					emptyMessage={ __(
						'No funnel data yet. The funnel will populate once readers begin moving through your prompts.',
						'newspack-plugin'
					) }
				>
					<Funnel stages={ current.conversion_funnel.stages } />
				</SectionState>
			</div>
			<div className="newspack-insights__prompts-convert-col">
				<SectionState
					state={ current.exposures_distribution.state }
					emptyMessage={ __( 'No distribution data yet. This will populate once readers begin converting.', 'newspack-plugin' ) }
				>
					<DistributionTable data={ current.exposures_distribution } />
				</SectionState>
			</div>
		</div>
	</section>
);

export default HowReadersConvertSection;
