/**
 * Internal dependencies.
 */
import { domReady } from '../utils';

/**
 * Initialize an OTP input: keep its value to digits only, cut at the code length
 * from `data-otp-length`, and expose the length and how many digits are typed so
 * the ticks under the empty slots can follow the caret.
 *
 * @param {HTMLInputElement} input The input element with name="otp_code".
 *
 * @return {HTMLInputElement|null} The same input, or null if no input provided.
 */
export function initOTPInput( input ) {
	if ( ! input ) {
		return null;
	}
	if ( input.dataset.otpReady ) {
		return input;
	}
	input.dataset.otpReady = '1';
	const length = parseInt( input.dataset.otpLength ) || 0;
	if ( length ) {
		input.style.setProperty( '--newspack-ui-otp-length', String( length ) );
	}
	const sync = () => {
		const digits = input.value.replace( /\D/g, '' );
		const value = length ? digits.slice( 0, length ) : digits;
		if ( value !== input.value ) {
			input.value = value;
		}
		input.style.setProperty( '--newspack-ui-otp-typed', String( value.length ) );
	};
	input.addEventListener( 'input', sync );
	sync();

	// Letter spacing cannot use a percentage, so the slots get the field's width in pixels.
	const place = () => {
		if ( input.clientWidth ) {
			input.style.setProperty( '--newspack-ui-otp-field', `${ input.clientWidth }px` );
		}
	};
	if ( typeof ResizeObserver === 'function' ) {
		new ResizeObserver( place ).observe( input );
	}
	place();
	return input;
}

/**
 * Mark the code in an OTP input as rejected: flag it as invalid, select it so the
 * first keystroke replaces it, and keep the submit buttons disabled until the code
 * changes, so the same wrong code cannot be sent twice.
 *
 * @param {HTMLInputElement}    input         The OTP input.
 * @param {HTMLButtonElement[]} submitButtons The form's submit buttons.
 * @param {HTMLElement|null}    message       The element holding the error message.
 */
export function rejectOTPCode( input, submitButtons, message = null ) {
	clearOTPRejection( input );
	const rejected = input.value;
	const setDisabled = disabled => submitButtons.forEach( button => ( button.disabled = disabled ) );
	input.setAttribute( 'aria-invalid', 'true' );
	const describedBy = ( input.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( Boolean );
	if ( message?.id && ! describedBy.includes( message.id ) ) {
		input.setAttribute( 'aria-describedby', [ ...describedBy, message.id ].join( ' ' ) );
	}
	setDisabled( true );
	const onInput = () => {
		if ( input.value !== rejected ) {
			clearOTPRejection( input );
		}
	};
	input.addEventListener( 'input', onInput );
	input._clearOTPRejection = () => {
		input.removeEventListener( 'input', onInput );
		input.removeAttribute( 'aria-invalid' );
		const ids = ( input.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( id => id && id !== message?.id );
		if ( ids.length ) {
			input.setAttribute( 'aria-describedby', ids.join( ' ' ) );
		} else {
			input.removeAttribute( 'aria-describedby' );
		}
		input.closest( '[data-form-status]' )?.removeAttribute( 'data-form-status' );
		setDisabled( false );
	};
	input.focus();
	input.select();
}

/**
 * Undo rejectOTPCode().
 *
 * @param {HTMLInputElement} input The OTP input.
 */
export function clearOTPRejection( input ) {
	if ( input?._clearOTPRejection ) {
		const clear = input._clearOTPRejection;
		input._clearOTPRejection = null;
		clear();
	}
}

/**
 * Initialize all OTP inputs on the page.
 */
export function initAllOTPInputs() {
	const otpInputs = document.querySelectorAll( 'input[name="otp_code"]' );
	otpInputs.forEach( initOTPInput );
}

domReady( initAllOTPInputs );
