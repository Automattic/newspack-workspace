const path = require( 'path' );

const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );

const repo = path.resolve( __dirname, '../../..' );

const packageDir = path.resolve( __dirname, '..' );
const packageRoot = name => path.dirname( require.resolve( `${ name }/package.json`, { paths: [ packageDir ] } ) );

const scriptsModules = path.resolve( packageRoot( 'newspack-scripts' ), 'node_modules' );

const REMOVED_PLUGINS = [ 'DependencyExtractionWebpackPlugin', 'RtlCssPlugin', 'CopyPlugin', 'PhpFilePathsPlugin' ];

module.exports = ( { entry, outDir, aliases = {} } ) => {
	const wpComponents = packageRoot( '@wordpress/components' );
	const wpDataviews = packageRoot( '@wordpress/dataviews' );
	const pluginDir = path.join( repo, 'plugins/newspack-plugin' );
	const base = getBaseWebpackConfig( { entry: { screen: entry } } );

	return {
		...base,
		mode: 'production',
		devtool: false,
		output: { path: outDir, filename: '[name].js' },
		plugins: base.plugins.filter( plugin => ! REMOVED_PLUGINS.includes( plugin.constructor.name ) ),
		module: {
			...base.module,
			parser: { ...base.module?.parser, javascript: { ...base.module?.parser?.javascript, exportsPresence: 'error' } },
		},
		optimization: { ...base.optimization, splitChunks: false, runtimeChunk: false },
		resolve: {
			...base.resolve,
			alias: {
				...( base.resolve.alias || {} ),
				'@np': path.join( repo, 'packages/components/src' ),
				'@plugin': path.join( pluginDir, 'src' ),
				'newspack-playground/bridge$': path.join( __dirname, 'bridge.js' ),
				'@wordpress/route$': path.join( __dirname, 'route-stub.js' ),
				'@wordpress/components$': wpComponents,
				'@wordpress/components/build-style': path.join( wpComponents, 'build-style' ),
				'@wordpress/dataviews$': wpDataviews,
				'@wordpress/dataviews/build-style': path.join( wpDataviews, 'build-style' ),
				...aliases,
			},
			modules: [ 'node_modules', path.join( packageDir, 'node_modules' ), path.join( pluginDir, 'node_modules' ), path.join( repo, 'node_modules' ) ],
		},
		resolveLoader: {
			modules: [ path.join( pluginDir, 'node_modules' ), scriptsModules, path.join( repo, 'node_modules' ) ],
		},
		performance: { hints: false },
	};
};
