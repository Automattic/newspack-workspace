/**
 * Text Control
 */

/**
 * WordPress dependencies
 */
import { TextControl as BaseComponent } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * External dependencies
 */
import classNames from 'classnames';

type BaseComponentProps = {
	onChange: ( value: string ) => void;
	value: string | number;
};

const TextControl = ( { className = '', required = false, isWide = false, withMargin = true, ...otherProps } ) => {
	const wrapperRef = useRef< HTMLDivElement >( null );
	useEffect( () => {
		if ( wrapperRef.current === null ) {
			return;
		}
		const labelEl = wrapperRef.current.querySelector( 'label' );
		if ( labelEl ) {
			labelEl.setAttribute( 'data-required-text', __( '(required)', 'newspack-plugin' ) );
		}
	}, [ wrapperRef.current ] );
	const classes = classNames(
		'newspack-text-control',
		{
			'newspack-text-control--wide': isWide,
			'newspack-text-control--with-margin': withMargin,
		},
		className
	);
	return required ? (
		<div ref={ wrapperRef } className="newspack-text-control--required">
			<BaseComponent className={ classes } required={ required } { ...( otherProps as BaseComponentProps ) } />
		</div>
	) : (
		<BaseComponent className={ classes } { ...( otherProps as BaseComponentProps ) } />
	);
};

export default TextControl;
