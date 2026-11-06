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
	editor: path.join( __dirname, 'src', 'editor' ),
	view: path.join( __dirname, 'src', 'view' ),
	admin: path.join( __dirname, 'src', 'view', 'admin' ),
	documentSettings: path.join( __dirname, 'src', 'document-settings' ),
	settings: path.join( __dirname, 'src', 'settings' ),
	blocks: path.join( __dirname, 'src', 'blocks' ),
	criteria: path.join( __dirname, 'src', 'criteria' ),
};

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

module.exports = webpackConfig;
