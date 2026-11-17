const path = require( 'path' );

const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );

const repo = path.resolve( __dirname, '../../..' );

const packageDir = path.resolve( __dirname, '..' );
const packageRoot = name => path.dirname( require.resolve( `${ name }/package.json`, { paths: [ packageDir ] } ) );

const scriptsModules = path.resolve( packageRoot( 'newspack-scripts' ), 'node_modules' );

const REMOVED_PLUGINS = [ 'DependencyExtractionWebpackPlugin', 'RtlCssPlugin', 'CopyPlugin', 'PhpFilePathsPlugin' ];

// Each of these holds a store or registry, or must match one that does, so every import gets the playground's pinned copy.
const PINNED = [
	'@wordpress/block-editor',
	'@wordpress/block-library',
	'@wordpress/blocks',
	'@wordpress/components',
	'@wordpress/core-data',
	'@wordpress/data',
	'@wordpress/dataviews',
	'@wordpress/global-styles-engine',
	'@wordpress/global-styles-ui',
];

const pinnedAliases = () =>
	Object.fromEntries(
		PINNED.flatMap( name => {
			const root = packageRoot( name );
			return [ [ `${ name }$`, root ], [ `${ name }/build-style`, path.join( root, 'build-style' ) ] ];
		} )
	);

module.exports = ( { entry, outDir, aliases = {} } ) => {
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
			// @wordpress/sync, which core-data loads, imports `fast-deep-equal/es6` with no extension from .mjs files.
			rules: [ ...( base.module?.rules || [] ), { test: /\.mjs$/, resolve: { fullySpecified: false } } ],
		},
		optimization: { ...base.optimization, splitChunks: false, runtimeChunk: false },
		resolve: {
			...base.resolve,
			alias: {
				...( base.resolve.alias || {} ),
				'@np': path.join( repo, 'packages/components/src' ),
				'@plugin': path.join( pluginDir, 'src' ),
				'newspack-playground/bridge$': path.join( __dirname, 'bridge.js' ),
				'newspack-playground/theme$': path.join( __dirname, 'theme.js' ),
				'@themes': path.join( repo, 'themes' ),
				'@wordpress/route$': path.join( __dirname, 'route-stub.js' ),
				...pinnedAliases(),
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
