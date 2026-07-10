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
 *     look); and
 *   - a TEXT layer with the section label + count (white text with a dark shadow,
 *     legible over both the dark upper bands and the faded lower ones).
 * The per-step drop-off descriptors ("X% of {top}" + "Y% drop-off from previous")
 * live in a Popover that floats beside the section and is revealed for the WHOLE
 * funnel on hover/focus (see the hover/focus state in the component).
 *
 * Widths are set inline (maxWidth %) and the separators are trapezoids clipped in
 * CSS (each band's bottom inset supplied inline via --separator-inset), so both
 * scale fluidly with no JS measurement of the DOM.
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
import colors from '../../../../../packages/colors/colors.module.scss';
import { formatNumber, formatPercent } from './format';

// Sections and their trapezoidal separators step through discrete stops of the
// primary color scale (top = darkest, bottom = lightest) rather than fading by
// opacity. Each band takes the next stop down from the top anchor by its position:
// fills run primary-700, 600, 500, … ; separators run primary-200, 100, 050, … .
// A short funnel simply stops early (a 3-step funnel is 700/600/500), and the
// scales are sized for the 5-stage maximum so the index never runs off the end.
const FUNNEL_FILL_SCALE = [ 'primary-700', 'primary-600', 'primary-500', 'primary-400', 'primary-300' ];
const FUNNEL_SEPARATOR_SCALE = [ 'primary-200', 'primary-100', 'primary-050', 'primary-000' ];

/**
 * The scale stop for a band at `index`: one adjacent stop down from the top anchor
 * per position, clamped to the last stop. Resolves the token to its hex via the
 * color map, falling back to the token name if the map can't resolve it (e.g. under
 * the test env's scss stub).
 */
const pickScale = ( scale: string[], index: number ): string => {
	const token = scale[ Math.min( index, scale.length - 1 ) ];
	return colors[ token ] ?? token;
};

// Each band's minimum width, as this fraction of the prior band's width times the
// total number of steps, to avoid extremely narrow funnels. Capped by
// MAX_BAND_TAPER below so the floor can never make a band as wide as the one above.
const MINIMUM_BAND_PROPORTION = 0.12;
// Each band is at most this fraction of the band above it, so widths always
// decrease (a visible taper) — even for very long funnels (where
// MINIMUM_BAND_PROPORTION * stepCount would otherwise exceed 1) or under data drift
// (where a later stage's raw share of the top can exceed the prior band).
const MAX_BAND_TAPER = 0.9;
// Section text stays full size until a section narrows past FONT_SCALE_THRESHOLD of
// the top width, then ramps down linearly toward FONT_SCALE_FLOOR of the top
// section's size (reached only as the width approaches 0) — a gradual reduction
// rather than a hard step at the threshold. Name and count share the one scale, so
// their relative sizes stay constant as they shrink together.
const FONT_SCALE_THRESHOLD = 0.5;
const FONT_SCALE_FLOOR = 0.75;

export interface FunnelStage {
	label: string;
	count: number;
	pct_of_top: number;
}

export interface FunnelProps {
	stages: FunnelStage[];
}

/**
 * Drop-off from the previous step as a fraction in [0, 1]. Clamped at 0: funnel
 * stages are independent aggregates, so a later stage can occasionally exceed the
 * prior one (data drift) — a negative "drop-off" is meaningless, so show 0%.
 */
export const dropFromPrevious = ( count: number, prevCount: number ): number => ( prevCount > 0 ? Math.max( 0, 1 - count / prevCount ) : 0 );

/**
 * The two descriptor strings for every step beyond the first: what share of the top
 * stage reached here, and the stage-to-stage drop-off. Built once and used by both
 * the always-present screen-reader copy and the on-hover Popover.
 */
const formatStepDescriptors = ( pctOfTop: number, drop: number, topLabel: string ) => ( {
	share: sprintf(
		/* translators: 1: percentage, 2: name of the first/top funnel stage (e.g. "Impression"). */
		__( '%1$s of %2$s', 'newspack-plugin' ),
		formatPercent( pctOfTop ),
		topLabel
	),
	drop: sprintf(
		/* translators: %s: percentage drop-off from the previous stage. */
		__( '%s drop-off', 'newspack-plugin' ),
		formatPercent( drop )
	),
} );

/**
 * The hover/focus-revealed Popover carrying a step's descriptors. It's a visual
 * affordance only — the same text is always present in a screen-reader node on the
 * section — so its content is aria-hidden to avoid a duplicate announcement. These
 * describe funnel progression, not a period comparison, so never red/green.
 */
const StepLabels = ( { share, drop }: { share: string; drop: string } ) => (
	<Popover className="newspack-insights__funnel-labels" offset={ 16 } placement="right" shift>
		<div aria-hidden="true">
			<p className="newspack-insights__funnel-label-pct">{ share }</p>
			<p className="newspack-insights__funnel-label-drop">
				<span className="newspack-insights__funnel-label-drop-arrow">↓</span> { drop }
			</p>
		</div>
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
	// Floor multiplier for each band's width, capped at MAX_BAND_TAPER so a long
	// funnel (where MINIMUM_BAND_PROPORTION * stepCount ≥ 1) can't floor a band as
	// wide as the one above.
	const minBandPct = Math.min( MAX_BAND_TAPER, MINIMUM_BAND_PROPORTION * stepCount );
	// Width fraction of each section. The top spans the full width; every lower
	// section follows its share of the top count, but capped at MAX_BAND_TAPER of the
	// band above (so widths strictly decrease, even when data drift pushes a raw
	// share ≥ the prior band) and floored at minBandPct of it (so long funnels don't
	// collapse to slivers).
	const bandWidths: number[] = [];
	stages.forEach( ( stage, index ) => {
		if ( index === 0 ) {
			bandWidths.push( 1 );
			return;
		}
		const prev = bandWidths[ index - 1 ];
		const share = topCount > 0 ? stage.count / topCount : 0;
		bandWidths.push( Math.max( minBandPct * prev, Math.min( MAX_BAND_TAPER * prev, share ) ) );
	} );

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
				// Each band takes a discrete stop of the primary scale (darkest at the
				// top, lightest at the bottom); the separator below it steps through a
				// lighter companion scale. Sampled evenly so a short funnel spans the
				// same endpoints as the 5-stage maximum.
				const fillColor = pickScale( FUNNEL_FILL_SCALE, index );
				const separatorColor = pickScale( FUNNEL_SEPARATOR_SCALE, index );
				const pctOfTop = topCount > 0 ? stage.count / topCount : 0;
				const drop = index > 0 ? dropFromPrevious( stage.count, stages[ index - 1 ].count ) : null;
				// Descriptor strings for steps after the first; rendered both as an
				// always-present screen-reader node and (on hover/focus) the Popover.
				const descriptors = drop !== null ? formatStepDescriptors( pctOfTop, drop, topLabel ) : null;
				const bandWidth = bandWidths[ index ];
				// Text is full size at/above FONT_SCALE_THRESHOLD of the top width, then
				// eases down linearly toward FONT_SCALE_FLOOR as the section narrows —
				// a gradual ramp rather than a step at the threshold.
				const widthRatio = Math.min( 1, bandWidth / FONT_SCALE_THRESHOLD );
				const fontScale = FONT_SCALE_FLOOR + ( 1 - FONT_SCALE_FLOOR ) * widthRatio;
				// The separator below this section is a trapezoid: its top edge spans
				// this (wider) section and its bottom edge insets toward the next
				// (narrower) section's width. The inset is a % of this section (the
				// separator spans its full width); the CSS floors it so the bottom edge
				// never drops below the section min-width. Ratio clamped to ≤ 1 (no flare).
				const nextBandWidth = index < stepCount - 1 ? bandWidths[ index + 1 ] : bandWidth;
				const separatorBottomRatio = bandWidth > 0 ? Math.min( 1, nextBandWidth / bandWidth ) : 1;
				const separatorInsetPct = ( ( 1 - separatorBottomRatio ) / 2 ) * 100;
				return (
					<li
						key={ index }
						className="newspack-insights__funnel-step"
						style={
							{
								'--band-fill': fillColor,
								'--funnel-font-scale': fontScale,
								maxWidth: `${ bandWidth * 100 }%`,
							} as React.CSSProperties
						}
					>
						<span className="newspack-insights__funnel-fill" aria-hidden="true" />
						<span className="newspack-insights__funnel-content">
							<span className="newspack-insights__funnel-label-name">{ stage.label }</span>
							<span className="newspack-insights__funnel-label-count">{ formatNumber( stage.count ) }</span>
							{ descriptors && (
								<>
									<span className="screen-reader-text">{ `${ descriptors.share }. ${ descriptors.drop }.` }</span>
									{ isActive && <StepLabels share={ descriptors.share } drop={ descriptors.drop } /> }
								</>
							) }
						</span>
						{ index < stages.length - 1 && (
							<span
								className="newspack-insights__funnel-separator"
								aria-hidden="true"
								style={
									{
										'--band-separator': separatorColor,
										'--separator-inset': `${ separatorInsetPct }%`,
									} as React.CSSProperties
								}
							/>
						) }
					</li>
				);
			} ) }
		</ol>
	);
};

export default Funnel;
