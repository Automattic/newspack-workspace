/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { TextControl } from '../';

type MinMaxSettingProps = {
	/** The minimum value. */
	min?: number;
	/** The maximum value. */
	max?: number;
	/** Called with the new minimum value. */
	onChangeMin: ( value: number | string ) => void;
	/** Called with the new maximum value. */
	onChangeMax: ( value: number | string ) => void;
	/** Placeholder for the minimum-value input. */
	minPlaceholder?: string;
	/** Placeholder for the maximum-value input. */
	maxPlaceholder?: string;
} & Omit< React.HTMLAttributes< HTMLDivElement >, 'onChange' >;

const MinMaxSetting = ( { min, max, onChangeMin, onChangeMax, minPlaceholder, maxPlaceholder, ...props }: MinMaxSettingProps ) => {
	return (
		<div { ...props }>
			<div className="newspack-settings__min-max">
				<CheckboxControl
					checked={ !! min && min > 0 }
					onChange={ value => onChangeMin( value ? 1 : 0 ) }
					label={ __( 'Min', 'newspack-plugin' ) }
				/>
				<TextControl
					data-testid="min"
					type="number"
					value={ min }
					placeholder={ minPlaceholder }
					onChange={ ( value: string | number ) => onChangeMin( Number( value ) > 0 ? value : 0 ) }
				/>
			</div>
			<div className="newspack-settings__min-max" data-testid="max">
				<CheckboxControl
					checked={ !! max && max > 0 }
					onChange={ value => onChangeMax( value ? min || 1 : 0 ) }
					label={ __( 'Max', 'newspack-plugin' ) }
				/>
				<TextControl
					data-testid="max"
					type="number"
					value={ max }
					placeholder={ maxPlaceholder }
					onChange={ ( value: string | number ) => onChangeMax( Number( value ) > 0 ? value : 0 ) }
				/>
			</div>
		</div>
	);
};
export default MinMaxSetting;
