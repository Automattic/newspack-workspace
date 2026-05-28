/**
 * Steps List
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { StepsListItem } from '../';
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

class StepsList extends Component {
	/**
	 * Render
	 */
	render() {
		const { className, stepsListItems, narrowList, style = {} } = this.props;
		const classes = classnames( 'steps-list', className, narrowList && 'steps-list__narrow-list' );

		return (
			<div className={ classes } style={ style }>
				{ stepsListItems.map( ( listItem, index ) => (
					<StepsListItem key={ index } listItemCount={ index + 1 } listItemText={ listItem } />
				) ) }
			</div>
		);
	}
}

export default StepsList;
