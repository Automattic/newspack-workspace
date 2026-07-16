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
const wizardsDir = path.join( __dirname, 'src', 'wizards' );

// Get files for wizards scripts.
const wizardsScripts = fs
	.readdirSync( wizardsDir )
	.filter(
		wizard =>
			fs.existsSync( path.join( __dirname, 'src', 'wizards', wizard, 'index.js' ) ) ||
			fs.existsSync( path.join( __dirname, 'src', 'wizards', wizard, 'index.tsx' ) )
	);
const wizardsScriptFiles = {
	'plugins-screen': path.join( __dirname, 'src', 'plugins-screen', 'plugins-screen.js' ),
};
wizardsScripts.forEach( function ( wizard ) {
	let wizardFileName = wizard;
	if ( wizard === 'advertising' ) {
		// "advertising.js" might be blocked by ad-blocking extensions.
		wizardFileName = 'billboard';
	}
	wizardsScriptFiles[ wizardFileName ] = path.join(
		__dirname,
		'src',
		'wizards',
		wizard,
		fs.existsSync( path.join( __dirname, 'src', 'wizards', wizard, 'index.tsx' ) ) ? 'index.tsx' : 'index.js'
	);
} );

const entry = {
	'reader-activation': path.join( __dirname, 'src', 'reader-activation', 'index.js' ),
	'reader-auth': path.join( __dirname, 'src', 'reader-activation-auth', 'index.js' ),
	'newsletters-signup': path.join( __dirname, 'src', 'reader-activation-newsletters', 'index.js' ),
	'reader-registration-block': path.join( __dirname, 'src', 'blocks', 'reader-registration', 'view.js' ),
	'correction-box-block': path.join( __dirname, 'src', 'blocks', 'correction-box', 'index.js' ),
	'correction-item-block': path.join( __dirname, 'src', 'blocks', 'correction-item', 'index.js' ),
	'content-gate-countdown-block': path.join( __dirname, 'src', 'blocks', 'content-gate', 'countdown', 'view.js' ),
	'overlay-menu-block': path.join( __dirname, 'src', 'blocks', 'overlay-menu', 'view.js' ),
	'overlay-search-block': path.join( __dirname, 'src', 'blocks', 'overlay-search', 'view.js' ),
	'content-gate-countdown-box-block': path.join( __dirname, 'src', 'blocks', 'content-gate', 'countdown-box', 'index.js' ),
	'contribution-meter-block': path.join( __dirname, 'src', 'blocks', 'contribution-meter', 'index.js' ),
	'avatar-block': path.join( __dirname, 'src', 'blocks', 'avatar', 'index.js' ),
	'my-account': path.join( __dirname, 'src', 'my-account', 'index.js' ),
	'my-account-v0': path.join( __dirname, 'src', 'my-account', 'v0', 'index.js' ),
	'my-account-v1': path.join( __dirname, 'src', 'my-account', 'v1', 'index.js' ),
	'account-frontend': path.join( __dirname, 'src', 'my-account', 'v1', 'frontend.js' ),
	admin: path.join( __dirname, 'src', 'admin', 'index.js' ),
	'content-gate': path.join( __dirname, 'src', 'content-gate', 'gate.js' ),
	'content-gate-metering': path.join( __dirname, 'src', 'content-gate', 'metering.js' ),
	'indesign-export': path.join( __dirname, 'src', 'indesign-export', 'index.js' ),

	// Newspack wizard assets.
	...wizardsScriptFiles,
	blocks: path.join( __dirname, 'src', 'blocks', 'index.js' ),
	'content-gate-editor': path.join( __dirname, 'src', 'content-gate', 'editor', 'editor.js' ),
	'content-gate-editor-memberships': path.join( __dirname, 'src', 'content-gate', 'editor', 'memberships.js' ),
	'content-gate-editor-metering': path.join( __dirname, 'src', 'content-gate', 'editor', 'metering-settings.js' ),
	'content-gate-block-patterns': path.join( __dirname, 'src', 'content-gate', 'editor', 'block-patterns.js' ),
	'content-gate-block-visibility': path.join( __dirname, 'src', 'content-gate', 'editor', 'block-visibility.tsx' ),
	'content-gate-post-settings': path.join( __dirname, 'src', 'content-gate', 'editor', 'post-settings.js' ),
	'content-banner': path.join( __dirname, 'src', 'content-gate', 'content-banner.js' ),
	wizards: path.join( __dirname, 'src', 'wizards', 'index.tsx' ),
	'newspack-ui': path.join( __dirname, 'src', 'newspack-ui', 'index.js' ),
	bylines: path.join( __dirname, 'src', 'bylines', 'index.js' ),
	'nicename-change': path.join( __dirname, 'src', 'nicename-change', 'index.js' ),
	'collections-admin': path.join( __dirname, 'src', 'collections', 'admin', 'index.js' ),
	'collections-frontend': path.join( __dirname, 'src', 'collections', 'frontend', 'index.js' ),
	'group-subscription-admin': path.join( __dirname, 'src', 'group-subscription', 'admin.js' ),
};

// Get files for other scripts.
const otherScripts = fs
	.readdirSync( path.join( __dirname, 'src', 'other-scripts' ) )
	.filter( script => fs.existsSync( path.join( __dirname, 'src', 'other-scripts', script, 'index.js' ) ) );
otherScripts.forEach( function ( script ) {
	entry[ `other-scripts/${ script }` ] = path.join( __dirname, 'src', 'other-scripts', script, 'index.js' );
} );

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

webpackConfig.output.chunkFilename = '[name].[contenthash].js';

// Content-hash async CSS chunks too. The line above only governs JS chunks; the
// async CSS chunks emitted by mini-css-extract-plugin (e.g. audience-wizards.css)
// otherwise keep a bare, unversioned name and get served stale by CDNs/proxies
// after a deploy (they're loaded by webpack's runtime, not wp_enqueue_style, so
// they never pick up a ?ver). Hashing matches the JS chunks' cache-busting.
const miniCssExtractPlugin = webpackConfig.plugins.find(
	plugin => plugin.constructor && plugin.constructor.name === 'MiniCssExtractPlugin'
);
if ( miniCssExtractPlugin && miniCssExtractPlugin.options ) {
	miniCssExtractPlugin.options.chunkFilename = '[name].[contenthash].css';
}

// Overwrite default optimisation.
webpackConfig.optimization.splitChunks.cacheGroups.commons = {
	name: 'commons',
	chunks: 'initial',
	minChunks: 2,
};

// Fonts handling.
webpackConfig.module.rules.push( {
	test: /\.(woff|woff2|eot|ttf|otf)$/i,
	type: 'asset/resource',
} );

module.exports = webpackConfig;
