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
import type { ComponentProps } from 'react';

/**
 * Internal dependencies
 */
import './style.scss';

type CustomSelectControlProps = ComponentProps< typeof BaseComponent >;

class CustomSelectControl extends Component< CustomSelectControlProps > {
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
