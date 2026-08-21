import { getBackTarget } from './auth-form-helpers';

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
} );
