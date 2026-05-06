/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, ExternalLink, TextareaControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Grid, SelectControl, TextControl } from '../../../../../packages/components/src';

/**
 * Render a single settings field.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.field    Field declaration.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 */
export const SettingsField = ( { field, value, onChange } ) => {
	const { key, type, label, description, placeholder, options, help_url: helpUrl } = field;
	const help = (
		<>
			{ description }
			{ helpUrl && (
				<>
					{ ' ' }
					<ExternalLink href={ helpUrl }>{ __( 'Learn more', 'newspack-plugin' ) }</ExternalLink>
				</>
			) }
		</>
	);

	switch ( type ) {
		case 'metadata': {
			const selectedFields = Array.isArray( value ) ? value : [];
			const normalizedOptions = ( options || [] ).map( option =>
				typeof option === 'string' ? { value: option, label: option } : { value: option.value, label: option.label || option.value }
			);
			return (
				<div key={ key }>
					<h3>{ label }</h3>
					<Grid columns={ 3 } rowGap={ 16 }>
						{ normalizedOptions.map( ( { value: optionValue, label: optionLabel } ) => (
							<CheckboxControl
								className="newspack-checkbox-control"
								key={ optionValue }
								label={ optionLabel.replace( /:\s*$/, '' ) }
								checked={ selectedFields.includes( optionValue ) }
								onChange={ checked => {
									const newFields = checked ? [ ...selectedFields, optionValue ] : selectedFields.filter( f => f !== optionValue );
									onChange( newFields );
								} }
							/>
						) ) }
					</Grid>
				</div>
			);
		}
		case 'checkbox':
			return <CheckboxControl key={ key } label={ label } help={ help } checked={ !! value } onChange={ onChange } />;
		case 'select':
			return (
				<SelectControl
					key={ key }
					label={ label }
					help={ help }
					value={ value }
					options={ ( options || [] ).map( opt => ( {
						label: opt.label,
						value: opt.value,
					} ) ) }
					onChange={ onChange }
				/>
			);
		case 'textarea':
			return (
				<TextareaControl key={ key } label={ label } help={ help } value={ value || '' } placeholder={ placeholder } onChange={ onChange } />
			);
		case 'number':
			return (
				<TextControl
					key={ key }
					label={ label }
					help={ help }
					value={ value ?? '' }
					placeholder={ placeholder }
					onChange={ onChange }
					type="number"
				/>
			);
		case 'password':
			return (
				<TextControl
					key={ key }
					label={ label }
					help={ help }
					value={ value || '' }
					placeholder={ placeholder }
					onChange={ onChange }
					type="password"
				/>
			);
		case 'text':
		default:
			return <TextControl key={ key } label={ label } help={ help } value={ value || '' } placeholder={ placeholder } onChange={ onChange } />;
	}
};
