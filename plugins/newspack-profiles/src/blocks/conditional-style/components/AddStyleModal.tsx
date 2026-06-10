import { Button, Modal, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ColorPickerControl } from './ColorPickerControl';
import { PresetColorPairControl } from './PresetColorPairControl';

type AddStyleModalProps = {
	isOpen: boolean;
	title: string;
	submitLabel: string;
	nextValue: string;
	nextTextColor: string;
	nextBackgroundColor: string;
	isValueDisabled?: boolean;
	onChangeValue: ( value: string ) => void;
	onChangeTextColor: ( color: string ) => void;
	onChangeBackgroundColor: ( color: string ) => void;
	onClose: () => void;
	onSubmit: () => void;
};

export const AddStyleModal = ( {
	isOpen,
	title,
	submitLabel,
	nextValue,
	nextTextColor,
	nextBackgroundColor,
	isValueDisabled = false,
	onChangeValue,
	onChangeTextColor,
	onChangeBackgroundColor,
	onClose,
	onSubmit,
}: AddStyleModalProps ) => {
	if ( ! isOpen ) {
		return null;
	}

	return (
		<Modal title={ title } onRequestClose={ onClose }>
			<div
				style={ {
					display: 'block',
					marginBottom: '12px',
				} }
			>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Field value', 'newspack-profiles' ) }
					help={ __(
						'Enter the exact field value that should use this color.',
						'newspack-profiles'
					) }
					placeholder={ __( 'Example: Active', 'newspack-profiles' ) }
					value={ nextValue }
					disabled={ isValueDisabled }
					onChange={ onChangeValue }
				/>
				<PresetColorPairControl
					nextTextColor={ nextTextColor }
					nextBackgroundColor={ nextBackgroundColor }
					onChangeTextColor={ onChangeTextColor }
					onChangeBackgroundColor={ onChangeBackgroundColor }
				/>
				<ColorPickerControl
					label={ __( 'Change text color', 'newspack-profiles' ) }
					color={ nextTextColor }
					onChange={ onChangeTextColor }
					marginBottom="8px"
				/>
				<ColorPickerControl
					label={ __(
						'Change background color',
						'newspack-profiles'
					) }
					color={ nextBackgroundColor }
					onChange={ onChangeBackgroundColor }
				/>
			</div>

			<div
				style={ {
					display: 'flex',
					justifyContent: 'flex-end',
					gap: '8px',
				} }
			>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'newspack-profiles' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ onSubmit }
					disabled={
						! nextValue.trim() ||
						! nextTextColor ||
						! nextBackgroundColor
					}
				>
					{ submitLabel }
				</Button>
			</div>
		</Modal>
	);
};
