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
const THICKNESS: Record< string, number > = {
	xs: 4,
	s: 8,
	m: 12,
	l: 16,
};

/**
 * Props for the circular meter component.
 */
type CircularMeterProps = {
	/** Amount raised. */
	amountRaised: number;
	/** Goal amount. */
	goal: number;
	/** Percentage completed (0-100). */
	percentage: number;
	/** Whether to show goal amount. */
	showGoal: boolean;
	/** Whether to show amount raised. */
	showAmountRaised: boolean;
	/** Whether to show percentage. */
	showPercentage: boolean;
	/** Custom progress bar color. */
	progressBarColor?: string;
	/** Thickness size. */
	thickness: string;
};

/**
 * Calculate SVG circle parameters for circular progress indicator.
 *
 * @param percentage Percentage value (can exceed 100).
 * @param thickness  Stroke thickness in pixels.
 * @param size       SVG viewBox size.
 * @return Circle parameters { radius, circumference, offset }.
 */
const calculateCircle = ( percentage: number, thickness: number, size: number ) => {
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
 * @param props                  Component props.
 * @param props.amountRaised     Amount raised.
 * @param props.goal             Goal amount.
 * @param props.percentage       Percentage completed (0-100).
 * @param props.showGoal         Whether to show goal amount.
 * @param props.showAmountRaised Whether to show amount raised.
 * @param props.showPercentage   Whether to show percentage.
 * @param props.progressBarColor Custom progress bar color.
 * @param props.thickness        Thickness size.
 * @return CircularMeter component.
 */
const CircularMeter = ( {
	amountRaised,
	goal,
	percentage,
	showGoal,
	showAmountRaised,
	showPercentage,
	progressBarColor,
	thickness,
}: CircularMeterProps ) => {
	const viewBoxSize = 72;
	const strokeWidth = THICKNESS[ thickness ] || THICKNESS.s;
	const { radius, circumference, offset } = calculateCircle( percentage, strokeWidth, viewBoxSize );

	const centerX = viewBoxSize / 2;
	const centerY = viewBoxSize / 2;

	const svgStyle: { color?: string } = {};
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
							String( percentage ),
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
