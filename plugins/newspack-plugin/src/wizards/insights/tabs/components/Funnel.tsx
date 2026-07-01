/**
 * Funnel viz — shared across Insights tabs (Gates, Prompts, Conversion Journey).
 *
 * Self-labeling stacked funnel (NEWS-2586). Each section carries its own label,
 * count, and — for every step after the first — the drop-off descriptors
 * ("X% of {top}" share + "Y% drop-off") INSIDE the section. One uniform layout at
 * every viewport and step count: no side-label/compact split, no separate legend.
 *
 * Each band is an <li> with two layers:
 *   - a clipped trapezoid FILL (CSS clip-path, insets from computeDisplayHalfWidths)
 *     behind the text, forming a continuous silhouette because adjacent band edges
 *     share width; and
 *   - a flowing TEXT layer on top that is NOT clipped, so it wraps, grows the band
 *     height, and is never cut off — when a band's fill is narrower than its text
 *     the text spills over the card (dark text on the faded lower bands keeps it
 *     legible there).
 *
 * Width is CSS-driven (width:100%, max-width cap); the clip-path percentages scale
 * intrinsically, so no JS measurement is needed. The single anchor color
 * (primary-500) fades 1.0 → 0.6 down the funnel; bands above
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

// Coordinate basis for the half-width math and the clip-path percentages.
const VIEWBOX_WIDTH = 320;
const FULL_OPACITY = 1;
const TAIL_OPACITY = 0.6;
// Above this band opacity the fill is dark enough for white text; below it the
// faded band (and any text spilling onto the white card) needs dark text.
const DARK_TEXT_OPACITY_THRESHOLD = 0.75;
const HALF_WIDTH = VIEWBOX_WIDTH / 2;
// The funnel is a rough relative-size viz: no segment is narrower than this share
// of the chart width (avoids razor-thin bands and keeps the lowest band's fill
// under its own text). The max per-segment taper is computed per-funnel from the
// step count so funnels of any length descend evenly (see computeDisplayHalfWidths).
const MIN_SEGMENT_WIDTH_RATIO = 0.28; // NEWS-2586: wider floor keeps the narrowest band's fill under its text.
const MIN_HALF_WIDTH = ( MIN_SEGMENT_WIDTH_RATIO * VIEWBOX_WIDTH ) / 2;

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
 * Per-level display half-width for every stage. Raw width is proportional to the
 * stage's share of the top count, but clamped so the silhouette stays a readable
 * rough viz: never wider than the level above, never below MIN_HALF_WIDTH, and no
 * more than the per-funnel max taper (HALF_WIDTH / stepCount) narrower than the
 * level above. Counts/percentages in the labels are unaffected — only widths clamp.
 */
export const computeDisplayHalfWidths = ( stages: FunnelStage[], topCount: number ): number[] => {
	if ( topCount <= 0 ) {
		return stages.map( () => 0 );
	}
	const maxTaperHalfWidth = HALF_WIDTH / stages.length;
	const halves: number[] = [];
	stages.forEach( ( stage, index ) => {
		const raw = Math.min( HALF_WIDTH, ( stage.count / topCount ) * HALF_WIDTH );
		if ( index === 0 ) {
			halves.push( raw );
			return;
		}
		const prev = halves[ index - 1 ];
		const lower = Math.max( MIN_HALF_WIDTH, prev - maxTaperHalfWidth );
		halves.push( Math.min( prev, Math.max( lower, raw ) ) );
	} );
	return halves;
};

/** Left inset (% of chart width) for a half-width; the right edge is 100 minus it. */
const edgePercent = ( half: number ): number => ( ( HALF_WIDTH - half ) / VIEWBOX_WIDTH ) * 100;

/** CSS clip-path polygon for a trapezoid from a top half-width to a bottom half-width. */
const trapezoidClip = ( halfTop: number, halfBottom: number ): string => {
	const lt = edgePercent( halfTop );
	const lb = edgePercent( halfBottom );
	return `polygon(${ lt }% 0, ${ 100 - lt }% 0, ${ 100 - lb }% 100%, ${ lb }% 100%)`;
};

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

	// Proportions can't be computed without a non-zero first step.
	if ( stages.length === 0 || topCount <= 0 ) {
		return (
			<div className="newspack-insights__funnel">
				<p className="newspack-insights__funnel-empty">{ __( 'Not enough data to chart the funnel.', 'newspack-plugin' ) }</p>
			</div>
		);
	}

	const stepCount = stages.length;
	const halves = computeDisplayHalfWidths( stages, topCount );

	return (
		<ol className="newspack-insights__funnel" aria-label={ __( 'Conversion funnel', 'newspack-plugin' ) }>
			{ stages.map( ( stage, index ) => {
				const opacity = stepOpacity( index, stepCount );
				const halfTop = halves[ index ];
				const halfBottom = index < stepCount - 1 ? halves[ index + 1 ] : halfTop;
				const isDark = opacity > DARK_TEXT_OPACITY_THRESHOLD;
				const pctOfTop = topCount > 0 ? stage.count / topCount : 0;
				const drop = index > 0 ? dropFromPrevious( stage.count, stages[ index - 1 ].count ) : null;
				return (
					<li
						key={ index }
						className={ 'newspack-insights__funnel-step ' + ( isDark ? 'is-on-dark' : 'is-on-light' ) }
						style={ { '--band-opacity': opacity } as React.CSSProperties }
					>
						<span
							className="newspack-insights__funnel-fill"
							style={ { clipPath: trapezoidClip( halfTop, halfBottom ) } }
							aria-hidden="true"
						/>
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
