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

type StepsListItemProps = {
	/** Additional CSS class name. */
	className?: string;
	/** The item's ordinal number. */
	listItemCount?: number;
	/** The item's content, as a plain-HTML string. */
	listItemText?: string;
	/** Inline styles for the item element. */
	style?: React.CSSProperties;
};

class StepsListItem extends Component< StepsListItemProps > {
	/**
	 * Render
	 */
	render() {
		const { className, listItemCount, listItemText, style = {} } = this.props;
		const classes = classnames( 'steps-list-item', className );

		return (
			<div className={ classes } style={ style }>
				<div className="steps-list-item__number">{ listItemCount }</div>
				<div className="steps-list-item__content" dangerouslySetInnerHTML={ { __html: listItemText ?? '' } } />
			</div>
		);
	}
}

export default StepsListItem;
