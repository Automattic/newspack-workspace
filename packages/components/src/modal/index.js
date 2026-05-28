/**
 * Modal
 */

/**
 * WordPress dependencies.
 */
import { forwardRef } from '@wordpress/element';
import { Modal as BaseComponent } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import './style.scss';

/**
 * External dependencies.
 */
import classnames from 'classnames';

const sizeClassMap = {
	small: 'newspack-modal--size-small',
	medium: 'newspack-modal--size-medium',
	large: 'newspack-modal--size-large',
	'x-large': 'newspack-modal--size-x-large',
	full: 'newspack-modal--size-full',
};

const getSizeClassName = size => sizeClassMap[ size ] || sizeClassMap.medium;

function Modal( { className, size = 'medium', hideTitle, ...otherProps }, ref ) {
	const classes = classnames(
		'newspack-modal',
		hideTitle && 'newspack-modal--hide-title', // Note: also hides the X close button.
		getSizeClassName( size ),
		className
	);

	return <BaseComponent className={ classes } { ...otherProps } ref={ ref } />;
}
export default forwardRef( Modal );
