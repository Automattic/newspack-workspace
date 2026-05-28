/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules */
/* eslint-disable @typescript-eslint/no-var-requires */

/**
 * External dependencies
 */
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const path = require( 'path' );

/**
 * Internal variables
 */
const entry = {
	admin: path.join( __dirname, 'src/admin' ),
	postPrimaryBrand: path.join( __dirname, 'src/post-primary-brand' ),
	promptBrands: path.join( __dirname, 'src/prompt-brands' ),
};

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

module.exports = webpackConfig;
