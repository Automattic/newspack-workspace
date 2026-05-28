/**
 * Hook for validating form fields.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { WizardError } from '../errors';

/**
 * Known common validation callbacks.
 */
const knownValidationCallbacks = {
	/**
	 * Is string a valid ID?
	 *
	 * @param value        ID string to test
	 * @param errorMessage Optional error message to display on failure
	 * @return             Empty string if ID is valid, error message otherwise
	 */
	isId( value: string, errorMessage: string = __( 'Field cannot be empty!', 'newspack-plugin' ) ) {
		return /^[A-Za-z0-9_-]*$/.test( value ) ? '' : errorMessage;
	},
	/**
	 * Is string a valid url?
	 *
	 * @param value        Url string to test
	 * @param errorMessage Optional error message to display on failure
	 * @return             Empty string if URL is valid, error message otherwise
	 */
	isUrl( value: string, errorMessage: string = __( 'Invalid URL!', 'newspack-plugin' ) ) {
		return '' === value || /^https?:\/\/[^\s]*$/.test( value ) ? '' : errorMessage;
	},
};

/**
 * Array of tupils where each tupil contains:
 * 1. Field name
 * 2. Validation callback name or custom validation callback
 * 3. (Optional) Configuration object
 */
type ValidationMap< TData, TConfig > = [
	keyof TData,
	keyof typeof knownValidationCallbacks | ( ( inputValue: string ) => string ),
	( TConfig & {
		dependsOn?: Record< keyof TData, string >;
		message?: string;
	} )?,
][];

/**
 * React hook for validating form fields.
 */
export function useFieldsValidation< TData, TConfig = Record< string, unknown > >( config: ValidationMap< TData, TConfig >, data: TData ) {
	const [ errorMessage, setErrorMessage ] = useState< WizardError | null >( null );
	return {
		isInputsValid() {
			for ( const [ key, callback, options ] of config ) {
				const inputValue = data[ key ] as string;
				const isFieldValid = ( typeof callback === 'string' ? knownValidationCallbacks[ callback ] : callback )(
					inputValue,
					options?.message
				);
				if ( '' !== isFieldValid ) {
					setErrorMessage( new WizardError( isFieldValid, `invalid_field_${ key.toString() }` ) );
					return false;
				}
			}
			setErrorMessage( null );
			return true;
		},
		errorMessage: errorMessage instanceof WizardError ? errorMessage.message : '',
	};
}

export default useFieldsValidation;
