/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules */
/* eslint-disable @typescript-eslint/no-var-requires */

/**
 * External dependencies
 */
const path = require( 'path' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );

/**
 * Internal variables
 */
const editor = path.join( __dirname, 'src', 'editor' );

const webpackConfig = getBaseWebpackConfig( {
	entry: { editor },
} );

module.exports = webpackConfig;
