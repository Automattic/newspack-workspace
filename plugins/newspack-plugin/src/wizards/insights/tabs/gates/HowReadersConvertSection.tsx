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
import Funnel from './viz/Funnel';
import DistributionTable from './viz/DistributionTable';

export interface HowReadersConvertSectionProps {
	current: GatesWindow;
}

const HowReadersConvertSection = ( { current }: HowReadersConvertSectionProps ) => (
	<section className="newspack-insights__section newspack-insights__section--convert" aria-labelledby="newspack-insights-gates-convert-heading">
		<h2 id="newspack-insights-gates-convert-heading" className="newspack-insights__section-heading">
			{ __( 'How readers convert', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">
			{ __(
				'The journey from gate impression to conversion. The funnel shows where readers drop off; the distribution shows how many touches it typically takes before conversion.',
				'newspack-plugin'
			) }
		</p>
		<div className="newspack-insights__gates-convert-grid">
			<div className="newspack-insights__gates-convert-col">
				<Funnel data={ current.conversion_funnel } />
			</div>
			<div className="newspack-insights__gates-convert-col">
				<DistributionTable data={ current.exposures_distribution } />
			</div>
		</div>
	</section>
);

export default HowReadersConvertSection;
