/**
 * Tab-local Funnel viz (NPPD-1607, Phase 1).
 *
 * Minimal vertical three-stage funnel used inside Tab 5 only. A
 * tab-local copy of the Gates Funnel (the two tabs render the same
 * three-stage chrome); kept separate so the two tabs stay decoupled
 * until a canonical Funnel component lands in
 * `packages/components/src/`. Keeping the API surface narrow on
 * purpose: a `stages` array, a `pending` flag, and CSS classes the
 * tab-local prompts.scss styles.
 *
 * Phase 1 behavior:
 *   - All stages render at zero
 *   - Drop-off labels are hidden when every stage is 0 (per spec)
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PromptsFunnelData, PromptsFunnelStage } from '../../../api/prompts';
import { formatNumber, formatPercent } from '../../components/format';

export interface FunnelProps {
	data: PromptsFunnelData;
}

const stageWidthPct = ( stage: PromptsFunnelStage, topCount: number ): number => {
	if ( topCount <= 0 ) {
		// Phase 1 / no data: each stage renders at a fixed minimum so
		// the funnel chrome stays visible without implying a value.
		return 40;
	}
	const ratio = stage.count / topCount;
	return Math.max( 12, Math.round( ratio * 100 ) );
};

const Funnel = ( { data }: FunnelProps ) => {
	const { stages } = data;
	const topCount = stages.length > 0 ? stages[ 0 ].count : 0;
	const allZero = stages.every( s => s.count === 0 );

	return (
		<div className="newspack-insights__prompts-funnel" role="figure" aria-label={ __( 'Conversion funnel', 'newspack-plugin' ) }>
			{ stages.map( ( stage, idx ) => {
				const prev = idx > 0 ? stages[ idx - 1 ] : null;
				const dropOffPct = prev && prev.count > 0 ? 1 - stage.count / prev.count : 0;
				const widthPct = stageWidthPct( stage, topCount );
				return (
					<div key={ stage.label } className="newspack-insights__prompts-funnel-row">
						{ idx > 0 && ! allZero && (
							<div className="newspack-insights__prompts-funnel-dropoff">
								{ sprintf(
									/* translators: %s: percentage of readers dropped off between two funnel stages */
									__( '%s drop-off', 'newspack-plugin' ),
									formatPercent( dropOffPct )
								) }
							</div>
						) }
						<div className="newspack-insights__prompts-funnel-stage" style={ { width: `${ widthPct }%` } } data-stage-index={ idx }>
							<div className="newspack-insights__prompts-funnel-stage-label">{ stage.label }</div>
							<div className="newspack-insights__prompts-funnel-stage-count">{ formatNumber( stage.count ) }</div>
							{ idx > 0 && ! allZero && (
								<div className="newspack-insights__prompts-funnel-stage-pct">
									{ sprintf(
										/* translators: %s: percentage of stage-1 readers reaching this stage */
										__( '%s of top', 'newspack-plugin' ),
										formatPercent( stage.pct_of_top )
									) }
								</div>
							) }
						</div>
					</div>
				);
			} ) }
		</div>
	);
};

export default Funnel;
