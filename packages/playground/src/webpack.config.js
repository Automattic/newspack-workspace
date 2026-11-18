const fs = require( 'fs' );
const path = require( 'path' );

const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const { PINNED, packageDir, packageRoot } = require( './pinned' );

const repo = path.resolve( __dirname, '../../..' );

const scriptsModules = path.resolve( packageRoot( 'newspack-scripts' ), 'node_modules' );

const REMOVED_PLUGINS = [ 'DependencyExtractionWebpackPlugin', 'RtlCssPlugin', 'CopyPlugin', 'PhpFilePathsPlugin' ];

const pinnedAliases = () =>
	Object.fromEntries(
		PINNED.flatMap( name => {
			const root = packageRoot( name );
			return [
				[ `${ name }$`, root ],
				[ `${ name }/build-module`, path.join( root, 'build-module' ) ],
				[ `${ name }/build-style`, path.join( root, 'build-style' ) ],
			];
		} )
	);

// Webpack also matches aliases against a file's resolved absolute path, so an exact alias on it catches the package's own relative imports.
const replaceAliases = replace => Object.fromEntries( replace.map( ( { original, replacement } ) => [ `${ original }$`, replacement ] ) );

// The playground's own node_modules comes first so a package it declares keeps that copy; anything else resolves from the original package's dependencies.
const replaceRules = replace =>
	replace.map( ( { name, replacement } ) => ( {
		include: fs.realpathSync( replacement ),
		resolve: {
			modules: [ path.join( packageDir, 'node_modules' ), path.resolve( packageRoot( name ), ...name.split( '/' ).map( () => '..' ) ), '...' ],
		},
	} ) );

module.exports = ( { entry, outDir, aliases = {}, replace = [] } ) => {
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
			rules: [ ...( base.module?.rules || [] ), { test: /\.mjs$/, resolve: { fullySpecified: false } }, ...replaceRules( replace ) ],
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
				...replaceAliases( replace ),
			},
			modules: [ 'node_modules', path.join( packageDir, 'node_modules' ), path.join( pluginDir, 'node_modules' ), path.join( repo, 'node_modules' ) ],
		},
		resolveLoader: {
			modules: [ path.join( pluginDir, 'node_modules' ), scriptsModules, path.join( repo, 'node_modules' ) ],
		},
		performance: { hints: false },
	};
};
