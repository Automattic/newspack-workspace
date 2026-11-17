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
		for ( const name of [ '@wordpress/data', '@wordpress/blocks', '@wordpress/block-editor', '@wordpress/core-data' ] ) {
			const pinned = require( 'path' ).dirname( require.resolve( `${ name }/package.json`, { paths: [ packageDir ] } ) );
			expect( config.resolve.alias[ `${ name }$` ] ).toBe( pinned );
		}
	} );

	it( 'reaches the themes folder through @themes', () => {
		expect( config.resolve.alias[ '@themes' ] ).toBe( require( 'path' ).resolve( __dirname, '../../../themes' ) );
	} );

	it( 'fails the build on a missing export', () => {
		expect( config.module.parser.javascript.exportsPresence ).toBe( 'error' );
	} );
} );
