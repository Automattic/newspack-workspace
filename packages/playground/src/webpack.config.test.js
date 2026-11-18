const createWebpackConfig = require( './webpack.config' );

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
} );
