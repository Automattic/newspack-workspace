/**
 * Funnel viz — shared across Insights tabs (Gates, Prompts, Conversion Journey).
 *
 * Self-labeling stacked funnel (NEWS-2586). Each section carries its own label,
 * count, and — for every step after the first — the drop-off descriptors
 * ("X% of {top}" share + "Y% drop-off") INSIDE the section. One uniform layout at
 * every viewport and step count: no side-label/compact split, no separate legend.
 *
 * The sections are equal-width rectangles (the overall silhouette is a rectangle,
 * not a tapering funnel): the drop-off between steps is conveyed by the labels and
 * by color alone, not by width. Each band is a full-width <li> with two layers:
 *   - a gradient FILL behind the text; and
 *   - a flowing TEXT layer on top that wraps and grows the band height.
 *
 * Width is CSS-driven (width:100%, max-width cap), so no JS measurement is needed.
 * The single anchor color (primary-600) fades 1.0 → 0.6 down the funnel — the sole
 * visual differentiator between sections. Each band's fill is a vertical gradient
 * ramping symmetrically around its own shade (darker top → lighter bottom); because
 * adjacent bands don't share a boundary value, a visible step keeps the sections
 * distinct. Bands whose shade is above DARK_TEXT_OPACITY_THRESHOLD take white text,
 * below it dark text.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Popover } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { formatNumber, formatPercent } from './format';

const FULL_OPACITY = 1;
const TAIL_OPACITY = 0.6;
// Above this band opacity the fill is dark enough for white text; below it the
// faded band needs dark text.
const DARK_TEXT_OPACITY_THRESHOLD = 0.4;
// Distinct-sections gradient: each band ramps symmetrically around its own shade
// by this fraction of the step-to-step opacity gap (darker top → lighter bottom).
const INTERNAL_GRADIENT_FRACTION = 0.5;
// The bottom of the gradient should be a little less opaque than the top of the
// next band, so the bands are visually distinct.
const INTERNAL_GRADIENT_FLOOR = 0.15;
// Each band should be no less than this fraction of the prior band's width, to
// avoid extremely narrow funnels.

export interface FunnelStage {
	label: string;
	count: number;
	pct_of_top: number;
}

export interface FunnelProps {
	stages: FunnelStage[];
}

/** Linear opacity from 1.0 at the first step to 0.6 at the last. */
export const stepOpacity = ( index: number, stepCount: number ): number => {
	if ( stepCount <= 1 ) {
		return FULL_OPACITY;
	}
	return FULL_OPACITY + ( TAIL_OPACITY - FULL_OPACITY ) * ( index / ( stepCount - 1 ) );
};

/**
 * Drop-off from the previous step as a fraction in [0, 1]. Clamped at 0: funnel
 * stages are independent aggregates, so a later stage can occasionally exceed the
 * prior one (data drift) — a negative "drop-off" is meaningless, so show 0%.
 */
export const dropFromPrevious = ( count: number, prevCount: number ): number => ( prevCount > 0 ? Math.max( 0, 1 - count / prevCount ) : 0 );

/**
 * The two descriptive lines for every step beyond the first: what share of the top
 * stage reached here, and the stage-to-stage drop-off. Color is inherited from the
 * band (white on dark bands, dark on faded ones) — these describe funnel
 * progression, not a period comparison, so never red/green.
 */
const StepLabels = ( { pctOfTop, drop, topLabel }: { pctOfTop: number; drop: number; topLabel: string } ) => (
	<Popover className="newspack-insights__funnel-labels" offset={ 16 } placement="right" shift>
		<span className="newspack-insights__funnel-label-pct">
			{ sprintf(
				/* translators: 1: percentage, 2: name of the first/top funnel stage (e.g. "Impression"). */
				__( '%1$s of %2$s', 'newspack-plugin' ),
				formatPercent( pctOfTop ),
				topLabel
			) }
		</span>{ ' ' }
		<span className="newspack-insights__funnel-label-drop">
			<span aria-hidden="true" className="newspack-insights__funnel-label-drop-arrow">
				↓
			</span>{ ' ' }
			{ sprintf(
				/* translators: %s: percentage drop-off from the previous stage. */
				__( '%s drop-off from previous', 'newspack-plugin' ),
				formatPercent( drop )
			) }
		</span>
	</Popover>
);

const Funnel = ( { stages }: FunnelProps ) => {
	const topCount = stages.length > 0 ? stages[ 0 ].count : 0;
	const topLabel = stages.length > 0 ? stages[ 0 ].label : '';

	// Share-of-top and drop-off percentages can't be computed without a non-zero
	// first step.
	if ( stages.length === 0 || topCount <= 0 ) {
		return (
			<div className="newspack-insights__funnel">
				<p className="newspack-insights__funnel-empty">{ __( 'Not enough data to chart the funnel.', 'newspack-plugin' ) }</p>
			</div>
		);
	}

	const stepCount = stages.length;
	const minBandPct = 0.15 * stepCount;
	// Half the internal ramp per band, in opacity units. Derived from the step gap
	// so the sheen scales with the funnel length (subtler on longer funnels).
	const stepGap = stepCount > 1 ? ( FULL_OPACITY - TAIL_OPACITY ) / ( stepCount - 1 ) : 0;
	const halfSpan = stepGap * INTERNAL_GRADIENT_FRACTION;
	let prevBandWidth = 1;

	return (
		<ol className="newspack-insights__funnel" aria-label={ __( 'Conversion funnel', 'newspack-plugin' ) }>
			{ stages.map( ( stage, index ) => {
				// Each band ramps symmetrically around its own shade (darker top →
				// lighter bottom). Adjacent bands do NOT share a boundary value, so a
				// visible step separates the sections. Clamped to a valid [0,1] alpha.
				const bandOpacity = stepOpacity( index, stepCount );
				const topOpacity = Math.min( FULL_OPACITY, bandOpacity + halfSpan );
				const bottomOpacity = Math.max( 0, bandOpacity - halfSpan - INTERNAL_GRADIENT_FLOOR );
				// Contrast tracks the band's own shade (its ramp midpoint).
				const isDark = bandOpacity > DARK_TEXT_OPACITY_THRESHOLD;
				const pctOfTop = topCount > 0 ? stage.count / topCount : 0;
				const drop = index > 0 ? dropFromPrevious( stage.count, stages[ index - 1 ].count ) : null;
				const bandWidth = Math.max( minBandPct * prevBandWidth, pctOfTop );
				prevBandWidth = bandWidth;
				return (
					<li
						key={ index }
						className={ 'newspack-insights__funnel-step ' + ( isDark ? 'is-on-dark' : 'is-on-light' ) }
						style={
							{
								'--band-opacity-top': topOpacity,
								'--band-opacity-bottom': bottomOpacity,
								maxWidth: `${ bandWidth * 100 }%`,
							} as React.CSSProperties
						}
					>
						<span className="newspack-insights__funnel-fill" aria-hidden="true" />
						<span className="newspack-insights__funnel-content">
							<span className="newspack-insights__funnel-label-name">{ stage.label }</span>
							<span className="newspack-insights__funnel-label-count">{ formatNumber( stage.count ) }</span>
							{ drop !== null && <StepLabels pctOfTop={ pctOfTop } drop={ drop } topLabel={ topLabel } /> }
						</span>
						{ index < stages.length - 1 && (
							<span
								className="newspack-insights__funnel-separator"
								style={ {
									opacity: bandOpacity,
								} }
							/>
						) }
					</li>
				);
			} ) }
		</ol>
	);
};

export default Funnel;
