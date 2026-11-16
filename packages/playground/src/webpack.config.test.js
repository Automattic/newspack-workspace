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

	it( 'fails the build on a missing export', () => {
		expect( config.module.parser.javascript.exportsPresence ).toBe( 'error' );
	} );
} );
