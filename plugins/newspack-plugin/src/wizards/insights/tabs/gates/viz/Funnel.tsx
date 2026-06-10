/**
 * Funnel viz (NPPD) — tab-local to Gates.
 *
 * Multi-step conversion funnel rendered as stacked SVG trapezoids. Each
 * trapezoid's top edge is proportional to its own count and its bottom edge to
 * the next step's count, so the silhouette narrows with drop-off; the last step
 * is a rectangle. A single anchor color (primary-500) fades from full opacity at
 * the top to 0.6 at the bottom.
 *
 * Two layouts, auto-selected from container width + step count:
 *   - Side-label (default): step name / count / deltas in a fixed column to the
 *     right of each trapezoid. Used when stepCount < 5 AND width >= 480px.
 *   - Compact: the count renders inside each trapezoid and the full
 *     names/counts/deltas move to a legend below. Used when stepCount >= 5 OR
 *     width < 480px.
 *
 * Tab-local for now (only Gates consumes a funnel); promote to
 * packages/components/src/ when a second consumer arrives.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import type { GatesFunnelStage } from '../../../api/gates';
import { formatNumber, formatPercent } from '../../components/format';

/** A "from previous" drop above this fraction is highlighted as a problem. */
export const DROP_HIGHLIGHT_THRESHOLD = 0.2;

const COMPACT_MIN_STEPS = 5;
const COMPACT_MAX_WIDTH = 480; // Below this container width, force compact mode.
const MIN_STEP_HEIGHT = 32;
const MAX_CHART_HEIGHT = 480;
const VIEWBOX_WIDTH = 320;
const FULL_OPACITY = 1;
const TAIL_OPACITY = 0.6;
const DARK_TEXT_OPACITY_THRESHOLD = 0.75;

export interface FunnelProps {
	stages: GatesFunnelStage[];
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

/** Drop-off from the previous step as a fraction in [0, 1]. */
export const dropFromPrevious = ( count: number, prevCount: number ): number => ( prevCount > 0 ? 1 - count / prevCount : 0 );

/** Whether a drop-off is severe enough to highlight. */
export const isHighDrop = ( drop: number ): boolean => drop > DROP_HIGHLIGHT_THRESHOLD;

/** Equal band height per step, capped so the whole chart fits ~480px. */
const stepHeightFor = ( stepCount: number ): number => Math.max( MIN_STEP_HEIGHT, Math.floor( MAX_CHART_HEIGHT / stepCount ) );

/** SVG path for one trapezoid: top edge sized to `count`, bottom to `nextCount`, centered. */
const trapezoidPath = ( count: number, nextCount: number, topCount: number, yTop: number, yBottom: number ): string => {
	const cx = VIEWBOX_WIDTH / 2;
	const halfTop = ( count / topCount ) * ( VIEWBOX_WIDTH / 2 );
	const halfBottom = ( nextCount / topCount ) * ( VIEWBOX_WIDTH / 2 );
	return [
		`M ${ cx - halfTop } ${ yTop }`,
		`L ${ cx + halfTop } ${ yTop }`,
		`L ${ cx + halfBottom } ${ yBottom }`,
		`L ${ cx - halfBottom } ${ yBottom }`,
		'Z',
	].join( ' ' );
};

interface StepView {
	stage: GatesFunnelStage;
	index: number;
	opacity: number;
	pctOfTop: number;
	drop: number | null;
	highDrop: boolean;
}

/** Build the per-step view model shared by both layouts. */
const buildSteps = ( stages: GatesFunnelStage[], topCount: number ): StepView[] =>
	stages.map( ( stage, index ) => {
		const prevCount = index > 0 ? stages[ index - 1 ].count : 0;
		const drop = index > 0 ? dropFromPrevious( stage.count, prevCount ) : null;
		return {
			stage,
			index,
			opacity: stepOpacity( index, stages.length ),
			pctOfTop: topCount > 0 ? stage.count / topCount : 0,
			drop,
			highDrop: drop !== null && isHighDrop( drop ),
		};
	} );

/** The two delta lines shown for every step beyond the first. */
const Deltas = ( { step }: { step: StepView } ) => {
	if ( step.drop === null ) {
		return null;
	}
	return (
		<>
			<span className="newspack-insights__funnel-delta newspack-insights__funnel-delta--top">
				{ sprintf(
					/* translators: %s: percentage of the first step's count reaching this step. */
					__( '%s of top', 'newspack-plugin' ),
					formatPercent( step.pctOfTop )
				) }
			</span>
			<span
				className={ classnames( 'newspack-insights__funnel-delta newspack-insights__funnel-delta--prev', {
					'is-high-drop': step.highDrop,
				} ) }
			>
				{ sprintf(
					/* translators: %s: percentage drop-off from the previous step. */
					__( '%s from previous', 'newspack-plugin' ),
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

	// Proportions can't be computed without a non-zero first step.
	if ( stages.length === 0 || topCount <= 0 ) {
		return (
			<div ref={ containerRef } className="newspack-insights__funnel">
				<p className="newspack-insights__funnel-empty">{ __( 'Not enough data to chart the funnel.', 'newspack-plugin' ) }</p>
			</div>
		);
	}

	const stepCount = stages.length;
	const compact = isCompactMode( stepCount, width );
	const stepHeight = stepHeightFor( stepCount );
	const chartHeight = stepHeight * stepCount;
	const steps = buildSteps( stages, topCount );

	const svg = (
		<svg
			className="newspack-insights__funnel-svg"
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
						key={ step.stage.label }
						className="newspack-insights__funnel-trapezoid"
						d={ trapezoidPath( step.stage.count, nextCount, topCount, yTop, yTop + stepHeight ) }
						fillOpacity={ step.opacity }
					/>
				);
			} ) }
			{ compact &&
				steps.map( step => (
					<text
						key={ step.stage.label }
						className={ classnames( 'newspack-insights__funnel-count-text', {
							'is-on-dark': step.opacity > DARK_TEXT_OPACITY_THRESHOLD,
							'is-on-light': step.opacity <= DARK_TEXT_OPACITY_THRESHOLD,
						} ) }
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
			<div ref={ containerRef } className="newspack-insights__funnel newspack-insights__funnel--compact" role="figure">
				{ svg }
				<ol className="newspack-insights__funnel-legend">
					{ steps.map( step => (
						<li key={ step.stage.label } className="newspack-insights__funnel-legend-item">
							<span className="newspack-insights__funnel-label-name">{ step.stage.label }</span>
							<span className="newspack-insights__funnel-label-count">{ formatNumber( step.stage.count ) }</span>
							<Deltas step={ step } />
						</li>
					) ) }
				</ol>
			</div>
		);
	}

	return (
		<div ref={ containerRef } className="newspack-insights__funnel newspack-insights__funnel--side" role="figure">
			{ svg }
			<div className="newspack-insights__funnel-labels">
				{ steps.map( step => (
					<div key={ step.stage.label } className="newspack-insights__funnel-label">
						<span className="newspack-insights__funnel-label-name">{ step.stage.label }</span>
						<span className="newspack-insights__funnel-label-count">{ formatNumber( step.stage.count ) }</span>
						<Deltas step={ step } />
					</div>
				) ) }
			</div>
		</div>
	);
};

export default Funnel;
