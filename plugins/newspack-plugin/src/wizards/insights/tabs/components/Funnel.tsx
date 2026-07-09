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
 * The single anchor color (primary-500) fades 1.0 → 0.6 down the funnel: each
 * band's fill ramps from its own opacity to the next band's, so the bands meet
 * seamlessly and the stack is one continuous gradient — the sole visual
 * differentiator between sections. Bands whose midpoint shade is above
 * DARK_TEXT_OPACITY_THRESHOLD take white text, below it dark text.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber, formatPercent } from './format';

const FULL_OPACITY = 1;
const TAIL_OPACITY = 0.6;
// Above this band opacity the fill is dark enough for white text; below it the
// faded band needs dark text.
const DARK_TEXT_OPACITY_THRESHOLD = 0.75;

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
	<>
		<span className="newspack-insights__funnel-label-pct">
			{ sprintf(
				/* translators: 1: percentage, 2: name of the first/top funnel stage (e.g. "Impression"). */
				__( '%1$s of %2$s', 'newspack-plugin' ),
				formatPercent( pctOfTop ),
				topLabel
			) }
		</span>
		<span className="newspack-insights__funnel-label-drop">
			<span aria-hidden="true" className="newspack-insights__funnel-label-drop-arrow">
				↓
			</span>{ ' ' }
			{ sprintf(
				/* translators: %s: percentage drop-off from the previous stage. */
				__( '%s drop-off', 'newspack-plugin' ),
				formatPercent( drop )
			) }
		</span>
	</>
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

	return (
		<ol className="newspack-insights__funnel" aria-label={ __( 'Conversion funnel', 'newspack-plugin' ) }>
			{ stages.map( ( stage, index ) => {
				// The fill ramps from this step's opacity at the top to the next
				// step's at the bottom, so adjacent bands meet seamlessly and the
				// stack reads as one continuous 1.0 → 0.6 gradient. The last band
				// holds the floor opacity (no step below it to ramp toward).
				const topOpacity = stepOpacity( index, stepCount );
				const bottomOpacity = index < stepCount - 1 ? stepOpacity( index + 1, stepCount ) : topOpacity;
				// Text contrast tracks the band's midpoint shade, since the fill now
				// varies across the band rather than being a single flat opacity.
				const isDark = ( topOpacity + bottomOpacity ) / 2 > DARK_TEXT_OPACITY_THRESHOLD;
				const pctOfTop = topCount > 0 ? stage.count / topCount : 0;
				const drop = index > 0 ? dropFromPrevious( stage.count, stages[ index - 1 ].count ) : null;
				return (
					<li
						key={ index }
						className={ 'newspack-insights__funnel-step ' + ( isDark ? 'is-on-dark' : 'is-on-light' ) }
						style={
							{
								'--band-opacity-top': topOpacity,
								'--band-opacity-bottom': bottomOpacity,
							} as React.CSSProperties
						}
					>
						<span className="newspack-insights__funnel-fill" aria-hidden="true" />
						<span className="newspack-insights__funnel-content">
							<span className="newspack-insights__funnel-label-name">{ stage.label }</span>
							<span className="newspack-insights__funnel-label-count">{ formatNumber( stage.count ) }</span>
							{ drop !== null && <StepLabels pctOfTop={ pctOfTop } drop={ drop } topLabel={ topLabel } /> }
						</span>
					</li>
				);
			} ) }
		</ol>
	);
};

export default Funnel;
