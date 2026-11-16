const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { loadConfig } = require( './config' );

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
		expect( () => loadConfig( dir ) ).toThrow( `playground: could not load ${ path.join( dir, 'playground.config.js' ) }` );
	} );

	it( 'throws when there is no config', () => {
		expect( () => loadConfig( dir ) ).toThrow( `playground: no playground.config.js in ${ dir }` );
	} );
} );
