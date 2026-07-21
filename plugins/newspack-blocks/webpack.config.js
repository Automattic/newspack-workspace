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
const { findSourceFile, resolveSourceFile } = require( 'newspack-scripts/config/resolveSource' );
const path = require( 'path' );
const isDevelopment = process.env.NODE_ENV !== 'production';
const blockListFile = process.env.npm_config_block_list || 'block-list.json';
const blockList = JSON.parse( fs.readFileSync( blockListFile ) );

/**
 * Internal variables
 */
const editorSetup = path.join( __dirname, 'src', 'setup', 'editor' );

function blockScripts( type, inputDir, blocks ) {
	return blocks.map( block => findSourceFile( path.join( inputDir, 'blocks', block, type ) ) ).filter( Boolean );
}

const blocksDir = path.join( __dirname, 'src', 'blocks' );
const blocks = fs
	.readdirSync( blocksDir )
	.filter( block => isDevelopment || blockList.production.includes( block ) )
	.filter( block => findSourceFile( path.join( blocksDir, block, 'editor' ) ) );

// Helps split up each block into its own folder view script
const viewBlocksScripts = blocks.reduce( ( viewBlocks, block ) => {
	const viewScriptPath = findSourceFile( path.join( blocksDir, block, 'view' ) );
	if ( viewScriptPath ) {
		viewBlocks[ block + '/view' ] = viewScriptPath;
	}
	return viewBlocks;
}, {} );

// Combines all the different blocks into one editor.js script
const editorScript = [ editorSetup, ...blockScripts( 'editor', path.join( __dirname, 'src' ), blocks ) ];

const placeholderBlocksScript = path.join( __dirname, 'src', 'setup', 'placeholder-blocks' );

const blockStylesScript = [ path.join( __dirname, 'src', 'block-styles', 'view' ) ];

const entry = {
	placeholder_blocks: placeholderBlocksScript,
	editor: editorScript,
	block_styles: blockStylesScript,
	modal: resolveSourceFile( path.join( __dirname, 'src/modal-checkout/modal' ) ),
	modalCheckout: path.join( __dirname, 'src/modal-checkout' ),
	frequencyBased: path.join( __dirname, 'src/blocks/donate/frequency-based' ),
	tiersBased: path.join( __dirname, 'src/blocks/donate/tiers-based' ),
	...viewBlocksScripts,
};

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

// Add rule to handle JSX files from newspack-icons
webpackConfig.module.rules.push( {
	test: /\.jsx?$/,
	include: [ path.resolve( __dirname, 'node_modules/newspack-icons' ) ],
	use: {
		loader: 'babel-loader',
		options: {
			presets: [ '@babel/preset-env', [ '@babel/preset-react', { runtime: 'automatic' } ] ],
		},
	},
} );

module.exports = webpackConfig;
