/**
 * Tests for the Subscribers wizard link helpers.
 *
 * isInternalHashPath guards a security boundary: the person profile reads its
 * `?from=` origin straight from the URL and puts it into anchor hrefs, so this
 * predicate is what stands between an attacker-supplied value and a live
 * javascript: URL — or an off-site redirect — in an authenticated admin origin.
 */

/**
 * Internal dependencies
 */
import { isInternalHashPath, groupDetailHref } from './links';

describe( 'isInternalHashPath', () => {
	it( "accepts the wizard's own hash routes", () => {
		expect( isInternalHashPath( '#/' ) ).toBe( true );
		expect( isInternalHashPath( '#/groups' ) ).toBe( true );
		expect( isInternalHashPath( '#/subscribers/29' ) ).toBe( true );
	} );

	it( 'rejects a javascript: URL — the DOM-XSS payload', () => {
		expect( isInternalHashPath( 'javascript:alert(1)' ) ).toBe( false ); // eslint-disable-line no-script-url
		expect( isInternalHashPath( '#javascript:alert(1)' ) ).toBe( false ); // eslint-disable-line no-script-url
	} );

	it( 'rejects a protocol-relative hash that a browser can treat as off-site', () => {
		expect( isInternalHashPath( '#//evil.example' ) ).toBe( false );
	} );

	it( 'rejects off-site and non-hash values', () => {
		expect( isInternalHashPath( 'https://evil.example' ) ).toBe( false );
		expect( isInternalHashPath( '/wp-admin/' ) ).toBe( false );
		expect( isInternalHashPath( '#' ) ).toBe( false );
		expect( isInternalHashPath( '#/../evil' ) ).toBe( true ); // still an internal hash; the router resolves it, no protocol escape
		expect( isInternalHashPath( null ) ).toBe( false );
		expect( isInternalHashPath( undefined ) ).toBe( false );
	} );
} );

describe( 'groupDetailHref', () => {
	it( 'points a group at the in-wizard detail route, and nowhere without an id', () => {
		expect( groupDetailHref( { id: 29 } ) ).toBe( '#/groups/29' );
		expect( groupDetailHref( {} ) ).toBe( '' );
		expect( groupDetailHref( undefined ) ).toBe( '' );
	} );

	it( 'produces a route the profile back-link guard accepts', () => {
		expect( isInternalHashPath( groupDetailHref( { id: 29 } ) ) ).toBe( true );
	} );
} );
