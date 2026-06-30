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
const webpack = require( 'webpack' );

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
	newsletterEditor: path.join( __dirname, 'src', 'newsletter-editor' ),
	blocks: path.join( __dirname, 'src', 'blocks' ),
	subscribeBlock: path.join( __dirname, 'src', 'blocks', 'subscribe', 'view.js' ),
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

// `newspack-components`' barrel re-exports `Wizard` from a module whose top-level
// `registerStore('newspack/wizards')` collides with newspack-plugin's wizards bundle in
// bundled mode. Newsletters doesn't use Wizard — stub the module so the side effect drops.
webpackConfig.plugins = webpackConfig.plugins || [];
webpackConfig.plugins.push(
	new webpack.NormalModuleReplacementPlugin(
		/[\\/]newspack-components[\\/]dist[\\/]esm[\\/]wizard[\\/]index\.js$/,
		path.resolve( __dirname, 'webpack-shims/newspack-components-wizard.js' )
	)
);

module.exports = webpackConfig;
