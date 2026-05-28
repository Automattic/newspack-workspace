/**
 * Internal dependencies.
 */
import { domReady } from '../utils';

/**
 * Initialize OTP input - transforms a single input into multiple digit inputs.
 *
 * @param {HTMLInputElement} originalInput The original input element with name="otp_code".
 *
 * @return {HTMLInputElement|null} The hidden input holding the OTP code value, the original input if maxlength is missing, or null if no input provided.
 */
export function initOTPInput( originalInput ) {
	if ( ! originalInput ) {
		return null;
	}
	const length = parseInt( originalInput.getAttribute( 'maxlength' ) );
	if ( ! length ) {
		return originalInput;
	}
	const inputContainer = originalInput.parentNode;
	inputContainer.removeChild( originalInput );
	const values = [];
	const otpCodeInput = document.createElement( 'input' );
	otpCodeInput.setAttribute( 'type', 'hidden' );
	otpCodeInput.setAttribute( 'name', 'otp_code' );
	inputContainer.appendChild( otpCodeInput );
	for ( let i = 0; i < length; i++ ) {
		const digit = document.createElement( 'input' );
		digit.setAttribute( 'type', 'text' );
		digit.setAttribute( 'pattern', '[0-9]' );
		digit.setAttribute( 'autocomplete', 0 === i ? 'one-time-code' : 'off' );
		digit.setAttribute( 'inputmode', 'numeric' );
		digit.setAttribute( 'data-index', i );
		digit.addEventListener( 'keydown', ev => {
			const prev = inputContainer.querySelector( `[data-index="${ i - 1 }"]` );
			const next = inputContainer.querySelector( `[data-index="${ i + 1 }"]` );
			switch ( ev.key ) {
				case 'Backspace':
					ev.preventDefault();
					ev.target.value = '';
					if ( prev ) {
						prev.focus();
					}
					values[ i ] = '';
					otpCodeInput.value = values.join( '' );
					break;
				case 'ArrowLeft':
					ev.preventDefault();
					if ( prev ) {
						prev.focus();
					}
					break;
				case 'ArrowRight':
					ev.preventDefault();
					if ( next ) {
						next.focus();
					}
					break;
				default:
					if ( ev.key.match( /^[0-9]$/ ) ) {
						ev.preventDefault();
						ev.target.value = ev.key;
						ev.target.dispatchEvent(
							new Event( 'input', {
								bubbles: true,
								cancelable: true,
							} )
						);
						if ( next ) {
							next.focus();
						}
					}
					break;
			}
		} );
		digit.addEventListener( 'input', ev => {
			const otpInput = ev.target.value.trim();
			if ( length === otpInput.length ) {
				for ( let index = 0; index < length; index++ ) {
					const char = otpInput[ index ];
					if ( /^[0-9]$/.test( char ) ) {
						const input = inputContainer.querySelector( `[data-index="${ index }"]` );
						input.value = char;
						values[ index ] = char;
					}
				}
				otpCodeInput.value = values.join( '' );
				return;
			} else if ( otpInput.match( /^[0-9]$/ ) ) {
				values[ i ] = otpInput;
				const next = inputContainer.querySelector( `[data-index="${ i + 1 }"]` );
				if ( next ) {
					next.focus();
				}
			} else {
				ev.target.value = '';
			}
			otpCodeInput.value = values.join( '' );
		} );
		digit.addEventListener( 'paste', ev => {
			ev.preventDefault();
			const paste = ( ev.clipboardData || window.clipboardData ).getData( 'text' );
			if ( paste.length !== length ) {
				return;
			}
			for ( let j = 0; j < length; j++ ) {
				if ( paste[ j ].match( /^[0-9]$/ ) ) {
					const digitInput = inputContainer.querySelector( `[data-index="${ j }"]` );
					digitInput.value = paste[ j ];
					values[ j ] = paste[ j ];
				}
			}
			otpCodeInput.value = values.join( '' );
		} );
		inputContainer.appendChild( digit );
	}
	return otpCodeInput;
}

/**
 * Initialize all OTP inputs on the page.
 */
export function initAllOTPInputs() {
	const otpInputs = document.querySelectorAll( 'input[name="otp_code"]' );
	otpInputs.forEach( initOTPInput );
}

// Auto-initialize on DOM ready for backwards compatibility
domReady( initAllOTPInputs );
