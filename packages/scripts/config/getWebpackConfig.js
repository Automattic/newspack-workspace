const path = require( 'path' );
require( '@wordpress/browserslist-config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Bundled rather than externalized: core registers no script handle for
// `style-runtime`, `react-inert-value` or `kebab-case`, and its `wp-theme` is
// not the version the bundled `@wordpress/ui` expects.
const FORCE_BUNDLE = new Set( [ '@wordpress/theme', '@wordpress/style-runtime', '@wordpress/react-inert-value', '@wordpress/kebab-case' ] );

module.exports = ( ...args ) => {
	let config = { ...defaultConfig };

	// Merge config extensions into default config.
	args.forEach( extension => {
		config = { ...config, ...extension };
	} );

	// Ensure that webpack resolves modules from the Newspack Scripts node_modules as well as the root repo's node_modules.
	config.resolve.modules = [ path.resolve( __dirname, '../node_modules' ), 'node_modules' ];

	// Clear cacheGroups so that CSS files don't get the `style-` prefix.
	if ( config?.optimization?.splitChunks?.cacheGroups?.style ) {
		delete config.optimization.splitChunks.cacheGroups.style;
	}

	// Returning a non-undefined falsey value (null) skips the default
	// externalization; returning undefined cascades to the default behavior.
	config.plugins = config.plugins
		.filter( plugin => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' )
		.concat(
			new DependencyExtractionWebpackPlugin( {
				requestToExternal( request ) {
					if ( FORCE_BUNDLE.has( request ) ) {
						return null;
					}
					return undefined;
				},
			} )
		);

	return config;
};
