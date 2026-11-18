const { spawnSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

describe( 'build.js', () => {
	let dir;

	beforeEach( () => {
		dir = fs.mkdtempSync( path.join( os.tmpdir(), 'playground-build-' ) );
	} );
	afterEach( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const build = key => {
		fs.writeFileSync( path.join( dir, 'entry.js' ), "import { addAction } from '@wordpress/hooks';\naddAction( 'a', 'b', () => {} );\n" );
		fs.writeFileSync( path.join( dir, 'replacement.js' ), 'export default () => true;\n' );
		fs.writeFileSync(
			path.join( dir, 'playground.config.js' ),
			`module.exports = ${ JSON.stringify( { entry: './entry.js', replace: { [ key ]: './replacement.js' } } ) };`
		);
		return spawnSync( process.execPath, [ path.join( __dirname, '../bin/build.js' ), dir, '--out', path.join( dir, 'out' ) ], { encoding: 'utf8' } );
	};

	it( 'fails when no import in the bundle reaches a replaced file', () => {
		const key = '@wordpress/hooks/build/validateNamespace.cjs';
		const result = build( key );
		expect( result.stderr ).toContain( `playground: replace "${ key }": no import in the bundle reaches that file` );
		expect( result.status ).toBe( 1 );
	}, 120000 );

	it( 'builds when an import reaches the replaced file', () => {
		const result = build( '@wordpress/hooks/build-module/validateNamespace.mjs' );
		expect( result.stderr ).not.toContain( 'playground: replace' );
		expect( result.status ).toBe( 0 );
	}, 120000 );
} );
