import { initOTPInput, rejectOTPCode, clearOTPRejection } from './otp-input';

const SPACER = '\u2007';

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

	it( 'keeps one visible input with a slot for the dash and a placeholder split the same way', () => {
		expect( document.querySelectorAll( 'input' ) ).toHaveLength( 1 );
		expect( input.style.getPropertyValue( '--newspack-ui-otp-slots' ) ).toBe( '7' );
		expect( input.style.getPropertyValue( '--newspack-ui-otp-split' ) ).toBe( '3' );
		expect( input.placeholder ).toBe( `000${ SPACER }000` );
	} );

	it( 'holds the dash slot open only once the second group starts', () => {
		type( input, '482' );
		expect( input.value ).toBe( '482' );
		type( input, '4829' );
		expect( input.value ).toBe( `482${ SPACER }9` );
	} );

	it( 'keeps digits only, up to the code length, for pasted or autofilled codes', () => {
		[ '482917', ' 482-917 9', '482 917', '482–917', `482${ SPACER }917` ].forEach( pasted => {
			type( input, pasted );
			expect( input.value ).toBe( `482${ SPACER }917` );
		} );
	} );

	it( 'removes the digit before the dash slot on Backspace, never just the slot', () => {
		type( input, '4829' );
		input.focus();
		input.setSelectionRange( 4, 4 );
		const event = new InputEvent( 'beforeinput', { inputType: 'deleteContentBackward', cancelable: true } );
		input.dispatchEvent( event );
		expect( event.defaultPrevented ).toBe( true );
		expect( input.value ).toBe( '489' );
		expect( input.selectionStart ).toBe( 2 );
	} );

	it( 'lets the rest of the form react to a delete across the dash slot', () => {
		type( input, '482917' );
		rejectOTPCode( input, [ button ], message );
		input.setSelectionRange( 4, 4 );
		input.dispatchEvent( new InputEvent( 'beforeinput', { inputType: 'deleteContentBackward', cancelable: true } ) );
		expect( input.value ).toBe( `489${ SPACER }17` );
		expect( input.hasAttribute( 'aria-invalid' ) ).toBe( false );
		expect( button.disabled ).toBe( false );
	} );

	it( 'leaves a deletion it cannot cancel to the browser', () => {
		type( input, '4829' );
		input.focus();
		input.setSelectionRange( 4, 4 );
		input.dispatchEvent( new InputEvent( 'beforeinput', { inputType: 'deleteContentBackward', cancelable: false } ) );
		expect( input.value ).toBe( `482${ SPACER }9` );
	} );

	it( 'lifts the length cap in markup cached from before the spacer, which would refuse the last digit', () => {
		document.body.innerHTML = '<input name="otp_code" type="text" maxlength="6" placeholder="6-digit code" />';
		const cached = initOTPInput( document.querySelector( 'input' ) );
		expect( cached.hasAttribute( 'maxlength' ) ).toBe( false );
		type( cached, '482917' );
		expect( cached.value ).toBe( `482${ SPACER }917` );
	} );

	it( 'draws its own caret only when the code is complete and the caret is at the end', () => {
		input.focus();
		type( input, '48291' );
		expect( input.hasAttribute( 'data-otp-caret-end' ) ).toBe( false );
		type( input, '482917' );
		input.setSelectionRange( 7, 7 );
		input.dispatchEvent( new Event( 'focus' ) );
		expect( input.hasAttribute( 'data-otp-caret-end' ) ).toBe( true );
		input.setSelectionRange( 0, 7 );
		document.dispatchEvent( new Event( 'selectionchange' ) );
		expect( input.hasAttribute( 'data-otp-caret-end' ) ).toBe( false );
		input.blur();
		expect( input.hasAttribute( 'data-otp-caret-end' ) ).toBe( false );
	} );

	it( 'selects a rejected code and keeps Continue disabled until the code changes', () => {
		type( input, '482917' );
		rejectOTPCode( input, [ button ], message );

		expect( input.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe( 'lead response' );
		expect( document.activeElement ).toBe( input );
		expect( input.selectionStart ).toBe( 0 );
		expect( input.selectionEnd ).toBe( 7 );
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
