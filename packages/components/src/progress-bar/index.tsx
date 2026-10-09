/**
 * Progress Bar
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import './style.scss';

type ProgressBarProps = {
	/** Label displayed above the bar. */
	label?: React.ReactNode;
	/** The number of steps completed. */
	completed?: number | string;
	/** The total number of steps. */
	total?: number | string;
	/** Whether to display completion as a `completed/total` fraction. */
	displayFraction?: boolean;
};

class ProgressBar extends Component< ProgressBarProps > {
	/**
	 * Get completion as a percentage.
	 *
	 * @param completed The number of steps completed.
	 * @param total     The total number of steps.
	 * @return completion percentage
	 */
	getCompletionPercentage( completed: number, total: number ) {
		if ( ! total ) {
			return 100;
		}

		return Math.max( Math.min( Math.round( ( completed / total ) * 100 ), 100 ), 0 );
	}

	/**
	 * Render.
	 */
	render() {
		const { label, completed, total, displayFraction } = this.props;
		const cleanTotal = Math.max( 0, parseInt( String( total ) ) || 0 );
		const cleanCompleted = Math.max( 0, Math.min( parseInt( String( completed ) ) || 0, cleanTotal ) );

		const barStyle = {
			width: this.getCompletionPercentage( cleanCompleted, cleanTotal ) + '%',
		};

		return (
			<div className="newspack-progress-bar">
				{ ( label || displayFraction ) && (
					<div className="newspack-progress-bar__headings">
						{ label && <h2>{ label }</h2> }
						{ displayFraction && (
							<p className="is-dark">
								<strong>
									{ cleanCompleted }/{ cleanTotal }
								</strong>
							</p>
						) }
					</div>
				) }

				<div className="newspack-progress-bar__container">
					<div className="newspack-progress-bar__bar" style={ barStyle } data-testid="progress-bar-indicator" />
				</div>
			</div>
		);
	}
}

export default ProgressBar;
