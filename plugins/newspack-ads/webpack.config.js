/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules */
/* eslint-disable @typescript-eslint/no-var-requires */

/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const { findSourceFile, resolveSourceFile } = require( 'newspack-scripts/config/resolveSource' );
const loader = require.resolve( 'babel-loader' );

/**
 * Internal variables
 */
const editorSetup = path.join( __dirname, 'src', 'setup', 'editor' );
const viewSetup = path.join( __dirname, 'src', 'setup', 'view' );

function blockScripts( type, inputDir, blocks ) {
	return blocks.map( block => findSourceFile( path.join( inputDir, 'blocks', block, type ) ) ).filter( Boolean );
}

const blocksDir = path.join( __dirname, 'src', 'blocks' );
const blocks = fs.readdirSync( blocksDir ).filter( block => findSourceFile( path.join( blocksDir, block, 'editor' ) ) );

// Helps split up each block into its own folder view script
const viewBlocksScripts = blocks.reduce( ( viewBlocks, block ) => {
	const viewScriptPath = findSourceFile( path.join( blocksDir, block, 'view' ) );
	if ( viewScriptPath ) {
		viewBlocks[ block + '/view' ] = [ ...viewSetup, ...[ viewScriptPath ] ];
	}
	return viewBlocks;
}, {} );

const entry = {
	'suppress-ads': path.join( __dirname, 'src', 'suppress-ads' ),
	frontend: path.join( __dirname, 'src', 'frontend' ),
	'customizer-preview': path.join( __dirname, 'src', 'customizer', 'preview' ),
	'customizer-control': path.join( __dirname, 'src', 'customizer', 'control' ),
	'header-bidding-gam': path.join( __dirname, 'src', 'wizard-settings', 'header-bidding-gam' ),
	prebid: path.join( __dirname, 'src', 'prebid' ),
	// Media Kit Page.
	'media-kit-frontend': resolveSourceFile( path.join( __dirname, 'src', 'media-kit', 'index' ) ),
};

const webpackConfig = getBaseWebpackConfig( {
	entry: {
		// Combines all the different blocks into one editor.js script
		editor: [ editorSetup, ...blockScripts( 'editor', path.join( __dirname, 'src' ), blocks ) ],
		...entry,
		...viewBlocksScripts,
	},
} );

/**
 * Custom babel config for Prebid.js.
 * https://github.com/prebid/Prebid.js/blob/6.12.0/README.md#usage-as-a-npm-dependency.
 */
webpackConfig.module.rules.push( {
	test: /.js$/,
	include: new RegExp( `\\${ path.sep }prebid\\.js` ),
	use: {
		loader,
		options: {
			...require( 'prebid.js/.babelrc.js' ),
			configFile: false,
		},
	},
} );

module.exports = webpackConfig;
