/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable @typescript-eslint/no-var-requires */

const path = require( 'path' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );

const srcDir = path.join( __dirname, 'src' );

const webpackConfig = getBaseWebpackConfig( {
	entry: {
		// Block category setup and both blocks' editor scripts in one bundle.
		editor: [
			path.join( srcDir, 'setup', 'editor' ),
			path.join( srcDir, 'blocks', 'homepage-articles', 'editor' ),
			path.join( srcDir, 'blocks', 'carousel', 'editor' ),
		],
		'homepage-articles/view': path.join( srcDir, 'blocks', 'homepage-articles', 'view' ),
		'carousel/view': path.join( srcDir, 'blocks', 'carousel', 'view' ),
	},
} );

// Add rule to handle JSX files from newspack-icons.
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
