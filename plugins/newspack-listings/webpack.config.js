/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules */
/* eslint-disable @typescript-eslint/no-var-requires */

/**
 * External dependencies
 */
const fs = require( 'fs' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const {
	SOURCE_FILE_REGEX,
	findSourceFile,
} = require( 'newspack-scripts/config/resolveSource' );
const path = require( 'path' );

/**
 * Internal variables
 */
const editor = path.join( __dirname, 'src', 'editor' );
const assetsDir = path.join( __dirname, 'src', 'assets', 'front-end' );
// Basenames are deduped and re-resolved via findSourceFile (TS-first) so a .ts twin
// deterministically wins over a leftover .js one, rather than whichever readdirSync
// happens to return last for that basename.
const assetBasenames = [
	...new Set(
		fs
			.readdirSync( assetsDir )
			.filter( ( asset ) => SOURCE_FILE_REGEX.test( asset ) )
			.map( ( asset ) => asset.replace( SOURCE_FILE_REGEX, '' ) )
	),
];
const assets = assetBasenames.reduce( ( acc, basename ) => {
	const source = findSourceFile( path.join( assetsDir, basename ) );
	if ( source ) {
		acc[ basename ] = source;
	}
	return acc;
}, {} );

const entry = {
	editor,
	...assets,
};

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

module.exports = webpackConfig;
