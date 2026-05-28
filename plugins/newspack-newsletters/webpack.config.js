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
	admin: path.join( __dirname, 'src', 'admin' ),
	adsEditor: path.join( __dirname, 'src', 'ads', 'editor' ),
	newsletterAdsEditor: path.join( __dirname, 'src', 'ads', 'newsletter-editor' ),
	branding: path.join( __dirname, 'src', 'branding' ),
	quickEdit: path.join( __dirname, 'src', 'quick-edit' ),
	editorBlocks: path.join( __dirname, 'src', 'editor', 'blocks' ),
	newsletterEditor: path.join( __dirname, 'src', 'newsletter-editor' ),
	blocks: path.join( __dirname, 'src', 'blocks' ),
	subscribeBlock: path.join( __dirname, 'src', 'blocks', 'subscribe', 'view.js' ),
	subscriptions: path.join( __dirname, 'src', 'subscriptions' ),
};

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

module.exports = webpackConfig;
