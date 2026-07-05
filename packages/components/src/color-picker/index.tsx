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
import type { ReactNode } from 'react';

/**
 * Internal dependencies.
 */
import hooks from '../hooks';
import utils from '../utils';
import './style.scss';

extend( [ a11yPlugin ] );
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
};

/**
 * ColorPicker component.
 */
const ColorPicker = ( { label, help, color = '#ffffff', onChange, className }: ColorPickerProps ) => {
	const [ isExpanded, setIsExpanded ] = useState( false );
	const ref = useRef< HTMLDivElement | null >( null );
	const id = useInstanceId( ColorPicker, 'newspack-color-picker' );
	const labelId = `${ id }-label`;
	const colordColor = colord( color );
	hooks.useOnClickOutside( ref, () => setIsExpanded( false ) );
	return (
		<BaseControl id={ id } className={ classnames( 'newspack-color-picker', className ) } help={ help } __nextHasNoMarginBottom>
			<BaseControl.VisualLabel id={ labelId }>{ label }</BaseControl.VisualLabel>
			<InteractiveDiv
				id={ id }
				aria-labelledby={ labelId }
				aria-describedby={ help ? `${ id }__help` : undefined }
				aria-expanded={ isExpanded }
				className={ 'newspack-color-picker__expander' }
				onClick={ () => setIsExpanded( ! isExpanded ) }
				style={ {
					backgroundColor: color,
					color: colordColor.contrast() > colordColor.contrast( '#000000' ) ? '#ffffff' : '#000000',
				} }
			>
				{ color }
			</InteractiveDiv>

			<div className="newspack-color-picker__main" ref={ ref }>
				{ isExpanded && <ColorPickerComponent color={ color } onChange={ onChange } enableAlpha={ false } /> }
			</div>
		</BaseControl>
	);
};

export default ColorPicker;
