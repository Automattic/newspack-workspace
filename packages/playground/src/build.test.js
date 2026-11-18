const { spawnSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

describe( 'build.js', () => {
	let dir;

	beforeEach( () => {
		dir = fs.mkdtempSync( path.join( fs.realpathSync( os.tmpdir() ), 'playground-build-' ) );
	} );
	afterEach( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const build = ( key, entry = '' ) => {
		fs.writeFileSync( path.join( dir, 'entry.js' ), `import { addAction } from '@wordpress/hooks';\n${ entry }addAction( 'a', 'b', () => {} );\n` );
		fs.writeFileSync( path.join( dir, 'replacement.js' ), 'export default () => true;\n' );
		fs.writeFileSync(
			path.join( dir, 'playground.config.js' ),
			`module.exports = ${ JSON.stringify( { entry: './entry.js', replace: { [ key ]: './replacement.js' } } ) };`
		);
		return spawnSync( process.execPath, [ path.join( __dirname, '../bin/build.js' ), dir, '--out', path.join( dir, 'out' ) ], { encoding: 'utf8' } );
	};

	it.each( [
		[ 'no import reaches it', '' ],
		[ 'only the prototype imports the replacement', "import replacement from './replacement.js';\nreplacement();\n" ],
	] )( 'fails when nothing in the package imports the replaced file and %s', ( label, entry ) => {
		const key = '@wordpress/hooks/build/validateNamespace.cjs';
		const result = build( key, entry );
		expect( result.stderr ).toContain(
			`playground: replace "${ key }" in ${ path.join( dir, 'playground.config.js' ) }: nothing in @wordpress/hooks imports that file`
		);
		expect( result.status ).toBe( 1 );
	}, 120000 );

	it( 'builds when the package imports the replaced file', () => {
		const result = build( '@wordpress/hooks/build-module/validateNamespace.mjs' );
		expect( result.stderr ).not.toContain( 'playground: replace' );
		expect( result.status ).toBe( 0 );
	}, 120000 );
} );
