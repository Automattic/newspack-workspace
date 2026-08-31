import { getBackTarget, shouldReuseActiveCode } from './auth-form-helpers';

describe( 'getBackTarget', () => {
	it( 'returns to the password step from the code step when the reader has a password', () => {
		expect( getBackTarget( 'otp', true ) ).toBe( 'pwd' );
	} );

	it( 'returns to the email step from the code step when the reader has no password', () => {
		expect( getBackTarget( 'otp', false ) ).toBe( 'signin' );
	} );

	it( 'returns to the email step from the password step regardless of password state', () => {
		expect( getBackTarget( 'pwd', true ) ).toBe( 'signin' );
	} );

	it( 'defaults to the email step when password state is unknown', () => {
		expect( getBackTarget( 'otp', undefined ) ).toBe( 'signin' );
	} );

	it( 'returns to the email step from the email step itself', () => {
		expect( getBackTarget( 'signin', true ) ).toBe( 'signin' );
	} );

	it( 'returns to the email step from the success step', () => {
		expect( getBackTarget( 'success', true ) ).toBe( 'signin' );
	} );
} );

describe( 'shouldReuseActiveCode', () => {
	it( 'reuses the existing code when "email me a code" is clicked and a code is active', () => {
		expect( shouldReuseActiveCode( true, true ) ).toBe( true );
	} );

	it( 'requests a new code when "email me a code" is clicked and no code is active', () => {
		expect( shouldReuseActiveCode( true, false ) ).toBe( false );
	} );

	it( 'never reuses for the resend button, even when a code is active', () => {
		expect( shouldReuseActiveCode( false, true ) ).toBe( false );
	} );

	it( 'requests a new code for the resend button when none is active', () => {
		expect( shouldReuseActiveCode( false, false ) ).toBe( false );
	} );
} );
