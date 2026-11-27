/**
 * WordPress dependencies.
 */
import { BaseControl, ColorPicker as ColorPickerComponent } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState, useRef, useEffect } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';
import type { ReactNode } from 'react';

/**
 * Internal dependencies.
 */
import hooks from '../hooks';
import utils from '../utils';
import { getContrastColor } from '../utils/contrast-color';
import './style.scss';

const { InteractiveDiv } = utils;

type ColorPickerProps = {
	/** Label for the color picker. */
	label: ReactNode;
	/** Help text for the color picker. */
	help?: ReactNode;
	/** Default color. */
	color?: string;
	/** Function to call when the color changes. */
	onChange: ( color: string ) => void;
	/** Additional class name. */
	className?: string;
	/** Whether the color is shown but cannot be changed. */
	disabled?: boolean;
	/** A control shown beside the swatch, such as a reset button. */
	action?: ReactNode;
};

/**
 * ColorPicker component.
 */
const ColorPicker = ( { label, help, color = '#ffffff', onChange, className, disabled = false, action }: ColorPickerProps ) => {
	const [ isExpanded, setIsExpanded ] = useState( false );
	const ref = useRef< HTMLDivElement | null >( null );
	const id = useInstanceId( ColorPicker, 'newspack-color-picker' );
	const labelId = `${ id }-label`;
	hooks.useOnClickOutside( ref, () => setIsExpanded( false ) );
	useEffect( () => {
		if ( disabled ) {
			setIsExpanded( false );
		}
	}, [ disabled ] );
	const expander = (
		<InteractiveDiv
			id={ id }
			aria-labelledby={ labelId }
			aria-describedby={ help ? `${ id }__help` : undefined }
			aria-expanded={ isExpanded }
			aria-disabled={ disabled || undefined }
			tabIndex={ disabled ? -1 : 0 }
			className={ 'newspack-color-picker__expander' }
			onClick={ () => ! disabled && setIsExpanded( ! isExpanded ) }
			style={ {
				...( disabled && { cursor: 'default' } ),
				backgroundColor: color,
				color: getContrastColor( color ),
			} }
		>
			{ color }
		</InteractiveDiv>
	);
	return (
		<BaseControl
			id={ id }
			className={ classnames( 'newspack-color-picker', { 'is-disabled': disabled }, className ) }
			help={ help }
			__nextHasNoMarginBottom
		>
			<BaseControl.VisualLabel id={ labelId }>{ label }</BaseControl.VisualLabel>
			{ action ? (
				<Stack direction="row" gap="sm" align="center" className="newspack-color-picker__row">
					{ expander }
					{ action }
				</Stack>
			) : (
				expander
			) }

			<div className="newspack-color-picker__main" ref={ ref }>
				{ isExpanded && ! disabled && <ColorPickerComponent color={ color } onChange={ onChange } enableAlpha={ false } /> }
			</div>
		</BaseControl>
	);
};

export default ColorPicker;
