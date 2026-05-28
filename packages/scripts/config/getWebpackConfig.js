const path = require( 'path' );
require( '@wordpress/browserslist-config' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = ( ...args ) => {
	let config = { ...defaultConfig };

	// Merge config extensions into default config.
	args.forEach( ( extension ) => {
		config = { ...config, ...extension };
	} );

	// Ensure that webpack resolves modules from the Newspack Scripts node_modules as well as the root repo's node_modules.
	config.resolve.modules = [
		path.resolve( __dirname, '../node_modules' ),
		'node_modules',
	];

	// Clear cacheGroups so that CSS files don't get the `style-` prefix.
	if ( config?.optimization?.splitChunks?.cacheGroups?.style ) {
		delete config.optimization.splitChunks.cacheGroups.style;
	}

	return config;
};
