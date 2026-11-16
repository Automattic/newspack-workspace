#!/usr/bin/env node
const fs = require( 'fs' );
const path = require( 'path' );
const { loadConfig } = require( '../src/config' );

process.env.NODE_ENV ??= 'production';

const scriptsDir = path.dirname( require.resolve( 'newspack-scripts/package.json' ) );
const wpScripts = path.dirname( require.resolve( '@wordpress/scripts/package.json', { paths: [ scriptsDir ] } ) );

const escapeHtml = value => String( value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

const args = process.argv.slice( 2 );
const outIndex = args.indexOf( '--out' );
const out = outIndex === -1 ? null : args[ outIndex + 1 ];
const dir = args.find( ( arg, i ) => ! arg.startsWith( '--' ) && i !== outIndex + 1 );

const fail = message => {
	console.error( message );
	process.exit( 1 );
};

if ( ! dir || ! out ) {
	fail( 'Usage: build.js <prototype-dir> --out <dir>' );
}

let config;
try {
	config = loadConfig( dir );
} catch ( error ) {
	fail( error.message );
}

const outDir = path.resolve( out );
fs.mkdirSync( outDir, { recursive: true } );

process.chdir( path.resolve( __dirname, '../../..' ) );
const webpack = require( require.resolve( 'webpack', { paths: [ wpScripts ] } ) );
const createWebpackConfig = require( '../src/webpack.config' );

webpack( createWebpackConfig( { entry: config.entry, outDir, aliases: config.aliases } ), ( error, stats ) => {
	if ( error ) {
		fail( error.stack || error.message );
	}
	if ( stats.hasErrors() ) {
		fail( stats.toString( { all: false, errors: true } ) );
	}
	if ( stats.hasWarnings() ) {
		console.warn( stats.toString( { all: false, warnings: true } ) );
	}
	const template = fs.readFileSync( path.join( __dirname, '../src/template.html' ), 'utf8' );
	fs.writeFileSync( path.join( outDir, 'index.html' ), template.replace( '{{title}}', () => escapeHtml( config.title || 'Playground' ) ) );
	console.log( `playground: built ${ outDir }` );
} );
