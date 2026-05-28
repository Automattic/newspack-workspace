/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules */
/* eslint-disable @typescript-eslint/no-var-requires */

const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const path = require( 'path' );

module.exports = getBaseWebpackConfig( {
	entry: {
		'story-budget-data': path.join( __dirname, 'src', 'store' ),
		'story-budget-app': path.join( __dirname, 'src', 'app' ),
		'story-budget-editor': path.join( __dirname, 'src', 'editor' ),
		'story-budget-quick-edit': path.join( __dirname, 'src', 'quick-edit' ),
	},
} );
