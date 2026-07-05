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

const { SOURCE_FILE_REGEX, findSourceFile } = require( 'newspack-scripts/config/resolveSource' );
const srcDir = path.join( __dirname, 'newspack-theme/js', 'src' );

// Add all js/src/*.{js,jsx,ts,tsx} scripts.
const entry = fs
	.readdirSync( srcDir )
	.filter( script => SOURCE_FILE_REGEX.test( script ) )
	.reduce(
		( obj, item ) => ( {
			...obj,
			[ item.replace( SOURCE_FILE_REGEX, '' ) ]: path.join( srcDir, item ),
		} ),
		{}
	);

// Add all js/src/*/index.{js,jsx,ts,tsx} scripts.
fs.readdirSync( srcDir ).forEach( function ( script ) {
	const index = findSourceFile( path.join( srcDir, script, 'index' ) );
	if ( index ) {
		entry[ script ] = index;
	}
} );

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

module.exports = webpackConfig;
