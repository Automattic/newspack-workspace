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
const { SOURCE_FILE_REGEX } = require( 'newspack-scripts/config/resolveSource' );
const path = require( 'path' );

/**
 * Internal variables
 */
const editor = path.join( __dirname, 'src', 'editor' );
const assetsDir = path.join( __dirname, 'src', 'assets', 'front-end' );
const assets = fs
	.readdirSync( assetsDir )
	.filter( asset => SOURCE_FILE_REGEX.test( asset ) )
	.reduce(
		( acc, fileName ) => ( {
			...acc,
			[ fileName.replace( SOURCE_FILE_REGEX, '' ) ]: path.join( assetsDir, fileName ),
		} ),
		{}
	);

const entry = {
	editor,
	...assets,
};

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

module.exports = webpackConfig;
