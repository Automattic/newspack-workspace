/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import { formatCurrency } from '../utils/helpers';

/**
 * Thickness values in pixels for circular meter.
 */
const THICKNESS = {
	xs: 4,
	s: 8,
	m: 12,
	l: 16,
};

/**
 * Calculate SVG circle parameters for circular progress indicator.
 *
 * @param {number} percentage Percentage value (can exceed 100).
 * @param {number} thickness  Stroke thickness in pixels.
 * @param {number} size       SVG viewBox size.
 * @return {Object} Circle parameters { radius, circumference, offset }.
 */
const calculateCircle = ( percentage, thickness, size ) => {
	const visualPercentage = percentage > 100 ? 100 : percentage; // Cap visual percentage at 100%.
	const radius = size / 2 - thickness / 2;
	const circumference = 2 * Math.PI * radius;
	const offset = circumference - ( visualPercentage / 100 ) * circumference;

	return {
		radius,
		circumference,
		offset,
	};
};

/**
 * Circular progress indicator component.
 *
 * @param {Object}  props                  Component props.
 * @param {number}  props.amountRaised     Amount raised.
 * @param {number}  props.goal             Goal amount.
 * @param {number}  props.percentage       Percentage completed (0-100).
 * @param {boolean} props.showGoal         Whether to show goal amount.
 * @param {boolean} props.showAmountRaised Whether to show amount raised.
 * @param {boolean} props.showPercentage   Whether to show percentage.
 * @param {string}  props.progressBarColor Custom progress bar color.
 * @param {string}  props.thickness        Thickness size.
 * @return {Element} CircularMeter component.
 */
const CircularMeter = ( { amountRaised, goal, percentage, showGoal, showAmountRaised, showPercentage, progressBarColor, thickness } ) => {
	const viewBoxSize = 72;
	const strokeWidth = THICKNESS[ thickness ] || THICKNESS.s;
	const { radius, circumference, offset } = calculateCircle( percentage, strokeWidth, viewBoxSize );

	const centerX = viewBoxSize / 2;
	const centerY = viewBoxSize / 2;

	const svgStyle = {};
	if ( progressBarColor ) {
		svgStyle.color = progressBarColor;
	}

	return (
		<div className="contribution-meter__circular">
			<div className="contribution-meter__circle-container">
				<svg
					className="contribution-meter__circle"
					style={ svgStyle }
					viewBox={ `0 0 ${ viewBoxSize } ${ viewBoxSize }` }
					role="img"
					aria-label={ __( 'Contribution progress indicator', 'newspack-plugin' ) }
				>
					<title>{ __( 'Contribution Meter', 'newspack-plugin' ) }</title>
					<desc>
						{ sprintf(
							/* translators: 1: percentage, 2: formatted goal amount */
							__( '%1$s%% progress toward %2$s goal', 'newspack-plugin' ),
							percentage,
							formatCurrency( goal )
						) }
					</desc>

					{ /* Background track */ }
					<circle
						className="contribution-meter__circle-track"
						cx={ centerX }
						cy={ centerY }
						r={ radius }
						fill="none"
						strokeWidth={ strokeWidth }
					/>

					{ /* Progress circle */ }
					<circle
						className="contribution-meter__circle-progress"
						cx={ centerX }
						cy={ centerY }
						r={ radius }
						fill="none"
						strokeWidth={ strokeWidth }
						strokeDasharray={ circumference }
						strokeDashoffset={ offset }
						strokeLinecap="butt"
						transform={ `rotate(-90 ${ centerX } ${ centerY })` }
					/>
				</svg>

				{ showPercentage && <div className="contribution-meter__circle-percentage newspack-ui__font--2xs">{ percentage }%</div> }
			</div>

			<div className="contribution-meter__data">
				{ showAmountRaised && (
					<span className="contribution-meter__amount-raised newspack-ui__font--m">
						{ formatCurrency( amountRaised ) } { __( 'raised', 'newspack-plugin' ) }
					</span>
				) }
				{ showGoal && (
					<span
						className={
							showAmountRaised
								? 'contribution-meter__goal'
								: 'contribution-meter__goal contribution-meter__goal--primary newspack-ui__font--m'
						}
					>
						{ formatCurrency( goal ) } { __( 'goal', 'newspack-plugin' ) }
					</span>
				) }
			</div>
		</div>
	);
};

export default CircularMeter;
