/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules, @typescript-eslint/no-var-requires */

/**
 * External dependencies
 */
const fs = require( 'fs' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const path = require( 'path' );
const { exec } = require( 'child_process' );

/**
 * Internal variables
 */
const frontEndDir = path.join( __dirname, 'src', 'js', 'front-end' );
const frontEnd = fs
	.readdirSync( frontEndDir )
	.filter( asset => /.(j|t)sx?$/.test( asset ) )
	.reduce(
		( acc, filename ) => ( {
			...acc,
			[ filename.replace( /\.[^/.]+$/, '' ) ]: path.join( __dirname, 'src', 'js', 'front-end', filename ),
		} ),
		{}
	);
const blocks = fs.readdirSync( path.join( __dirname, 'includes', 'blocks' ) ).reduce( ( acc, asset ) => {
	if ( fs.lstatSync( path.join( __dirname, 'includes', 'blocks', asset ) ).isDirectory() ) {
		fs.readdirSync( path.join( __dirname, 'includes', 'blocks', asset ) )
			.filter( file => /\.(j|t)sx?$/.test( file ) )
			.forEach( file => {
				const name = file.replace( /\.[^/.]+$/, '' );
				acc[ `${ asset }-${ name }` ] = path.join( __dirname, 'includes', 'blocks', asset, file );
			} );
	}
	return acc;
}, {} );

const editor = path.join( __dirname, 'src', 'js', 'editor' );

const style = [ path.join( __dirname, 'src', 'scss' ) ];

const webpackConfig = getBaseWebpackConfig( {
	entry: { editor, ...frontEnd, ...blocks, style },
	output: {
		path: path.join( __dirname, 'dist' ),
	},
} );

// Copy built CSS files to the root of the theme. See: https://stackoverflow.com/questions/30312715/run-command-after-webpack-build
webpackConfig.plugins.push( {
	apply: compiler => {
		compiler.hooks.afterEmit.tap( 'AfterEmitPlugin', () => {
			exec( 'cp ./dist/*.css ./', ( err, stdout, stderr ) => {
				if ( err ) {
					process.stderr.write( err );
				}
				if ( stdout ) {
					process.stdout.write( stdout );
				}
				if ( stderr ) {
					process.stderr.write( stderr );
				}
			} );
		} );
	},
} );
module.exports = webpackConfig;
