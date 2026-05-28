/**
 * Custom Select Control
 */

/**
 * WordPress dependencies
 */
import { CustomSelectControl as BaseComponent } from '@wordpress/components';
import { Component } from '@wordpress/element';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import './style.scss';

class CustomSelectControl extends Component {
	/**
	 * Render.
	 */
	render() {
		const { className, ...otherProps } = this.props;
		const classes = classnames( 'newspack-custom-select-control', className );
		return <BaseComponent className={ classes } { ...otherProps } />;
	}
}

export default CustomSelectControl;
