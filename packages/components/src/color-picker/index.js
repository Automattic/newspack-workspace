/**
 * WordPress dependencies.
 */
import { BaseControl, ColorPicker as ColorPickerComponent } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState, useRef } from '@wordpress/element';

/**
 * External dependencies.
 */
import classnames from 'classnames';
import { colord, extend } from 'colord';
import a11yPlugin from 'colord/plugins/a11y';

/**
 * Internal dependencies.
 */
import hooks from '../hooks';
import utils from '../utils';
import './style.scss';

extend( [ a11yPlugin ] );
const { InteractiveDiv } = utils;

/**
 * ColorPicker component.
 *
 * @param {Object}             props             - Component props.
 * @param {JSX.Element|string} props.label       - Label for the color picker.
 * @param {JSX.Element|string} [props.help]      - Help text for the color picker.
 * @param {string}             [props.color]     - Default color.
 * @param {Function}           props.onChange    - Function to call when the color changes.
 * @param {string}             [props.className] - Additional class name.
 * @param {boolean}            [props.disabled]  - Whether the color is shown but cannot be changed.
 * @return {JSX.Element} ColorPicker component.
 */
const ColorPicker = ( { label, help, color = '#ffffff', onChange, className, disabled = false } ) => {
	const [ isExpanded, setIsExpanded ] = useState( false );
	const ref = useRef();
	const id = useInstanceId( ColorPicker, 'newspack-color-picker' );
	const labelId = `${ id }-label`;
	const colordColor = colord( color );
	hooks.useOnClickOutside( ref, () => setIsExpanded( false ) );
	return (
		<BaseControl
			id={ id }
			className={ classnames( 'newspack-color-picker', { 'is-disabled': disabled }, className ) }
			help={ help }
			__nextHasNoMarginBottom
		>
			<BaseControl.VisualLabel id={ labelId }>{ label }</BaseControl.VisualLabel>
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
					color: colordColor.contrast() > colordColor.contrast( '#000000' ) ? '#ffffff' : '#000000',
				} }
			>
				{ color }
			</InteractiveDiv>

			<div className="newspack-color-picker__main" ref={ ref }>
				{ isExpanded && ! disabled && <ColorPickerComponent color={ color } onChange={ onChange } enableAlpha={ false } /> }
			</div>
		</BaseControl>
	);
};

export default ColorPicker;
