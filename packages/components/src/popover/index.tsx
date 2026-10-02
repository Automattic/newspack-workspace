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

type PopoverProps = React.ComponentProps< typeof BaseComponent > & {
	/** Padding variant suffix for the `newspack-popover__padding-*` class, or `false` for none. */
	padding?: string | number | false;
};

/**
 * Popover
 */
class Popover extends Component< PopoverProps > {
	static defaultProps = {
		padding: false as string | number | false,
	};

	/**
	 * Render
	 */
	render() {
		const { className, padding, ...otherProps } = this.props;
		const classes = classnames( 'newspack-popover', padding && 'newspack-popover__padding-' + padding, className );
		return <BaseComponent className={ classes } { ...otherProps } />;
	}
}

export default Popover;
