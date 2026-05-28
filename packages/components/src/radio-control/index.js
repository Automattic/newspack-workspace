/**
 * WordPress dependencies.
 */
import { RadioControl as BaseComponent } from '@wordpress/components';
import { Component } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Radio Control.
 */
class RadioControl extends Component {
	/**
	 * Render.
	 */
	render() {
		const { className, ...otherProps } = this.props;
		const classes = classnames( 'newspack-radio-control', className );
		return <BaseComponent className={ classes } { ...otherProps } />;
	}
}

export default RadioControl;
