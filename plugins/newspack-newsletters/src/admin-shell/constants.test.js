/**
 * Internal dependencies
 */
import { EMPTY_STATE_CLASS, getEmptyStateHeading } from './constants';

describe( 'getEmptyStateHeading', () => {
	afterEach( () => {
		delete window.newspackNewslettersAdmin;
	} );

	// `wp_localize_script()` string-casts, so the wire values are `''` and `'1'`. Pinning
	// booleans would keep this green while a tightening to `=== true` broke production.
	it( 'is 1 when standalone, because the shell header holding the h1 is hidden', () => {
		window.newspackNewslettersAdmin = { bundledMode: '' };
		expect( getEmptyStateHeading() ).toBe( 1 );
	} );

	it( 'is 1 when the global is absent, as outside wp-admin', () => {
		expect( getEmptyStateHeading() ).toBe( 1 );
	} );

	it( 'is 2 when bundled, because Page renders the h1 outside the hidden subtree', () => {
		window.newspackNewslettersAdmin = { bundledMode: '1' };
		expect( getEmptyStateHeading() ).toBe( 2 );
	} );
} );

describe( 'every screen empty state carries the shell contract', () => {
	// Read the sources rather than render them: both values fail silently. Losing the
	// class un-hides the shell header and drops the width cap; losing the heading level
	// leaves a standalone install with no h1. Neither throws.
	const fs = require( 'fs' );
	const path = require( 'path' );

	const screensDir = path.join( __dirname, 'screens' );

	// Every .js in the directory, not just index.js: these screens already split fields
	// and actions into siblings, so an extracted empty state would slip past.
	const readScreen = name => {
		const dir = path.join( screensDir, name );
		return fs
			.readdirSync( dir )
			.filter( file => file.endsWith( '.js' ) && ! file.endsWith( '.test.js' ) )
			.map( file => fs.readFileSync( path.join( dir, file ), 'utf8' ) )
			.join( '\n' );
	};

	const sources = fs
		.readdirSync( screensDir, { withFileTypes: true } )
		.filter( entry => entry.isDirectory() )
		.map( entry => [ entry.name, readScreen( entry.name ) ] )
		.filter( ( [ , source ] ) => source.includes( 'EmptyState.Root' ) );

	// Anchored to a literal list so a new empty state cannot shrink the denominator.
	it( 'covers every screen that renders an empty state', () => {
		expect( sources.map( ( [ name ] ) => name ).sort() ).toEqual( [ 'ads-list', 'advertisers-list', 'newsletters-list' ] );
	} );

	// Prop order and line breaks are not pinned: a second prop on Root is not a failure.
	it.each( sources )( '%s passes EMPTY_STATE_CLASS to EmptyState.Root', ( _name, source ) => {
		expect( source ).toMatch( /<EmptyState\.Root[^>]*\bclassName=\{ EMPTY_STATE_CLASS \}/ );
	} );

	it.each( sources )( '%s sets the heading level from the shell', ( _name, source ) => {
		expect( source ).toContain( 'heading={ getEmptyStateHeading() }' );
	} );

	it( 'keeps the class in sync with the stylesheet selectors', () => {
		const stylesheet = fs.readFileSync( path.join( __dirname, 'style.scss' ), 'utf8' );
		expect( stylesheet ).toContain( `:has(.${ EMPTY_STATE_CLASS })` );
	} );
} );
