/**
 **** WARNING: No ES6 modules here. Not transpiled! ****
 */
/* eslint-disable import/no-nodejs-modules */
/* eslint-disable @typescript-eslint/no-var-requires */

/**
 * External dependencies
 */
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const { resolveSourceFile } = require( 'newspack-scripts/config/resolveSource' );
const path = require( 'path' );

/**
 * Internal variables
 */

const entry = {
	editor: path.join( __dirname, 'src', 'editor' ),
	admin: path.join( __dirname, 'src', 'admin' ),
	'admin-shell': path.join( __dirname, 'src', 'admin-shell' ),
	'wizard-bridge': path.join( __dirname, 'src', 'wizard-bridge' ),
	adsEditor: path.join( __dirname, 'src', 'ads', 'editor' ),
	newsletterAdsEditor: path.join( __dirname, 'src', 'ads', 'newsletter-editor' ),
	branding: path.join( __dirname, 'src', 'branding' ),
	quickEdit: path.join( __dirname, 'src', 'quick-edit' ),
	editorBlocks: path.join( __dirname, 'src', 'editor', 'blocks' ),
	legacyBlockStyles: path.join( __dirname, 'src', 'editor', 'legacy-block-styles' ),
	newsletterEditor: path.join( __dirname, 'src', 'newsletter-editor' ),
	blocks: path.join( __dirname, 'src', 'blocks' ),
	subscribeBlock: resolveSourceFile( path.join( __dirname, 'src', 'blocks', 'subscribe', 'view' ) ),
	subscriptions: path.join( __dirname, 'src', 'subscriptions' ),
};

const webpackConfig = getBaseWebpackConfig( {
	entry,
} );

// `newspack-icons` ships raw JSX in `node_modules/newspack-icons/src/`. The
// default babel-loader rule from `@wordpress/scripts` excludes node_modules,
// so the package fails to parse. Carve out an exception. Detect the rule by
// presence of `babel-loader` in `rule.use` rather than matching `rule.test`
// verbatim — keeps working if upstream tweaks the test regex. Path separator
// uses `[\\/]` so the exclude works on Windows too.
webpackConfig.module.rules = webpackConfig.module.rules.map( rule => {
	const usesBabel =
		Array.isArray( rule.use ) &&
		rule.use.some(
			loader =>
				( typeof loader === 'string' && loader.includes( 'babel-loader' ) ) ||
				( loader && typeof loader === 'object' && typeof loader.loader === 'string' && loader.loader.includes( 'babel-loader' ) )
		);
	if ( usesBabel && rule.exclude ) {
		return { ...rule, exclude: /node_modules[\\/](?!newspack-icons[\\/])/ };
	}
	return rule;
} );

module.exports = webpackConfig;
