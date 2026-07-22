const path = require( 'path' );
require( '@wordpress/browserslist-config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// @wordpress packages that WP does NOT expose as runtime globals and that
// `@wordpress/admin-ui`/`@wordpress/ui` depend on, so they must be bundled.
const FORCE_BUNDLE = new Set( [ '@wordpress/theme', '@wordpress/style-runtime' ] );

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
