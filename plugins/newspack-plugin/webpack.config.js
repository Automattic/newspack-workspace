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
const { findSourceFile, resolveSourceFile } = require( 'newspack-scripts/config/resolveSource' );
const wizardsDir = path.join( __dirname, 'src', 'wizards' );

// Entry sources are declared extensionless; the file on disk may be TS or JS.
const findSource = ( ...segments ) => findSourceFile( path.join( __dirname, ...segments ) );
const resolveSource = ( ...segments ) => resolveSourceFile( path.join( __dirname, ...segments ) );

// Shared modules living under src/wizards that are not wizard app entries.
const NON_WIZARD_DIRS = [ 'errors', 'types' ];

// Get files for wizards scripts.
const wizardsScripts = fs
	.readdirSync( wizardsDir )
	.filter( wizard => ! NON_WIZARD_DIRS.includes( wizard ) && findSource( 'src', 'wizards', wizard, 'index' ) );
const wizardsScriptFiles = {
	'plugins-screen': resolveSource( 'src', 'plugins-screen', 'plugins-screen' ),
};
wizardsScripts.forEach( function ( wizard ) {
	let wizardFileName = wizard;
	if ( wizard === 'advertising' ) {
		// "advertising.js" might be blocked by ad-blocking extensions.
		wizardFileName = 'billboard';
	}
	wizardsScriptFiles[ wizardFileName ] = resolveSource( 'src', 'wizards', wizard, 'index' );
} );

const entry = {
	'reader-activation': resolveSource( 'src', 'reader-activation', 'index' ),
	'reader-auth': resolveSource( 'src', 'reader-activation-auth', 'index' ),
	'newsletters-signup': resolveSource( 'src', 'reader-activation-newsletters', 'index' ),
	'reader-registration-block': resolveSource( 'src', 'blocks', 'reader-registration', 'view' ),
	'correction-box-block': resolveSource( 'src', 'blocks', 'correction-box', 'index' ),
	'correction-item-block': resolveSource( 'src', 'blocks', 'correction-item', 'index' ),
	'content-gate-countdown-block': resolveSource( 'src', 'blocks', 'content-gate', 'countdown', 'view' ),
	'overlay-menu-block': resolveSource( 'src', 'blocks', 'overlay-menu', 'view' ),
	'overlay-search-block': resolveSource( 'src', 'blocks', 'overlay-search', 'view' ),
	'content-gate-countdown-box-block': resolveSource( 'src', 'blocks', 'content-gate', 'countdown-box', 'index' ),
	'contribution-meter-block': resolveSource( 'src', 'blocks', 'contribution-meter', 'index' ),
	'avatar-block': resolveSource( 'src', 'blocks', 'avatar', 'index' ),
	'my-account': resolveSource( 'src', 'my-account', 'index' ),
	'my-account-v0': resolveSource( 'src', 'my-account', 'v0', 'index' ),
	'my-account-v1': resolveSource( 'src', 'my-account', 'v1', 'index' ),
	'account-frontend': resolveSource( 'src', 'my-account', 'v1', 'frontend' ),
	admin: resolveSource( 'src', 'admin', 'index' ),
	'content-gate': resolveSource( 'src', 'content-gate', 'gate' ),
	'content-gate-metering': resolveSource( 'src', 'content-gate', 'metering' ),
	'indesign-export': resolveSource( 'src', 'indesign-export', 'index' ),

	// Newspack wizard assets.
	...wizardsScriptFiles,
	blocks: resolveSource( 'src', 'blocks', 'index' ),
	'content-gate-editor': resolveSource( 'src', 'content-gate', 'editor', 'editor' ),
	'content-gate-editor-memberships': resolveSource( 'src', 'content-gate', 'editor', 'memberships' ),
	'content-gate-editor-metering': resolveSource( 'src', 'content-gate', 'editor', 'metering-settings' ),
	'content-gate-block-patterns': resolveSource( 'src', 'content-gate', 'editor', 'block-patterns' ),
	'content-gate-block-visibility': resolveSource( 'src', 'content-gate', 'editor', 'block-visibility' ),
	'content-gate-post-settings': resolveSource( 'src', 'content-gate', 'editor', 'post-settings' ),
	'content-banner': resolveSource( 'src', 'content-gate', 'content-banner' ),
	wizards: resolveSource( 'src', 'wizards', 'index' ),
	'newspack-ui': resolveSource( 'src', 'newspack-ui', 'index' ),
	bylines: resolveSource( 'src', 'bylines', 'index' ),
	'nicename-change': resolveSource( 'src', 'nicename-change', 'index' ),
	'collections-admin': resolveSource( 'src', 'collections', 'admin', 'index' ),
	'collections-frontend': resolveSource( 'src', 'collections', 'frontend', 'index' ),
	'group-subscription-admin': resolveSource( 'src', 'group-subscription', 'admin' ),
};

// Get files for other scripts.
const otherScripts = fs
	.readdirSync( path.join( __dirname, 'src', 'other-scripts' ) )
	.filter( script => findSource( 'src', 'other-scripts', script, 'index' ) );
otherScripts.forEach( function ( script ) {
	entry[ `other-scripts/${ script }` ] = resolveSource( 'src', 'other-scripts', script, 'index' );
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
const miniCssExtractPlugin = webpackConfig.plugins.find( plugin => plugin.constructor && plugin.constructor.name === 'MiniCssExtractPlugin' );
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
