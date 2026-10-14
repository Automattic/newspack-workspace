/**
 * Waiting
 */

/**
 * WordPress dependencies.
 */
import { Component } from '@wordpress/element';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

type WaitingProps = React.ComponentProps< typeof Spinner > & {
	/** Additional CSS class name. */
	className?: string;
	/** Whether the spinner is aligned to the right. */
	isRight?: boolean;
	/** Whether the spinner is aligned to the left. */
	isLeft?: boolean;
	/** Whether the spinner is centered. */
	isCenter?: boolean;
	/** Whether to render the spinner without margins. */
	noMargin?: boolean;
};

class Waiting extends Component< WaitingProps > {
	/**
	 * Render
	 */
	render() {
		const { className, isRight, isLeft, isCenter, noMargin, ...otherProps } = this.props;
		const classes = classnames( 'newspack-waiting', className, {
			'is-right': isRight,
			'is-left': isLeft,
			'is-center': isCenter,
			'no-margin': noMargin,
		} );
		return <Spinner className={ classes } { ...otherProps } />;
	}
}

export default Waiting;
