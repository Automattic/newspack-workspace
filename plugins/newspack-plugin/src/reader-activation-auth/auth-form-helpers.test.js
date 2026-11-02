import { shouldReuseActiveCode } from './auth-form-helpers';

describe( 'shouldReuseActiveCode', () => {
	it( 'reuses the code when "email me a code" is clicked after one was already sent', () => {
		expect( shouldReuseActiveCode( true, true ) ).toBe( true );
	} );

	it( 'requests a code when "email me a code" is clicked and none has been sent yet', () => {
		expect( shouldReuseActiveCode( true, false ) ).toBe( false );
	} );

	it( 'never reuses for the resend button, even after a code was sent', () => {
		expect( shouldReuseActiveCode( false, true ) ).toBe( false );
	} );

	it( 'requests a code for the resend button when none has been sent', () => {
		expect( shouldReuseActiveCode( false, false ) ).toBe( false );
	} );
} );
