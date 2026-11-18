const { spawnSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const createWebpackConfig = require( './webpack.config' );
const { linkedDeps, packageRoot } = require( './pinned' );

const scriptsDir = path.dirname( require.resolve( 'newspack-scripts/package.json' ) );
const wpScripts = path.dirname( require.resolve( '@wordpress/scripts/package.json', { paths: [ scriptsDir ] } ) );
const webpack = require( require.resolve( 'webpack', { paths: [ wpScripts ] } ) );

// Jest's jsdom environment can't run a webpack compile, so it runs in a child process that prints each bundled module's resource.
const COMPILE = `
const [ webpackPath, configPath, options ] = process.argv.slice( 1 );
require( webpackPath )( require( configPath )( JSON.parse( options ) ), ( error, stats ) => {
	if ( error || stats.hasErrors() ) {
		console.error( error || stats.toString( 'errors-only' ) );
		process.exit( 1 );
	}
	const modules = [ ...stats.compilation.modules ].flatMap( module => [ module, ...( module.modules || [] ) ] );
	process.stdout.write( JSON.stringify( modules.map( module => module.resource ).filter( Boolean ) ) );
} );
`;

const bundledResources = options => {
	const result = spawnSync(
		process.execPath,
		[ '-e', COMPILE, require.resolve( 'webpack', { paths: [ wpScripts ] } ), require.resolve( './webpack.config' ), JSON.stringify( options ) ],
		{ cwd: path.resolve( __dirname, '../../..' ), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
	);
	if ( result.status !== 0 ) {
		throw result.error || new Error( result.stderr );
	}
	return JSON.parse( result.stdout );
};

const resolverFor = webpackConfig => {
	const resolver = webpack( webpackConfig ).resolverFactory.get( 'normal', { dependencyType: 'esm' } );
	return ( context, request ) =>
		new Promise( ( resolve, reject ) => resolver.resolve( {}, context, request, {}, ( error, result ) => ( error ? reject( error ) : resolve( result ) ) ) );
};

describe( 'createWebpackConfig', () => {
	const config = createWebpackConfig( { entry: '/tmp/entry.js', outDir: '/tmp/out' } );

	it( 'does not clean the output directory', () => {
		expect( config.output.clean ).toBeUndefined();
		expect( config.output.path ).toBe( '/tmp/out' );
	} );

	it( 'drops the dependency extraction plugin so wp packages are bundled', () => {
		const names = config.plugins.map( plugin => plugin.constructor.name );
		expect( names ).not.toContain( 'DependencyExtractionWebpackPlugin' );
	} );

	it( 'resolves every editor store to the one pinned copy', () => {
		const packageDir = require( 'path' ).resolve( __dirname, '..' );
		const stores = [
			'@wordpress/api-fetch',
			'@wordpress/block-editor',
			'@wordpress/block-library',
			'@wordpress/blocks',
			'@wordpress/components',
			'@wordpress/core-data',
			'@wordpress/data',
			'@wordpress/dataviews',
			'@wordpress/global-styles-engine',
			'@wordpress/global-styles-ui',
			'@wordpress/hooks',
			'@wordpress/interface',
		];
		for ( const name of stores ) {
			const pinned = require( 'path' ).dirname( require.resolve( `${ name }/package.json`, { paths: [ packageDir ] } ) );
			expect( config.resolve.alias[ `${ name }$` ] ).toBe( pinned );
			expect( require( `${ config.resolve.alias[ `${ name }$` ] }/package.json` ).version ).toBe( require( '../package.json' ).dependencies[ name ] );
		}
	} );

	it( 'reaches the themes folder through @themes', () => {
		expect( config.resolve.alias[ '@themes' ] ).toBe( require( 'path' ).resolve( __dirname, '../../../themes' ) );
	} );

	it( 'fails the build on a missing export', () => {
		expect( config.module.parser.javascript.exportsPresence ).toBe( 'error' );
	} );

	it( 'has a root entry for every pinned package, so the pins leave root node_modules alone', () => {
		const root = require( '../../../package.json' );
		const declared = { ...root.dependencies, ...root.devDependencies };
		// packages/components declares it at the playground's range, so the root slot already holds that version.
		const heldElsewhere = [ '@wordpress/ui' ];
		const pins = Object.keys( require( '../package.json' ).dependencies ).filter(
			name => name.startsWith( '@wordpress/' ) && ! heldElsewhere.includes( name )
		);
		expect( pins.filter( name => ! declared[ name ] ) ).toEqual( [] );
	} );

	describe( 'replace', () => {
		const name = '@wordpress/global-styles-ui';
		const globalStylesUi = packageRoot( name );
		const original = path.join( globalStylesUi, 'build-module/color-palette-panel.mjs' );
		const replacement = path.join( __dirname, '../fixtures/hello/entry.js' );
		const replaceConfig = createWebpackConfig( { entry: '/tmp/entry.js', outDir: '/tmp/out', replace: [ { key: name, name, original, replacement } ] } );
		const resolve = resolverFor( replaceConfig );

		it( "swaps the module for the package's own relative imports of it", async () => {
			await expect( resolve( path.join( globalStylesUi, 'build-module' ), './color-palette-panel.mjs' ) ).resolves.toBe( replacement );
		} );

		it( 'swaps the module for a deep import of it from outside the package', async () => {
			await expect( resolve( __dirname, '@wordpress/global-styles-ui/build-module/color-palette-panel.mjs' ) ).resolves.toBe( replacement );
		} );
	} );

	describe( "a replacement's own imports", () => {
		const name = '@wordpress/global-styles-ui';
		let dir;

		let resources;

		const bundledRoots = pkg => [ ...new Set( resources.map( resource => resource.match( new RegExp( `^(.*/${ pkg }/)` ) )?.[ 1 ] ).filter( Boolean ) ) ];

		beforeAll( () => {
			dir = fs.mkdtempSync( path.join( os.tmpdir(), 'playground-replace-' ) );
			fs.mkdirSync( path.join( dir, 'real' ) );
			fs.writeFileSync(
				path.join( dir, 'real/replacement.js' ),
				"import { useViewportMatch } from '@wordpress/compose';\nimport { store } from '@wordpress/notices';\nexport default () => [ useViewportMatch, store ];\n"
			);
			fs.symlinkSync( path.join( dir, 'real' ), path.join( dir, 'link' ) );
			fs.writeFileSync( path.join( dir, 'entry.js' ), "import replacement from './link/replacement.js';\nreplacement();\n" );
			resources = bundledResources( {
				entry: path.join( dir, 'entry.js' ),
				outDir: path.join( dir, 'out' ),
				replace: [
					{
						key: name,
						name,
						original: path.join( packageRoot( name ), 'build-module/color-palette-panel.mjs' ),
						replacement: path.join( dir, 'link/replacement.js' ),
					},
				],
			} );
		}, 120000 );
		afterAll( () => fs.rmSync( dir, { recursive: true, force: true } ) );

		it( 'get the copy the original package links, though the replacement sits behind a symlink', () => {
			expect( bundledRoots( '@wordpress/compose' ) ).toEqual( [ `${ fs.realpathSync( path.join( linkedDeps( name ), '@wordpress/compose' ) ) }/` ] );
		} );

		it( "fall back to another pinned package's link for what the original package doesn't link", () => {
			expect( bundledRoots( '@wordpress/notices' ) ).toEqual( [
				`${ fs.realpathSync( path.join( linkedDeps( '@wordpress/block-editor' ), '@wordpress/notices' ) ) }/`,
			] );
		} );
	} );

	it( "reaches a pinned package's build-module files, which its exports map hides", async () => {
		const resolve = resolverFor( config );
		await expect( resolve( __dirname, '@wordpress/global-styles-ui/build-module/hooks.mjs' ) ).resolves.toBe(
			path.join( packageRoot( '@wordpress/global-styles-ui' ), 'build-module/hooks.mjs' )
		);
	} );
} );
