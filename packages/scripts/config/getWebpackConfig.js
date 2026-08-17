const path = require( 'path' );
require( '@wordpress/browserslist-config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// @wordpress packages to bundle rather than externalize to a `wp-*` script
// handle. Core registers no `wp-ui`, `wp-admin-ui` or `wp-style-runtime`
// handle (checked on 7.0 and 7.1, with and without the Gutenberg plugin), so
// externalizing them puts a dependency on the bundle nothing can satisfy - and
// that failure is silent: `wp_enqueue_script()` drops the whole bundle, the
// stylesheet still loads, and the screen renders empty with nothing in the
// console. Newer @wordpress/dependency-extraction-webpack-plugin releases already
// bundle `ui` and `admin-ui` internally; listing them here keeps that behaviour
// stable for a consumer whose lockfile resolves an older release. `theme` is
// kept for the same reason even though core does register `wp-theme`.
const FORCE_BUNDLE = new Set( [
	'@wordpress/ui',
	'@wordpress/admin-ui',
	'@wordpress/theme',
	'@wordpress/style-runtime',
] );

// `newspack-icons` publishes raw JSX under `src/` with no compile step, so every
// consumer has to transpile it. @wordpress/scripts' babel-loader excludes
// node_modules wholesale, which is why each consumer grew its own carve-out.
// Centralised here so they don't have to, and matched by pattern rather than by
// resolved path: npm nests a second copy under `newspack-components` whenever the
// pinned versions differ, and a path-prefix `include` silently misses it.
const ICONS_MODULE = /node_modules[\\/]newspack-icons[\\/]/;

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

	// Transpile newspack-icons wherever it resolves, including nested copies.
	// Rebuilt rather than pushed: `config` is a shallow copy of the module-level
	// defaultConfig, so mutating its rules array would stack a duplicate rule on
	// every call in the same process.
	config.module = {
		...config.module,
		rules: [
			...config.module.rules,
			{
				test: /\.jsx?$/,
				include: ICONS_MODULE,
				use: {
					loader: 'babel-loader',
					options: {
						presets: [
							'@babel/preset-env',
							[ '@babel/preset-react', { runtime: 'automatic' } ],
						],
					},
				},
			},
		],
	};

	return config;
};
