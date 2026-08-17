const path = require( 'path' );
require( '@wordpress/browserslist-config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// @wordpress packages that WordPress does not expose as runtime globals, so a
// bundle that externalizes them declares a script dependency no WordPress can
// satisfy. That failure is silent: `wp_enqueue_script()` drops the whole bundle,
// the stylesheet still loads, and the screen renders empty with nothing in the
// console. `ui` and `admin-ui` belong here alongside the two packages they
// depend on - core ships no `wp-ui` or `wp-admin-ui` handle in 7.0 or 7.1, and
// neither does the Gutenberg plugin.
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
