import { initOTPInput, rejectOTPCode, clearOTPRejection } from './otp-input';

const type = ( input, value ) => {
	input.value = value;
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
};

describe( 'OTP input', () => {
	let input;
	let button;
	let message;

	beforeEach( () => {
		document.body.innerHTML = `
			<form>
				<input name="otp_code" type="text" data-otp-length="6" aria-describedby="lead" />
				<div class="response" id="response">That code isn't right.</div>
				<button type="submit">Continue</button>
			</form>
		`;
		input = initOTPInput( document.querySelector( 'input' ) );
		button = document.querySelector( 'button' );
		message = document.getElementById( 'response' );
	} );

	it( 'keeps one visible input and counts typed digits', () => {
		expect( document.querySelectorAll( 'input' ) ).toHaveLength( 1 );
		type( input, '48' );
		expect( input.style.getPropertyValue( '--newspack-ui-otp-typed' ) ).toBe( '2' );
	} );

	it( 'keeps digits only, up to the code length, for pasted or autofilled codes', () => {
		type( input, ' 482-917 9' );
		expect( input.value ).toBe( '482917' );
		expect( input.style.getPropertyValue( '--newspack-ui-otp-typed' ) ).toBe( '6' );
	} );

	it( 'leaves the length to the script, so a paste with spaces or a dash keeps every digit', () => {
		expect( input.style.getPropertyValue( '--newspack-ui-otp-length' ) ).toBe( '6' );
		type( input, ' 482917' );
		expect( input.value ).toBe( '482917' );
	} );

	it( 'selects a rejected code and keeps Continue disabled until the code changes', () => {
		type( input, '482917' );
		rejectOTPCode( input, [ button ], message );

		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe( 'lead response' );
		expect( document.activeElement ).toBe( input );
		expect( input.selectionStart ).toBe( 0 );
		expect( input.selectionEnd ).toBe( 6 );
		expect( button.disabled ).toBe( true );

		type( input, '482917' );
		expect( button.disabled ).toBe( true );

		type( input, '48291' );
		expect( button.disabled ).toBe( false );
		expect( input.hasAttribute( 'aria-invalid' ) ).toBe( false );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe( 'lead' );
	} );

	it( 'undoes a rejection, including the form status that marks the field red', () => {
		const form = document.querySelector( 'form' );
		form.setAttribute( 'data-form-status', '400' );
		type( input, '482917' );
		rejectOTPCode( input, [ button ], message );
		clearOTPRejection( input );
		expect( button.disabled ).toBe( false );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe( 'lead' );
		expect( form.hasAttribute( 'data-form-status' ) ).toBe( false );
	} );
} );
