/**
 * Funnel viz (NPPD-1607) — tab-local to Prompts.
 *
 * Multi-step conversion funnel rendered as stacked SVG trapezoids. Each
 * trapezoid's top edge is proportional to its own count and its bottom edge to
 * the next step's count, so the silhouette narrows with drop-off; the last step
 * is a rectangle. A single anchor color (primary-500) fades from full opacity at
 * the top to 0.6 at the bottom, so each stage carries its own weight.
 *
 * A tab-local copy of the Gates funnel (adopted from PR #267): the two tabs
 * render the same three-stage chrome. Kept separate so the tabs stay decoupled
 * until a canonical Funnel lands in packages/components/src/.
 *
 * Two layouts, auto-selected from container width + step count:
 *   - Side-label (default): step name / count / labels in a fixed column to the
 *     right of each trapezoid. Used when stepCount < 5 AND width >= 480px.
 *   - Compact: the count renders inside each trapezoid and the full
 *     names/counts/labels move to a legend below. Used when stepCount >= 5 OR
 *     width < 480px.
 *
 * Phase 1: every stage count is zero, so the first-step-zero guard renders the
 * empty state ("Not enough data to chart the funnel.") — the same treatment
 * Gates shows for a zero-data window.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { PromptsFunnelStage } from '../../../api/prompts';
import { formatNumber, formatPercent } from '../../components/format';

const COMPACT_MIN_STEPS = 5;
const COMPACT_MAX_WIDTH = 480; // Below this container width, force compact mode.
const MIN_STEP_HEIGHT = 32;
const MAX_CHART_HEIGHT = 480;
const VIEWBOX_WIDTH = 320;
const FULL_OPACITY = 1;
const TAIL_OPACITY = 0.6;
// Above this band opacity the fill is dark enough for white text; below it, the
// faded band needs dark text. Only affects compact mode (count rendered inside).
const DARK_TEXT_OPACITY_THRESHOLD = 0.75;

export interface FunnelProps {
	stages: PromptsFunnelStage[];
}

/** Compact when there are many steps OR the container is narrow. */
export const isCompactMode = ( stepCount: number, containerWidth: number ): boolean =>
	stepCount >= COMPACT_MIN_STEPS || containerWidth < COMPACT_MAX_WIDTH;

/** Linear opacity from 1.0 at the first step to 0.6 at the last. */
export const stepOpacity = ( index: number, stepCount: number ): number => {
	if ( stepCount <= 1 ) {
		return FULL_OPACITY;
	}
	return FULL_OPACITY + ( TAIL_OPACITY - FULL_OPACITY ) * ( index / ( stepCount - 1 ) );
};

/**
 * Drop-off from the previous step as a fraction in [0, 1]. Clamped at 0: funnel
 * stages are independent aggregates, so a later stage can occasionally exceed
 * the prior one (data drift) — a negative "drop-off" is meaningless, so show 0%.
 */
export const dropFromPrevious = ( count: number, prevCount: number ): number => ( prevCount > 0 ? Math.max( 0, 1 - count / prevCount ) : 0 );

/** Equal band height per step, capped so the whole chart fits ~480px. */
const stepHeightFor = ( stepCount: number ): number => Math.max( MIN_STEP_HEIGHT, Math.floor( MAX_CHART_HEIGHT / stepCount ) );

/**
 * SVG path for one trapezoid: top edge sized to `count`, bottom to `nextCount`,
 * centered. The bottom is clamped to never exceed the top so the silhouette only
 * ever narrows (a funnel never flares outward, even on anomalous data).
 */
const trapezoidPath = ( count: number, nextCount: number, topCount: number, yTop: number, yBottom: number ): string => {
	const cx = VIEWBOX_WIDTH / 2;
	// Clamp the top edge to the viewBox half-width too (not just the bottom):
	// funnel stages are independent aggregates, so a later stage can exceed the
	// top stage (data drift). Without this the trapezoid would flare past the
	// viewBox edge — the opposite of the "only ever narrows" invariant below.
	const halfTop = Math.min( VIEWBOX_WIDTH / 2, ( count / topCount ) * ( VIEWBOX_WIDTH / 2 ) );
	const halfBottom = Math.min( halfTop, ( nextCount / topCount ) * ( VIEWBOX_WIDTH / 2 ) );
	return [
		`M ${ cx - halfTop } ${ yTop }`,
		`L ${ cx + halfTop } ${ yTop }`,
		`L ${ cx + halfBottom } ${ yBottom }`,
		`L ${ cx - halfBottom } ${ yBottom }`,
		'Z',
	].join( ' ' );
};

interface StepView {
	stage: PromptsFunnelStage;
	index: number;
	opacity: number;
	pctOfTop: number;
	drop: number | null;
}

/** Build the per-step view model shared by both layouts. */
const buildSteps = ( stages: PromptsFunnelStage[], topCount: number ): StepView[] =>
	stages.map( ( stage, index ) => {
		const prevCount = index > 0 ? stages[ index - 1 ].count : 0;
		return {
			stage,
			index,
			opacity: stepOpacity( index, stages.length ),
			pctOfTop: topCount > 0 ? stage.count / topCount : 0,
			drop: index > 0 ? dropFromPrevious( stage.count, prevCount ) : null,
		};
	} );

/**
 * The two descriptive labels shown for every step beyond the first: what share
 * of the top stage reached here, and the stage-to-stage drop-off. Both are muted
 * (not red/green) — they describe funnel progression, not a period comparison.
 */
const StepLabels = ( { step, topLabel }: { step: StepView; topLabel: string } ) => {
	if ( step.drop === null ) {
		return null;
	}
	return (
		<>
			<span className="newspack-insights__prompts-funnel-label-pct">
				{ sprintf(
					/* translators: 1: percentage, 2: name of the first/top funnel stage (e.g. "Impression"). */
					__( '%1$s of %2$s', 'newspack-plugin' ),
					formatPercent( step.pctOfTop ),
					topLabel
				) }
			</span>
			<span className="newspack-insights__prompts-funnel-label-drop">
				<span aria-hidden="true" className="newspack-insights__prompts-funnel-label-drop-arrow">
					↓
				</span>{ ' ' }
				{ sprintf(
					/* translators: %s: percentage drop-off from the previous stage. */
					__( '%s drop-off', 'newspack-plugin' ),
					formatPercent( step.drop )
				) }
			</span>
		</>
	);
};

const Funnel = ( { stages }: FunnelProps ) => {
	const containerRef = useRef< HTMLDivElement >( null );
	// Default to a desktop width so the common 3-step funnel renders in
	// side-label mode on first paint (the observer corrects narrow containers).
	const [ width, setWidth ] = useState( COMPACT_MAX_WIDTH );

	useEffect( () => {
		const el = containerRef.current;
		if ( ! el || typeof ResizeObserver === 'undefined' ) {
			return;
		}
		setWidth( el.getBoundingClientRect().width );
		const observer = new ResizeObserver( entries => {
			for ( const entry of entries ) {
				setWidth( entry.contentRect.width );
			}
		} );
		observer.observe( el );
		return () => observer.disconnect();
	}, [] );

	const topCount = stages.length > 0 ? stages[ 0 ].count : 0;
	const topLabel = stages.length > 0 ? stages[ 0 ].label : '';
	const steps = useMemo( () => buildSteps( stages, topCount ), [ stages, topCount ] );

	// Proportions can't be computed without a non-zero first step.
	if ( stages.length === 0 || topCount <= 0 ) {
		return (
			<div ref={ containerRef } className="newspack-insights__prompts-funnel">
				<p className="newspack-insights__prompts-funnel-empty">{ __( 'Not enough data to chart the funnel.', 'newspack-plugin' ) }</p>
			</div>
		);
	}

	const stepCount = stages.length;
	const compact = isCompactMode( stepCount, width );
	const stepHeight = stepHeightFor( stepCount );
	const chartHeight = stepHeight * stepCount;

	const svg = (
		<svg
			className="newspack-insights__prompts-funnel-svg"
			viewBox={ `0 0 ${ VIEWBOX_WIDTH } ${ chartHeight }` }
			preserveAspectRatio="xMidYMid meet"
			role="img"
			aria-label={ __( 'Conversion funnel', 'newspack-plugin' ) }
		>
			{ steps.map( step => {
				const nextCount = step.index < stepCount - 1 ? stages[ step.index + 1 ].count : step.stage.count;
				const yTop = step.index * stepHeight;
				return (
					<path
						key={ step.index }
						className="newspack-insights__prompts-funnel-trapezoid"
						d={ trapezoidPath( step.stage.count, nextCount, topCount, yTop, yTop + stepHeight ) }
						fillOpacity={ step.opacity }
					/>
				);
			} ) }
			{ compact &&
				steps.map( step => (
					<text
						key={ step.index }
						className={
							'newspack-insights__prompts-funnel-count-text ' +
							( step.opacity > DARK_TEXT_OPACITY_THRESHOLD ? 'is-on-dark' : 'is-on-light' )
						}
						x={ VIEWBOX_WIDTH / 2 }
						y={ step.index * stepHeight + stepHeight / 2 }
						textAnchor="middle"
						dominantBaseline="central"
					>
						{ formatNumber( step.stage.count ) }
					</text>
				) ) }
		</svg>
	);

	if ( compact ) {
		return (
			<div ref={ containerRef } className="newspack-insights__prompts-funnel newspack-insights__prompts-funnel--compact" role="figure">
				{ svg }
				<ol className="newspack-insights__prompts-funnel-legend">
					{ steps.map( step => (
						<li key={ step.index } className="newspack-insights__prompts-funnel-legend-item">
							<span className="newspack-insights__prompts-funnel-label-name">{ step.stage.label }</span>
							<span className="newspack-insights__prompts-funnel-label-count">{ formatNumber( step.stage.count ) }</span>
							<StepLabels step={ step } topLabel={ topLabel } />
						</li>
					) ) }
				</ol>
			</div>
		);
	}

	return (
		<div ref={ containerRef } className="newspack-insights__prompts-funnel newspack-insights__prompts-funnel--side" role="figure">
			{ svg }
			<div className="newspack-insights__prompts-funnel-labels">
				{ steps.map( step => (
					<div key={ step.index } className="newspack-insights__prompts-funnel-label">
						<span className="newspack-insights__prompts-funnel-label-name">{ step.stage.label }</span>
						<span className="newspack-insights__prompts-funnel-label-count">{ formatNumber( step.stage.count ) }</span>
						<StepLabels step={ step } topLabel={ topLabel } />
					</div>
				) ) }
			</div>
		</div>
	);
};

export default Funnel;
