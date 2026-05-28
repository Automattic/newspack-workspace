/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { TextControl } from '../';

const MinMaxSetting = ( { min, max, onChangeMin, onChangeMax, minPlaceholder, maxPlaceholder, ...props } ) => {
	return (
		<div { ...props }>
			<div className="newspack-settings__min-max">
				<CheckboxControl checked={ min > 0 } onChange={ value => onChangeMin( value ? 1 : 0 ) } label={ __( 'Min', 'newspack-plugin' ) } />
				<TextControl
					data-testid="min"
					type="number"
					value={ min }
					placeholder={ minPlaceholder }
					onChange={ value => onChangeMin( value > 0 ? value : 0 ) }
				/>
			</div>
			<div className="newspack-settings__min-max" data-testid="max">
				<CheckboxControl
					checked={ max > 0 }
					onChange={ value => onChangeMax( value ? min || 1 : 0 ) }
					label={ __( 'Max', 'newspack-plugin' ) }
				/>
				<TextControl
					data-testid="max"
					type="number"
					value={ max }
					placeholder={ maxPlaceholder }
					onChange={ value => onChangeMax( value > 0 ? value : 0 ) }
				/>
			</div>
		</div>
	);
};
export default MinMaxSetting;
