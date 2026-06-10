/* eslint-disable jsdoc/check-tag-names */
/**
 * @jest-environment jsdom
 */
/* eslint-enable jsdoc/check-tag-names */

const REGISTRY_KEY = '__newspackNewslettersLocalListModalExtensions';

describe( 'extension registry', () => {
	beforeEach( () => {
		// Reset modules so the module's window-init side effects re-run, and
		// clear the shared window state so each test starts with an empty
		// registry.
		jest.resetModules();
		delete window.newspack;
		delete window[ REGISTRY_KEY ];
	} );

	it( 'registers and retrieves an extension', () => {
		const { registerLocalListModalExtension, getLocalListModalExtensions } = require( './extensions' );
		const ext = { render: () => 'a', onSave: jest.fn() };
		registerLocalListModalExtension( 'a', ext );
		expect( getLocalListModalExtensions() ).toEqual( [ ext ] );
	} );

	it( 'preserves registration order across multiple extensions', () => {
		const { registerLocalListModalExtension, getLocalListModalExtensions } = require( './extensions' );
		const a = { render: () => 'a' };
		const b = { render: () => 'b' };
		registerLocalListModalExtension( 'a', a );
		registerLocalListModalExtension( 'b', b );
		expect( getLocalListModalExtensions() ).toEqual( [ a, b ] );
	} );

	it( 'replaces an existing entry with a console.warn', () => {
		const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const { registerLocalListModalExtension, getLocalListModalExtensions } = require( './extensions' );
		registerLocalListModalExtension( 'a', { render: () => 'first' } );
		registerLocalListModalExtension( 'a', { render: () => 'second' } );
		expect( warn ).toHaveBeenCalledWith( expect.stringContaining( 'a' ) );
		expect( getLocalListModalExtensions() ).toHaveLength( 1 );
		expect( getLocalListModalExtensions()[ 0 ].render() ).toBe( 'second' );
		warn.mockRestore();
	} );

	it( 'drains _pendingExtensions queue on first import', () => {
		window.newspack = { newsletters: { _pendingExtensions: [ [ 'pre', { render: () => 'pre' } ] ] } };
		const { getLocalListModalExtensions } = require( './extensions' );
		expect( getLocalListModalExtensions() ).toHaveLength( 1 );
		expect( window.newspack.newsletters._pendingExtensions ).toHaveLength( 0 );
	} );

	it( 'exposes registerLocalListModalExtension on window.newspack.newsletters for late registrations', () => {
		require( './extensions' );
		expect( typeof window.newspack.newsletters.registerLocalListModalExtension ).toBe( 'function' );
	} );

	it( 'filters by `kind`, defaulting unscoped extensions to local', () => {
		const { registerLocalListModalExtension, getLocalListModalExtensions } = require( './extensions' );
		const legacy = { render: () => 'legacy' };
		const espOnly = { render: () => 'esp', appliesTo: [ 'esp' ] };
		const both = { render: () => 'both', appliesTo: [ 'local', 'esp' ] };
		registerLocalListModalExtension( 'legacy', legacy );
		registerLocalListModalExtension( 'esp', espOnly );
		registerLocalListModalExtension( 'both', both );

		expect( getLocalListModalExtensions() ).toEqual( [ legacy, both ] );
		expect( getLocalListModalExtensions( 'local' ) ).toEqual( [ legacy, both ] );
		expect( getLocalListModalExtensions( 'esp' ) ).toEqual( [ espOnly, both ] );
	} );

	it( 'treats an empty appliesTo array as local-only (defensive default)', () => {
		const { registerLocalListModalExtension, getLocalListModalExtensions } = require( './extensions' );
		const empty = { render: () => 'empty', appliesTo: [] };
		registerLocalListModalExtension( 'empty', empty );
		expect( getLocalListModalExtensions( 'local' ) ).toEqual( [ empty ] );
		expect( getLocalListModalExtensions( 'esp' ) ).toEqual( [] );
	} );

	it( 'shares the registry across multiple imports of the module (cross-bundle safety)', () => {
		// Simulate two separate webpack entries each importing this module.
		// `jest.isolateModules` evaluates the module in a fresh registry, so
		// the two `require` calls return independent module instances — the
		// same situation as two webpack bundles. They must still see each
		// other's registrations because the underlying Map lives on `window`.
		let bundleA;
		let bundleB;
		jest.isolateModules( () => {
			bundleA = require( './extensions' );
		} );
		jest.isolateModules( () => {
			bundleB = require( './extensions' );
		} );
		const ext = { render: () => 'shared' };
		bundleA.registerLocalListModalExtension( 'shared', ext );
		expect( bundleB.getLocalListModalExtensions() ).toEqual( [ ext ] );
	} );
} );
