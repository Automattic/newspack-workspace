/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, ExternalLink, TextareaControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Grid, SelectControl, TextControl } from '../../../../../packages/components/src';

/**
 * Whether a field declaration produces any rendered output.
 *
 * Option-driven types are judged on `options` alone. The outbound metadata
 * field keeps its data in `grouped_options`, and the configure view extracts
 * it before any field reaches here.
 *
 * @param {Object} field Field declaration.
 * @return {boolean} True when `SettingsField` renders something for the field.
 */
export const settingsFieldRenders = field => {
	switch ( field.type ) {
		case 'hidden':
			return false;
		case 'select':
			// A required list stays on screen with nothing to pick: the Enable flow sends
			// publishers here to complete it, and a missing section reads as a configured one.
			return !! field.required || ( field.options || [] ).length > 0;
		case 'metadata':
			return ( field.options || [] ).length > 0;
		default:
			return true;
	}
};

/**
 * Render a single settings field.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.field    Field declaration.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 */
export const SettingsField = ( { field, value, onChange } ) => {
	if ( ! settingsFieldRenders( field ) ) {
		return null;
	}

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
		case 'oauth': {
			const isConnected = !! value;
			const oauthUrl = field.oauth_url || '';
			return (
				<div key={ key } className="newspack-oauth-field">
					<p>
						<strong>{ label }</strong>
					</p>
					{ ( description || helpUrl ) && <p>{ help }</p> }
					{ isConnected ? (
						<>
							<p>{ value }</p>
							{ field.disconnect_url && (
								<Button variant="secondary" isDestructive href={ field.disconnect_url }>
									{ __( 'Disconnect', 'newspack-plugin' ) }
								</Button>
							) }
						</>
					) : (
						<Button variant="primary" href={ oauthUrl || undefined } disabled={ ! oauthUrl }>
							{ __( 'Connect', 'newspack-plugin' ) }
						</Button>
					) }
				</div>
			);
		}
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
								__nextHasNoMarginBottom
							/>
						) ) }
					</Grid>
				</div>
			);
		}
		case 'checkbox':
			return <CheckboxControl key={ key } label={ label } help={ help } checked={ !! value } onChange={ onChange } __nextHasNoMarginBottom />;
		case 'select': {
			const selectOptions = options || [];
			const hasOptions = selectOptions.length > 0;
			return (
				<SelectControl
					key={ key }
					label={ label }
					help={ hasOptions ? help : __( 'No options are available. Check the connection to this integration.', 'newspack-plugin' ) }
					value={ hasOptions ? value : '' }
					options={
						hasOptions
							? selectOptions.map( opt => ( {
									label: opt.label,
									value: opt.value,
							  } ) )
							: [ { label: __( 'No options available', 'newspack-plugin' ), value: '' } ]
					}
					onChange={ onChange }
					disabled={ ! hasOptions }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			);
		}
		case 'textarea':
			return (
				<TextareaControl
					key={ key }
					label={ label }
					help={ help }
					value={ value || '' }
					placeholder={ placeholder }
					onChange={ onChange }
					__nextHasNoMarginBottom
				/>
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
					withMargin={ false }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
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
					withMargin={ false }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			);
		case 'text':
		default:
			return (
				<TextControl
					key={ key }
					label={ label }
					help={ help }
					value={ value || '' }
					placeholder={ placeholder }
					onChange={ onChange }
					withMargin={ false }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			);
	}
};
