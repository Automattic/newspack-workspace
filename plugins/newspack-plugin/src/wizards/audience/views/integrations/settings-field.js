/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, ExternalLink, SelectControl, TextareaControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Grid, TextControl } from '../../../../../packages/components/src';

import './settings-field.scss';

/**
 * Whether a value counts as unset.
 *
 * @param {*} value Value to test.
 * @return {boolean} True when the value is absent or the empty string.
 */
export const isEmptyValue = value => value === undefined || value === null || value === '';

/**
 * Whether a select field offers an option that can actually be chosen.
 *
 * The ESP list call prepends a `None` entry to every successful response, so a
 * connected account with no audiences still arrives with one option. Counting
 * options would read that as a working list.
 *
 * @param {Object} field Field declaration.
 * @return {boolean} True when at least one option carries a non-empty value.
 */
export const hasSelectableOption = field => ( field.options || [] ).some( option => ! isEmptyValue( option.value ) );

/**
 * Whether a field declaration produces any rendered output.
 *
 * The metadata field is judged on `options`; the outbound one also carries
 * `grouped_options`, and the configure view extracts it before any field
 * reaches here.
 *
 * @param {Object} field Field declaration.
 * @return {boolean} True when `SettingsField` renders something for the field.
 */
export const settingsFieldRenders = field => {
	switch ( field.type ) {
		case 'hidden':
			return false;
		case 'select':
			// A list with nothing to pick stays on screen when it is required or already
			// set: the Enable flow sends publishers here to complete it, and a missing
			// section reads as a configured one.
			return !! field.required || ! isEmptyValue( field.value ) || hasSelectableOption( field );
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
		// Unreachable while the early return above stands. Kept because these fields
		// carry OAuth tokens: falling through to `default` would print them in a text input.
		case 'hidden':
			return null;
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
				<div key={ key } className="newspack-settings-field__metadata">
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
			const selectable = hasSelectableOption( field );
			return (
				<SelectControl
					key={ key }
					className={ selectable ? undefined : 'newspack-settings-field__empty-select' }
					label={ label }
					help={
						selectable ? (
							help
						) : (
							<>
								{ help } { __( 'No options are available. Check the connection to this integration.', 'newspack-plugin' ) }
							</>
						)
					}
					value={ selectable ? value : '' }
					options={
						selectable
							? ( options || [] ).map( opt => ( {
									label: opt.label,
									value: opt.value,
							  } ) )
							: [ { label: __( 'No options available', 'newspack-plugin' ), value: '' } ]
					}
					// aria-disabled rather than disabled: a disabled select is unfocusable, so
					// keyboard users would never reach the field the Enable flow sent them to,
					// nor the help text explaining why it is empty.
					aria-disabled={ ! selectable }
					onChange={ selectable ? onChange : () => {} }
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
