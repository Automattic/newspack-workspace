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

type StepsListProps = {
	/** Additional CSS class name. */
	className?: string;
	/** The steps' contents, as plain-HTML strings. */
	stepsListItems: string[];
	/** Whether to render the narrow variant. */
	narrowList?: boolean;
	/** Inline styles for the list element. */
	style?: React.CSSProperties;
};

class StepsList extends Component< StepsListProps > {
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
