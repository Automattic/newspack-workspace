/**
 * WordPress dependencies
 */
import { Popover as BaseComponent } from '@wordpress/components';
import { Component } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Popover
 */
class Popover extends Component {
	/**
	 * Render
	 */
	render() {
		const { className, padding, ...otherProps } = this.props;
		const classes = classnames( 'newspack-popover', padding && 'newspack-popover__padding-' + padding, className );
		return <BaseComponent className={ classes } { ...otherProps } />;
	}
}

Popover.defaultProps = {
	padding: false,
};

export default Popover;
