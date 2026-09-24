import { rememberSessionSignal } from './session-signal';

const KEY = 'newspack-popups-test-signal';

/**
 * Invoke the helper for a `flag` param that is positive when its value is `1`.
 *
 * @return {boolean} The helper result.
 */
const detect = () => rememberSessionSignal( { param: 'flag', sessionKey: KEY, isPositive: value => '1' === value } );

describe( 'rememberSessionSignal', () => {
	beforeEach( () => {
		window.sessionStorage.clear();
		window.history.replaceState( {}, '', '/' );
	} );

	it( 'returns false when the param is absent and nothing is remembered', () => {
		expect( detect() ).toBe( false );
	} );

	it( 'returns true and remembers a positive param for the session', () => {
		window.history.replaceState( {}, '', '/?flag=1' );
		expect( detect() ).toBe( true );
		// Subsequent navigation to a clean URL still matches via the remembered flag.
		window.history.replaceState( {}, '', '/some-article' );
		expect( detect() ).toBe( true );
	} );

	it( 'returns false for a non-positive param value and does not remember it', () => {
		window.history.replaceState( {}, '', '/?flag=0' );
		expect( detect() ).toBe( false );
		window.history.replaceState( {}, '', '/some-article' );
		expect( detect() ).toBe( false );
	} );

	it( 'still detects a positive param when the sessionStorage write is blocked', () => {
		window.history.replaceState( {}, '', '/?flag=1' );
		const setSpy = jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		expect( () => detect() ).not.toThrow();
		expect( detect() ).toBe( true );
		setSpy.mockRestore();
	} );

	it( 'fails closed when sessionStorage is fully unavailable and no param is present', () => {
		window.history.replaceState( {}, '', '/some-article' );
		const setSpy = jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		const getSpy = jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'sessionStorage unavailable' );
		} );
		expect( () => detect() ).not.toThrow();
		expect( detect() ).toBe( false );
		setSpy.mockRestore();
		getSpy.mockRestore();
	} );
} );
