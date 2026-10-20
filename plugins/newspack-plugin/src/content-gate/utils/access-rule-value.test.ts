/**
 * Internal dependencies.
 */
import { getAccessRuleValueNotice, getUntrustedReaderDataKeys } from './access-rule-value';

const readerData = { trusted_keys: [ 'plan' ] };

describe( 'getUntrustedReaderDataKeys', () => {
	it( 'returns the keys the server does not set', () => {
		expect( getUntrustedReaderDataKeys( readerData, 'plan=a; utm_source=b' ) ).toEqual( [ 'utm_source' ] );
	} );

	it( 'returns nothing when every key is trusted', () => {
		expect( getUntrustedReaderDataKeys( readerData, 'plan=a;plan=b' ) ).toEqual( [] );
	} );

	it( 'returns nothing when the rule carries no trusted keys', () => {
		expect( getUntrustedReaderDataKeys( {}, 'utm_source=b' ) ).toEqual( [] );
		expect( getUntrustedReaderDataKeys( undefined, 'utm_source=b' ) ).toEqual( [] );
	} );

	it( 'skips empty items and items without a key or an equals sign', () => {
		expect( getUntrustedReaderDataKeys( readerData, ';; =x; nokey ;a=b=c' ) ).toEqual( [ 'a' ] );
	} );

	it( 'lists each untrusted key once', () => {
		expect( getUntrustedReaderDataKeys( readerData, 'a=1;a=2;b=3' ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'ignores values that are not text', () => {
		expect( getUntrustedReaderDataKeys( readerData, [ 'a=1' ] ) ).toEqual( [] );
		expect( getUntrustedReaderDataKeys( readerData, null ) ).toEqual( [] );
	} );
} );

describe( 'getAccessRuleValueNotice for reader data', () => {
	it( 'names a single untrusted key', () => {
		expect( getAccessRuleValueNotice( readerData, 'utm_source=x', false ) ).toBe(
			'utm_source isn’t set by the site’s server, so this rule matches no reader. Use a key the server sets, or remove it.'
		);
	} );

	it( 'names several untrusted keys', () => {
		expect( getAccessRuleValueNotice( readerData, 'a=1;b=2', false ) ).toBe(
			'a, b aren’t set by the site’s server, so this rule matches no reader. Use keys the server sets, or remove them.'
		);
	} );

	it( 'returns no notice when all keys are trusted', () => {
		expect( getAccessRuleValueNotice( readerData, 'plan=x', false ) ).toBeUndefined();
	} );

	it( 'returns no notice when trusted_keys is absent', () => {
		expect( getAccessRuleValueNotice( {}, 'utm_source=x', false ) ).toBeUndefined();
	} );

	it( 'explains a promoted field whose name the browser also uses', () => {
		expect( getAccessRuleValueNotice( { is_untrusted: true }, 'x', false ) ).toBe(
			'The browser can also set this field’s value, so this rule matches no reader.'
		);
	} );
} );
