/**
 * Internal dependencies.
 */
import { isItemActive } from './index';

describe( 'isItemActive', () => {
	it( 'treats an explicitly selected item as active regardless of pathname', () => {
		expect( isItemActive( { selected: true, path: '/other' }, '/current' ) ).toBe( true );
		expect( isItemActive( { selected: true }, null ) ).toBe( true );
	} );

	describe( 'outside a router (pathname is null)', () => {
		afterEach( () => {
			delete window.location;
			window.location = new URL( 'http://localhost/' );
		} );

		it( 'is active when the href matches the current URL', () => {
			delete window.location;
			window.location = new URL( 'http://example.com/wp-admin/admin.php?page=ads' );
			expect( isItemActive( { href: 'http://example.com/wp-admin/admin.php?page=ads' }, null ) ).toBe( true );
		} );

		it( 'is inactive when the href does not match', () => {
			delete window.location;
			window.location = new URL( 'http://example.com/wp-admin/admin.php?page=ads' );
			expect( isItemActive( { href: 'http://example.com/wp-admin/admin.php?page=other' }, null ) ).toBe( false );
		} );

		it( 'is inactive when the item has no href', () => {
			expect( isItemActive( { path: '/ads' }, null ) ).toBe( false );
		} );
	} );

	describe( 'inside a router', () => {
		it( 'matches on an exact path', () => {
			expect( isItemActive( { path: '/donations' }, '/donations' ) ).toBe( true );
			expect( isItemActive( { path: '/donations' }, '/segments' ) ).toBe( false );
		} );

		it( 'does not treat a path prefix as a match by default', () => {
			expect( isItemActive( { path: '/segments' }, '/segments/123' ) ).toBe( false );
		} );

		it( 'matches a path prefix when exact is false', () => {
			expect( isItemActive( { path: '/segments', exact: false }, '/segments' ) ).toBe( true );
			expect( isItemActive( { path: '/segments', exact: false }, '/segments/123' ) ).toBe( true );
			expect( isItemActive( { path: '/segments', exact: false }, '/donations' ) ).toBe( false );
		} );

		it( 'does not match a prefix-colliding sibling when exact is false', () => {
			expect( isItemActive( { path: '/segments', exact: false }, '/segments-old' ) ).toBe( false );
			expect( isItemActive( { path: '/segments', exact: false }, '/segmentsnew' ) ).toBe( false );
		} );

		it( 'matches an activeTabPaths entry exactly', () => {
			const item = { path: '/segments', activeTabPaths: [ '/segments', '/segments/new' ] };
			expect( isItemActive( item, '/segments/new' ) ).toBe( true );
			expect( isItemActive( item, '/segments/123' ) ).toBe( false );
		} );

		it( 'matches an activeTabPaths wildcard as a prefix', () => {
			const item = { path: '/other', activeTabPaths: [ '/segments/*' ] };
			expect( isItemActive( item, '/segments/123' ) ).toBe( true );
			expect( isItemActive( item, '/segments' ) ).toBe( false );
			expect( isItemActive( item, '/donations' ) ).toBe( false );
		} );

		it( 'keeps the parent tab active on a hidden subpage via wildcard', () => {
			const item = { path: '/additional-brands', activeTabPaths: [ '/additional-brands/*' ] };
			expect( isItemActive( item, '/additional-brands/new' ) ).toBe( true );
		} );

		it( 'is inactive when nothing matches', () => {
			expect( isItemActive( { path: '/donations' }, '/segments' ) ).toBe( false );
			expect( isItemActive( {}, '/segments' ) ).toBe( false );
		} );
	} );
} );
