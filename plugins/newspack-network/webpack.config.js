/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules */
/* eslint-disable @typescript-eslint/no-var-requires */

const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const path = require( 'path' );

module.exports = getBaseWebpackConfig( {
	entry: {
		'distribute-panel': path.join(
			__dirname,
			'src',
			'content-distribution',
			'content-distribution-panel'
		),
		'outgoing-post': path.join(
			__dirname,
			'src',
			'content-distribution',
			'outgoing-post'
		),
		'incoming-post': path.join(
			__dirname,
			'src',
			'content-distribution',
			'incoming-post'
		),
		'story-budget': path.join( __dirname, 'src', 'story-budget', 'index' ),
	},
} );
