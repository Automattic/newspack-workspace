import {
	Button,
	ColorIndicator,
	ColorPicker,
	Dropdown,
} from '@wordpress/components';
import { getColorValue } from './utils';

type ColorPickerControlProps = {
	label: string;
	color: string;
	onChange: ( color: string ) => void;
	marginBottom?: string;
};

export const ColorPickerControl = ( {
	label,
	color,
	onChange,
	marginBottom,
}: ColorPickerControlProps ) => (
	<div
		style={ {
			display: 'flex',
			alignItems: 'center',
			gap: '8px',
			marginBottom,
		} }
	>
		<ColorIndicator colorValue={ color } />
		<Dropdown
			renderToggle={ ( { isOpen: isPickerOpen, onToggle } ) => (
				<Button
					variant="secondary"
					onClick={ onToggle }
					aria-expanded={ isPickerOpen }
				>
					{ label }
				</Button>
			) }
			renderContent={ () => (
				<ColorPicker
					color={ color }
					enableAlpha={ false }
					onChangeComplete={ ( selectedColor ) =>
						onChange( getColorValue( selectedColor, color ) )
					}
				/>
			) }
		/>
	</div>
);
