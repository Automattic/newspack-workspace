/**
 * Funnel viz — shared across Insights tabs (Gates, Prompts, Conversion Journey).
 *
 * Alternative "stepped" treatment (NEWS-2586). The sections are rectangles of
 * DECREASING width: the top section spans the component's max width and each lower
 * section is sized in proportion to the top count, floored at a minimum so long
 * funnels don't collapse to slivers. Between every pair of sections a trapezoidal
 * SEPARATOR connects the wider section above to the narrower one below.
 *
 * Each section is an <li> with:
 *   - a gradient FILL behind the text (primary-600, opacity fading 1.0 → 0.6 down
 *     the funnel — each band ramps around its own shade for a distinct-sections
 *     look; white text above DARK_TEXT_OPACITY_THRESHOLD, dark text below); and
 *   - a TEXT layer with the section label + count.
 * The per-step drop-off descriptors ("X% of {top}" + "Y% drop-off from previous")
 * live in a Popover that floats beside the section and is revealed for the WHOLE
 * funnel on hover/focus (see the hover/focus state in the component).
 *
 * Widths are set inline (maxWidth %) and the separators are trapezoids via an
 * inline clip-path, so both scale fluidly with no JS measurement of the DOM.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Popover } from '@wordpress/components';
import { useState } from '@wordpress/element';

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
		<p className="newspack-insights__funnel-label-pct">
			{ sprintf(
				/* translators: 1: percentage, 2: name of the first/top funnel stage (e.g. "Impression"). */
				__( '%1$s of %2$s', 'newspack-plugin' ),
				formatPercent( pctOfTop ),
				topLabel
			) }
		</p>
		<p className="newspack-insights__funnel-label-drop">
			<span aria-hidden="true" className="newspack-insights__funnel-label-drop-arrow">
				↓
			</span>{ ' ' }
			{ sprintf(
				/* translators: %s: percentage drop-off from the previous stage. */
				__( '%s drop-off', 'newspack-plugin' ),
				formatPercent( drop )
			) }
		</p>
	</Popover>
);

const Funnel = ( { stages }: FunnelProps ) => {
	// The floating label Popovers are revealed for the whole funnel while it is
	// hovered or holds focus; tracking hover and focus separately keeps the labels
	// up when the pointer leaves but keyboard focus is still inside, and vice versa.
	const [ isHovered, setIsHovered ] = useState( false );
	const [ isFocused, setIsFocused ] = useState( false );
	const isActive = isHovered || isFocused;

	// Blur only deactivates when focus actually leaves the funnel, not when it moves
	// between sections inside it.
	const handleBlur = ( event: React.FocusEvent< HTMLOListElement > ) => {
		if ( ! event.currentTarget.contains( event.relatedTarget as Node | null ) ) {
			setIsFocused( false );
		}
	};

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
		<ol
			className="newspack-insights__funnel"
			aria-label={ __( 'Conversion funnel', 'newspack-plugin' ) }
			tabIndex={ 0 }
			onMouseEnter={ () => setIsHovered( true ) }
			onMouseLeave={ () => setIsHovered( false ) }
			onFocus={ () => setIsFocused( true ) }
			onBlur={ handleBlur }
		>
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
				// The separator below this section is a trapezoid: its top edge spans
				// this (wider) section and its bottom edge is clipped to the next
				// (narrower) section's width. The ratio is relative to this section,
				// since the separator spans its full width. Clamped to ≤ 1 (no flare).
				const nextBandWidth = index < stepCount - 1 ? Math.max( minBandPct * bandWidth, stages[ index + 1 ].count / topCount ) : bandWidth;
				const separatorBottomRatio = bandWidth > 0 ? Math.min( 1, nextBandWidth / bandWidth ) : 1;
				const separatorInsetPct = ( ( 1 - separatorBottomRatio ) / 2 ) * 100;
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
							{ drop !== null && isActive && <StepLabels pctOfTop={ pctOfTop } drop={ drop } topLabel={ topLabel } /> }
						</span>
						{ index < stages.length - 1 && (
							<span
								className="newspack-insights__funnel-separator"
								aria-hidden="true"
								style={ {
									opacity: bandOpacity,
									clipPath: `polygon(0 0, 100% 0, ${ 100 - separatorInsetPct }% 100%, ${ separatorInsetPct }% 100%)`,
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
