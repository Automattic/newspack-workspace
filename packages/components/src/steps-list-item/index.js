/**
 * Steps List Item
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

class StepsListItem extends Component {
	/**
	 * Render
	 */
	render() {
		const { className, listItemCount, listItemText, style = {} } = this.props;
		const classes = classnames( 'steps-list-item', className );

		return (
			<div className={ classes } style={ style }>
				<div className="steps-list-item__number">{ listItemCount }</div>
				<div className="steps-list-item__content" dangerouslySetInnerHTML={ { __html: listItemText } } />
			</div>
		);
	}
}

export default StepsListItem;
