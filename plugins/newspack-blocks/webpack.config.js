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
const path = require( 'path' );
const isDevelopment = process.env.NODE_ENV !== 'production';
const blockListFile = process.env.npm_config_block_list || 'block-list.json';
const blockList = JSON.parse( fs.readFileSync( blockListFile ) );

/**
 * Internal variables
 */
const editorSetup = path.join( __dirname, 'src', 'setup', 'editor' );

function blockScripts( type, inputDir, blocks ) {
	return blocks.map( block => path.join( inputDir, 'blocks', block, `${ type }.js` ) ).filter( fs.existsSync );
}

const blocksDir = path.join( __dirname, 'src', 'blocks' );
const blocks = fs
	.readdirSync( blocksDir )
	.filter( block => isDevelopment || blockList.production.includes( block ) )
	.filter( block => fs.existsSync( path.join( __dirname, 'src', 'blocks', block, 'editor.js' ) ) );

// Helps split up each block into its own folder view script
const viewBlocksScripts = blocks.reduce( ( viewBlocks, block ) => {
	const pathToBlock = [ __dirname, 'src', 'blocks', block ];
	let viewScriptPath = path.join( ...pathToBlock, 'view.js' );
	let fileExists = fs.existsSync( viewScriptPath );
	if ( ! fileExists ) {
		// Try TS.
		viewScriptPath = path.join( ...pathToBlock, 'view.ts' );
		fileExists = fs.existsSync( viewScriptPath );
	}
	if ( fileExists ) {
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
	modal: path.join( __dirname, 'src/modal-checkout/modal.js' ),
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
