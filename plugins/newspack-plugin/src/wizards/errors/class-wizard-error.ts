/**
 * Custom error class for Newspack Wizards.
 */
class WizardError extends Error {
	errorCode: string;
	details: string;

	constructor( message: string, errorCode: string, details: any = '' ) {
		super( message );
		this.name = 'WizardError';
		this.errorCode = errorCode;
		this.details = details;

		if ( Object.setPrototypeOf ) {
			Object.setPrototypeOf( this, new.target.prototype );
		}
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
			errorCode: this.errorCode,
			details: this.details,
			stackTrace: this.stack,
		};
	}
}

export default WizardError;
