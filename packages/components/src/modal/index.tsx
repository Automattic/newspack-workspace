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
import type { ComponentProps, ForwardedRef } from 'react';

const sizeClassMap: Record< string, string > = {
	small: 'newspack-modal--size-small',
	medium: 'newspack-modal--size-medium',
	large: 'newspack-modal--size-large',
	'x-large': 'newspack-modal--size-x-large',
	full: 'newspack-modal--size-full',
};

const getSizeClassName = ( size: string ) => sizeClassMap[ size ] || sizeClassMap.medium;

type ModalProps = Omit< ComponentProps< typeof BaseComponent >, 'size' > & {
	size?: string;
	hideTitle?: boolean;
};

function Modal( { className, size = 'medium', hideTitle, ...otherProps }: ModalProps, ref: ForwardedRef< HTMLDivElement > ) {
	const classes = classnames(
		'newspack-modal',
		hideTitle && 'newspack-modal--hide-title', // Note: also hides the X close button.
		getSizeClassName( size ),
		className
	);

	return <BaseComponent className={ classes } { ...otherProps } ref={ ref } />;
}
export default forwardRef( Modal );
