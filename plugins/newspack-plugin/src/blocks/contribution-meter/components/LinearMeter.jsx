/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatCurrency } from '../utils/helpers';

/**
 * Linear progress bar meter component.
 *
 * @param {Object}  props                  Component props.
 * @param {number}  props.amountRaised     Amount raised.
 * @param {number}  props.goal             Goal amount.
 * @param {number}  props.percentage       Percentage completed (0-100).
 * @param {boolean} props.showGoal         Whether to show goal amount.
 * @param {boolean} props.showAmountRaised Whether to show amount raised.
 * @param {boolean} props.showPercentage   Whether to show percentage.
 * @param {string}  props.progressBarColor Custom progress bar color.
 * @return {Element} LinearMeter component.
 */
const LinearMeter = ( { amountRaised, goal, percentage, showGoal, showAmountRaised, showPercentage, progressBarColor } ) => {
	const barStyle = {};
	if ( progressBarColor ) {
		barStyle.color = progressBarColor;
	}

	const progressStyle = {
		width: `${ percentage > 100 ? 100 : percentage }%`,
	};

	const hasAnyTextDisplay = showGoal || showAmountRaised || showPercentage;

	// Determine text alignment class based on what's displayed.
	let textAlignClass = '';
	if ( ( showPercentage && ! showGoal && ! showAmountRaised ) || ( ! showAmountRaised && showGoal && ! showPercentage ) ) {
		textAlignClass = 'contribution-meter__text--right';
	}

	const textStyle = showPercentage && ! showGoal && ! showAmountRaised ? { width: progressStyle.width } : undefined;

	return (
		<div className="contribution-meter__linear">
			{ hasAnyTextDisplay && (
				<div className={ `contribution-meter__text ${ textAlignClass }` } style={ textStyle }>
					{ /* Percentage comes first when: Goal + Percentage (no raised) */ }
					{ showPercentage && ! showAmountRaised && showGoal && <span className="contribution-meter__percentage">{ percentage }%</span> }

					{ /* Raised + Goal combined */ }
					{ showAmountRaised && showGoal && (
						<span className="contribution-meter__amount-raised-goal">
							{ sprintf(
								/* translators: 1: amount raised, 2: goal amount */
								__( '%1$s raised of %2$s goal', 'newspack-plugin' ),
								formatCurrency( amountRaised ),
								formatCurrency( goal )
							) }
						</span>
					) }

					{ /* Raised only */ }
					{ showAmountRaised && ! showGoal && (
						<span className="contribution-meter__amount-raised">
							{ sprintf(
								/* translators: %s: amount raised */
								__( '%s raised', 'newspack-plugin' ),
								formatCurrency( amountRaised )
							) }
						</span>
					) }

					{ /* Goal (when not combined with raised) */ }
					{ ! showAmountRaised && showGoal && (
						<span className="contribution-meter__goal">
							{ sprintf(
								/* translators: %s: goal amount */
								__( '%s goal', 'newspack-plugin' ),
								formatCurrency( goal )
							) }
						</span>
					) }

					{ /* Percentage comes last in all other cases */ }
					{ showPercentage && ! ( ! showAmountRaised && showGoal ) && (
						<span className="contribution-meter__percentage">{ percentage }%</span>
					) }
				</div>
			) }
			<div className="contribution-meter__bar-container" style={ barStyle }>
				<div className="contribution-meter__bar-track">
					<div className="contribution-meter__bar-progress" style={ progressStyle } />
				</div>
			</div>
		</div>
	);
};

export default LinearMeter;
