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
import Funnel from './viz/Funnel';
import DistributionTable from './viz/DistributionTable';

export interface HowReadersConvertSectionProps {
	current: PromptsWindow;
}

const HowReadersConvertSection = ( { current }: HowReadersConvertSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--convert" aria-labelledby="newspack-insights-prompts-convert-heading">
		<h2 id="newspack-insights-prompts-convert-heading" className="newspack-insights__section-heading">
			{ __( 'How readers convert', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'The journey from prompt impression to conversion. The funnel shows where readers drop off; the distribution shows how many touches it typically takes before conversion.',
				'newspack-plugin'
			) }
		</p>
		<div className="newspack-insights__prompts-convert-grid">
			<div className="newspack-insights__prompts-convert-col">
				<Funnel stages={ current.conversion_funnel.stages } />
			</div>
			<div className="newspack-insights__prompts-convert-col">
				<DistributionTable data={ current.exposures_distribution } />
			</div>
		</div>
	</section>
);

export default HowReadersConvertSection;
