const path = require( 'path' );
const createWebpackConfig = require( './webpack.config' );
const { packageRoot } = require( './pinned' );

const scriptsDir = path.dirname( require.resolve( 'newspack-scripts/package.json' ) );
const wpScripts = path.dirname( require.resolve( '@wordpress/scripts/package.json', { paths: [ scriptsDir ] } ) );
const webpack = require( require.resolve( 'webpack', { paths: [ wpScripts ] } ) );

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
		const globalStylesUi = packageRoot( '@wordpress/global-styles-ui' );
		const original = path.join( globalStylesUi, 'build-module/color-palette-panel.mjs' );
		const replacement = path.join( __dirname, '../fixtures/hello/entry.js' );
		const resolve = resolverFor( createWebpackConfig( { entry: '/tmp/entry.js', outDir: '/tmp/out', replace: { [ original ]: replacement } } ) );

		it( "swaps the module for the package's own relative imports of it", async () => {
			await expect( resolve( path.join( globalStylesUi, 'build-module' ), './color-palette-panel.mjs' ) ).resolves.toBe( replacement );
		} );

		it( 'swaps the module for a deep import of it from outside the package', async () => {
			await expect( resolve( __dirname, '@wordpress/global-styles-ui/build-module/color-palette-panel.mjs' ) ).resolves.toBe( replacement );
		} );
	} );

	it( "reaches a pinned package's build-module files, which its exports map hides", async () => {
		const resolve = resolverFor( config );
		await expect( resolve( __dirname, '@wordpress/global-styles-ui/build-module/hooks.mjs' ) ).resolves.toBe(
			path.join( packageRoot( '@wordpress/global-styles-ui' ), 'build-module/hooks.mjs' )
		);
	} );
} );
