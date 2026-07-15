import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { AddStyleModal } from './AddStyleModal';
import { FallbackStyleControl } from './FallbackStyleControl';
import { StyleList } from './StyleList';
import type { Styles, ColorStyle } from './types';
import {
	DEFAULT_BACKGROUND_COLOR,
	DEFAULT_TEXT_COLOR,
	normalizeColorStyle,
} from './utils';

type ColorValueStylesControlProps = {
	styles: Styles;
	fallbackStyle: ColorStyle;
	onChange: ( nextStyles: Styles ) => void;
	onChangeFallbackStyle: ( nextFallbackStyle: ColorStyle ) => void;
};

export const ColorValueStylesControl = ( {
	styles,
	fallbackStyle,
	onChange,
	onChangeFallbackStyle,
}: ColorValueStylesControlProps ) => {
	const [ isAddModalOpen, setIsAddModalOpen ] = useState( false );
	const [ isEditModalOpen, setIsEditModalOpen ] = useState( false );
	const [ isFallbackModalOpen, setIsFallbackModalOpen ] = useState( false );

	const [ nextValue, setNextValue ] = useState( '' );
	const [ nextTextColor, setNextTextColor ] = useState( DEFAULT_TEXT_COLOR );
	const [ nextBackgroundColor, setNextBackgroundColor ] = useState(
		DEFAULT_BACKGROUND_COLOR
	);

	const [ editValue, setEditValue ] = useState( '' );
	const [ editTextColor, setEditTextColor ] = useState( DEFAULT_TEXT_COLOR );
	const [ editBackgroundColor, setEditBackgroundColor ] = useState(
		DEFAULT_BACKGROUND_COLOR
	);

	const [ fallbackValue, setFallbackValue ] = useState< string >(
		__( 'Fallback style (no match)', 'newspack-profiles' )
	);
	const [ fallbackTextColor, setFallbackTextColor ] =
		useState( DEFAULT_TEXT_COLOR );
	const [ fallbackBackgroundColor, setFallbackBackgroundColor ] = useState(
		DEFAULT_BACKGROUND_COLOR
	);

	const normalizedFallbackStyle = normalizeColorStyle( fallbackStyle );

	const closeAddModal = () => {
		setIsAddModalOpen( false );

		setNextValue( '' );
		setNextTextColor( DEFAULT_TEXT_COLOR );
		setNextBackgroundColor( DEFAULT_BACKGROUND_COLOR );
	};

	const removeStyle = ( value: string ) => {
		const updatedMap = { ...styles };

		delete updatedMap[ value ];

		onChange( updatedMap );
	};

	const openEditModal = ( value: string ) => {
		const style = normalizeColorStyle( styles[ value ] );

		setEditValue( value );
		setEditTextColor( style.textColor );
		setEditBackgroundColor( style.backgroundColor );
		setIsEditModalOpen( true );
	};

	const closeEditModal = () => {
		setIsEditModalOpen( false );
		setEditValue( '' );
		setEditTextColor( DEFAULT_TEXT_COLOR );
		setEditBackgroundColor( DEFAULT_BACKGROUND_COLOR );
	};

	const saveEditedStyle = () => {
		const trimmedValue = editValue.trim();

		if ( ! trimmedValue || ! editTextColor || ! editBackgroundColor ) {
			return;
		}

		onChange( {
			...styles,
			[ trimmedValue ]: {
				textColor: editTextColor,
				backgroundColor: editBackgroundColor,
			},
		} );

		closeEditModal();
	};

	const openFallbackModal = () => {
		setFallbackValue(
			__( 'Fallback style (no match)', 'newspack-profiles' )
		);
		setFallbackTextColor( normalizedFallbackStyle.textColor );
		setFallbackBackgroundColor( normalizedFallbackStyle.backgroundColor );
		setIsFallbackModalOpen( true );
	};

	const closeFallbackModal = () => {
		setIsFallbackModalOpen( false );
	};

	const saveFallbackStyle = () => {
		onChangeFallbackStyle( {
			textColor: fallbackTextColor,
			backgroundColor: fallbackBackgroundColor,
		} );

		closeFallbackModal();
	};

	const addStyle = () => {
		const trimmedValue = nextValue.trim();

		if ( ! trimmedValue || ! nextTextColor || ! nextBackgroundColor ) {
			return;
		}

		onChange( {
			...styles,
			[ trimmedValue ]: {
				textColor: nextTextColor,
				backgroundColor: nextBackgroundColor,
			},
		} );

		closeAddModal();
	};

	return (
		<>
			<p className="wp-block-newspack-profiles-conditional-style__styles-label">
				{ __( 'Styles by value', 'newspack-profiles' ) }
			</p>

			<FallbackStyleControl
				fallbackStyle={ normalizedFallbackStyle }
				onEdit={ openFallbackModal }
			/>

			<StyleList
				styles={ styles }
				onEdit={ openEditModal }
				onRemove={ removeStyle }
			/>

			<Button
				variant="primary"
				onClick={ () => setIsAddModalOpen( true ) }
			>
				{ __( 'Add Style', 'newspack-profiles' ) }
			</Button>

			<AddStyleModal
				isOpen={ isAddModalOpen }
				title={ __( 'Add Style', 'newspack-profiles' ) }
				submitLabel={ __( 'Add Style', 'newspack-profiles' ) }
				nextValue={ nextValue }
				nextTextColor={ nextTextColor }
				nextBackgroundColor={ nextBackgroundColor }
				onChangeValue={ setNextValue }
				onChangeTextColor={ setNextTextColor }
				onChangeBackgroundColor={ setNextBackgroundColor }
				onClose={ closeAddModal }
				onSubmit={ addStyle }
			/>

			<AddStyleModal
				isOpen={ isEditModalOpen }
				title={ __( 'Edit Style', 'newspack-profiles' ) }
				submitLabel={ __( 'Save Style', 'newspack-profiles' ) }
				nextValue={ editValue }
				nextTextColor={ editTextColor }
				nextBackgroundColor={ editBackgroundColor }
				isValueDisabled
				onChangeValue={ setEditValue }
				onChangeTextColor={ setEditTextColor }
				onChangeBackgroundColor={ setEditBackgroundColor }
				onClose={ closeEditModal }
				onSubmit={ saveEditedStyle }
			/>

			<AddStyleModal
				isOpen={ isFallbackModalOpen }
				title={ __( 'Edit Fallback Style', 'newspack-profiles' ) }
				submitLabel={ __( 'Save Fallback Style', 'newspack-profiles' ) }
				nextValue={ fallbackValue }
				nextTextColor={ fallbackTextColor }
				nextBackgroundColor={ fallbackBackgroundColor }
				isValueDisabled
				onChangeValue={ setFallbackValue }
				onChangeTextColor={ setFallbackTextColor }
				onChangeBackgroundColor={ setFallbackBackgroundColor }
				onClose={ closeFallbackModal }
				onSubmit={ saveFallbackStyle }
			/>
		</>
	);
};
