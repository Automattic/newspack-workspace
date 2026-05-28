/**
 * Internal dependencies
 */
import WizardError from './class-wizard-error';

/**
 * Custom error class for Newspack Wizards API requests.
 */
class WizardApiError extends WizardError {
	statusCode: number;

	constructor( message: string, statusCode: number, errorCode: string, details = '' ) {
		super( message, errorCode, details );
		this.name = 'WizardApiError';
		this.statusCode = statusCode;

		// Set the prototype explicitly.
		Object.setPrototypeOf( this, WizardApiError.prototype );
	}

	/**
	 * For when this class is serialized outside of the API.
	 *
	 * @return JSON representation of the error.
	 */
	toJSON() {
		return {
			name: this.name,
			message: this.message,
			statusCode: this.statusCode,
			errorCode: this.errorCode,
			details: this.details,
			stackTrace: this.stack,
		};
	}
}

export default WizardApiError;
