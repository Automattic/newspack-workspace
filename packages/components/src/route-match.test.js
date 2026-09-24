/**
 * Internal dependencies.
 */
import { matchesRoute } from './route-match';

describe( 'matchesRoute', () => {
	it( 'matches the root path exactly, never as a prefix', () => {
		expect( matchesRoute( { path: '/' }, '/' ) ).toBe( true );
		expect( matchesRoute( { path: '/' }, '/segments' ) ).toBe( false );
	} );

	it( 'matches a path as a prefix by default, at segment boundaries', () => {
		expect( matchesRoute( { path: '/segments' }, '/segments' ) ).toBe( true );
		expect( matchesRoute( { path: '/segments' }, '/segments/123' ) ).toBe( true );
		expect( matchesRoute( { path: '/segments' }, '/segments-old' ) ).toBe( false );
		expect( matchesRoute( { path: '/segments' }, '/donations' ) ).toBe( false );
	} );

	it( 'matches exactly when the item opts in via exact', () => {
		expect( matchesRoute( { path: '/segments', exact: true }, '/segments' ) ).toBe( true );
		expect( matchesRoute( { path: '/segments', exact: true }, '/segments/123' ) ).toBe( false );
	} );

	it( 'matches an activeTabPaths entry exactly', () => {
		const item = { path: '/other', activeTabPaths: [ '/segments/new' ] };
		expect( matchesRoute( item, '/segments/new' ) ).toBe( true );
		expect( matchesRoute( item, '/segments/123' ) ).toBe( false );
	} );

	it( 'anchors an activeTabPaths wildcard at a segment boundary', () => {
		const item = { path: '/other', activeTabPaths: [ '/segments/*' ] };
		expect( matchesRoute( item, '/segments' ) ).toBe( true );
		expect( matchesRoute( item, '/segments/123' ) ).toBe( true );
		expect( matchesRoute( item, '/segments-old' ) ).toBe( false );

		const noSlash = { path: '/other', activeTabPaths: [ '/segments*' ] };
		expect( matchesRoute( noSlash, '/segments' ) ).toBe( true );
		expect( matchesRoute( noSlash, '/segments/123' ) ).toBe( true );
		expect( matchesRoute( noSlash, '/segments-old' ) ).toBe( false );
	} );

	it( 'falls through to path matching when activeTabPaths misses', () => {
		expect( matchesRoute( { path: '/segments', activeTabPaths: [ '/elsewhere' ] }, '/segments/123' ) ).toBe( true );
	} );

	it( 'never matches an item without a path when activeTabPaths misses', () => {
		expect( matchesRoute( {}, '/segments' ) ).toBe( false );
		expect( matchesRoute( { activeTabPaths: [ '/elsewhere' ] }, '/segments' ) ).toBe( false );
	} );
} );
