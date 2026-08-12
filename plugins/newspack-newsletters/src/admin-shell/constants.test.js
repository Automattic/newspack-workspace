/**
 * Internal dependencies
 */
import { EMPTY_STATE_CLASS, getEmptyStateHeading } from './constants';

describe( 'getEmptyStateHeading', () => {
	afterEach( () => {
		delete window.newspackNewslettersAdmin;
	} );

	it( 'is 1 when standalone, because the shell header holding the h1 is hidden', () => {
		expect( getEmptyStateHeading() ).toBe( 1 );
	} );

	it( 'is 2 when bundled, because Page renders the h1 outside the hidden subtree', () => {
		window.newspackNewslettersAdmin = { bundledMode: true };
		expect( getEmptyStateHeading() ).toBe( 2 );
	} );
} );

describe( 'every screen empty state carries the shell contract', () => {
	// Read the screen sources rather than rendering them: both values fail silently.
	// Without EMPTY_STATE_CLASS the `:has()` rules in style.scss stop matching, so the
	// shell header reappears and the region goes full-width; without the heading level
	// a standalone install has no h1. Neither throws, and no rendered test would notice.
	const fs = require( 'fs' );
	const path = require( 'path' );

	const screensDir = path.join( __dirname, 'screens' );
	const sources = fs
		.readdirSync( screensDir, { withFileTypes: true } )
		.filter( entry => entry.isDirectory() )
		.map( entry => [ entry.name, path.join( screensDir, entry.name, 'index.js' ) ] )
		.filter( ( [ , file ] ) => fs.existsSync( file ) )
		.map( ( [ name, file ] ) => [ name, fs.readFileSync( file, 'utf8' ) ] )
		.filter( ( [ , source ] ) => source.includes( 'EmptyState.Root' ) );

	// Anchored to a literal count so a new empty state has to come through here rather
	// than shrinking the denominator unnoticed.
	it( 'covers every screen that renders an empty state', () => {
		expect( sources.map( ( [ name ] ) => name ).sort() ).toEqual( [ 'ads-list', 'advertisers-list', 'newsletters-list' ] );
	} );

	it.each( sources )( '%s passes EMPTY_STATE_CLASS to EmptyState.Root', ( _name, source ) => {
		expect( source ).toMatch( /<EmptyState\.Root className=\{ EMPTY_STATE_CLASS \}>/ );
	} );

	it.each( sources )( '%s sets the heading level from the shell', ( _name, source ) => {
		expect( source ).toContain( 'heading={ getEmptyStateHeading() }' );
	} );

	it( 'keeps the class in sync with the stylesheet selectors', () => {
		const stylesheet = fs.readFileSync( path.join( __dirname, 'style.scss' ), 'utf8' );
		expect( stylesheet ).toContain( `:has(.${ EMPTY_STATE_CLASS })` );
	} );
} );
