/**
 * Internal dependencies.
 */
import { domReady } from '../utils';

const SPACER = ' ';

/**
 * Get the digits typed in an OTP input, without the spacer between the two groups.
 *
 * @param {HTMLInputElement} input The OTP input.
 *
 * @return {string} The code.
 */
function getOTPCode( input ) {
	return input.value.replace( /\D/g, '' );
}

/**
 * Initialize an OTP input: keep its value to digits only, cut at the code length
 * from `data-otp-length`, and show it as two groups with a dash between them.
 *
 * The dash is drawn by CSS into a slot of its own. Once the second group starts,
 * the value holds a figure space in that slot, one digit wide, so every slot in
 * the letter-spaced field stays the same width.
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
	const doc = input.ownerDocument;
	const length = parseInt( input.dataset.otpLength ) || 6;
	const split = Math.ceil( length / 2 );
	input.style.setProperty( '--newspack-ui-otp-slots', String( length + 1 ) );
	input.style.setProperty( '--newspack-ui-otp-split', String( split ) );
	input.placeholder = '0'.repeat( split ) + SPACER + '0'.repeat( length - split );
	// Markup cached from before the spacer existed caps the field at the code length, which would refuse the last digit.
	input.removeAttribute( 'maxlength' );

	const format = digits => ( digits.length > split ? digits.slice( 0, split ) + SPACER + digits.slice( split ) : digits );

	// Letter spacing leaves a gap after the last digit, so a full code draws its own caret there.
	const markCaretEnd = () => {
		const atEnd =
			doc.hasFocus() && doc.activeElement === input && input.selectionStart === input.selectionEnd && input.selectionEnd === input.value.length;
		input.toggleAttribute( 'data-otp-caret-end', atEnd && getOTPCode( input ).length === length );
	};

	const sync = () => {
		const caretDigits = input.value.slice( 0, input.selectionStart ?? input.value.length ).replace( /\D/g, '' ).length;
		const value = format( getOTPCode( input ).slice( 0, length ) );
		if ( value !== input.value ) {
			input.value = value;
			if ( doc.activeElement === input ) {
				const caret = Math.min( caretDigits > split ? caretDigits + 1 : caretDigits, value.length );
				input.setSelectionRange( caret, caret );
			}
		}
		markCaretEnd();
	};

	// Deleting across the spacer removes the digit beyond it, never just the spacer.
	input.addEventListener( 'beforeinput', event => {
		const at = input.selectionStart;
		if ( ! event.cancelable || at !== input.selectionEnd ) {
			return;
		}
		let from = null;
		if ( 'deleteContentBackward' === event.inputType && SPACER === input.value[ at - 1 ] ) {
			from = at - 2;
		} else if ( 'deleteContentForward' === event.inputType && SPACER === input.value[ at ] ) {
			from = at;
		}
		if ( null !== from ) {
			event.preventDefault();
			input.setRangeText( '', from, from + 2 );
			input.setSelectionRange( from, from );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	} );
	input.addEventListener( 'input', sync );
	input.addEventListener( 'focus', markCaretEnd );
	input.addEventListener( 'blur', markCaretEnd );
	doc.addEventListener( 'selectionchange', () => doc.activeElement === input && markCaretEnd() );
	doc.defaultView?.addEventListener( 'focus', markCaretEnd );
	doc.defaultView?.addEventListener( 'blur', markCaretEnd );
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
