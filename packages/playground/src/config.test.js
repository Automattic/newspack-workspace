const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { loadConfig } = require( './config' );
const { packageRoot } = require( './pinned' );

describe( 'loadConfig', () => {
	let dir;
	const write = ( file, content = '' ) => {
		fs.mkdirSync( path.dirname( path.join( dir, file ) ), { recursive: true } );
		fs.writeFileSync( path.join( dir, file ), content );
	};
	const writeConfig = config => write( 'playground.config.js', `module.exports = ${ JSON.stringify( config ) };` );

	beforeEach( () => {
		dir = fs.mkdtempSync( path.join( os.tmpdir(), 'playground-config-' ) );
	} );
	afterEach( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	it( 'resolves the entry and each alias to absolute paths', () => {
		write( 'src/entry.js' );
		write( 'shared/index.js' );
		writeConfig( { title: 'Demo', entry: './src/entry.js', aliases: { '@shared': './shared' } } );
		expect( loadConfig( dir ) ).toEqual( {
			title: 'Demo',
			entry: path.join( dir, 'src/entry.js' ),
			aliases: { '@shared': path.join( dir, 'shared' ) },
			replace: [],
		} );
	} );

	it( 'defaults aliases to an empty object', () => {
		write( 'entry.js' );
		writeConfig( { title: 'Demo', entry: './entry.js' } );
		expect( loadConfig( dir ).aliases ).toEqual( {} );
	} );

	it.each( [
		[ 'not set', { title: 'Demo' } ],
		[ 'an empty string', { title: 'Demo', entry: '' } ],
	] )( 'throws when the entry is %s', ( label, config ) => {
		writeConfig( config );
		expect( () => loadConfig( dir ) ).toThrow( 'playground: playground.config.js has no entry' );
	} );

	it( 'throws for a missing entry', () => {
		writeConfig( { title: 'Demo', entry: './nope.js' } );
		expect( () => loadConfig( dir ) ).toThrow( `playground: ${ path.join( dir, 'nope.js' ) } does not exist` );
	} );

	it( 'throws for a missing alias target', () => {
		write( 'entry.js' );
		writeConfig( { title: 'Demo', entry: './entry.js', aliases: { '@x': './missing' } } );
		expect( () => loadConfig( dir ) ).toThrow( `playground: ${ path.join( dir, 'missing' ) } does not exist` );
	} );

	it( 'names the config file when it cannot be loaded', () => {
		write( 'playground.config.js', 'module.exports = {' );
		const file = path.join( dir, 'playground.config.js' );
		let thrown;
		try {
			loadConfig( dir );
		} catch ( error ) {
			thrown = error;
		}
		expect( thrown.message ).toBe( `playground: could not load ${ file }` );
		expect( thrown.cause.name ).toBe( 'SyntaxError' );
	} );

	it( 'throws when there is no config', () => {
		expect( () => loadConfig( dir ) ).toThrow( `playground: no playground.config.js in ${ dir }` );
	} );

	describe( 'replace', () => {
		const key = '@wordpress/global-styles-ui/build-module/color-palette-panel.mjs';
		const globalStylesUi = packageRoot( '@wordpress/global-styles-ui' );

		it( "maps the pinned package's file to the replacement, relative to the config", () => {
			write( 'entry.js' );
			write( 'shared/panel.js' );
			writeConfig( { entry: './entry.js', replace: { [ key ]: './shared/panel.js' } } );
			expect( loadConfig( dir ).replace ).toEqual( [
				{
					key,
					name: '@wordpress/global-styles-ui',
					original: path.join( globalStylesUi, 'build-module/color-palette-panel.mjs' ),
					replacement: path.join( dir, 'shared/panel.js' ),
				},
			] );
		} );

		it.each( [
			[ 'the key does not start with a package name', '/build-module/index.mjs', './shared/panel.js', () => 'the key must start with a package name' ],
			[ 'the package is not pinned', '@wordpress/compose/build-module/index.mjs', './shared/panel.js', () => '@wordpress/compose is not a package the playground pins' ],
			[
				'the key leaves the package',
				'@wordpress/global-styles-ui/../../../../../../package.json',
				'./shared/panel.js',
				() => `${ path.join( globalStylesUi, '../../../../../../package.json' ) } is outside @wordpress/global-styles-ui`,
			],
			[
				'the file is not in the package',
				'@wordpress/global-styles-ui/build-module/nope.mjs',
				'./shared/panel.js',
				() => `no file at ${ path.join( globalStylesUi, 'build-module/nope.mjs' ) }`,
			],
			[ 'the replacement does not exist', key, './missing.js', () => `no file at ${ path.join( dir, 'missing.js' ) }` ],
		] )( 'throws, naming the key and the config, when %s', ( label, replaceKey, target, reason ) => {
			write( 'entry.js' );
			write( 'shared/panel.js' );
			writeConfig( { entry: './entry.js', replace: { [ replaceKey ]: target } } );
			expect( () => loadConfig( dir ) ).toThrow( `playground: replace "${ replaceKey }" in ${ path.join( dir, 'playground.config.js' ) }: ${ reason() }` );
		} );
	} );
} );
