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

	const useHooks = "import { addAction } from '@wordpress/hooks';\naddAction( 'a', 'b', () => {} );\n";
	const importReplacement = "import replacement from './replacement.js';\nreplacement();\n";

	const build = ( key, entry ) => {
		fs.writeFileSync( path.join( dir, 'entry.js' ), entry );
		fs.writeFileSync( path.join( dir, 'replacement.js' ), 'export default () => true;\n' );
		fs.writeFileSync(
			path.join( dir, 'playground.config.js' ),
			`module.exports = ${ JSON.stringify( { entry: './entry.js', replace: { [ key ]: './replacement.js' } } ) };`
		);
		const { NODE_ENV, ...env } = process.env;
		return spawnSync( process.execPath, [ path.join( __dirname, '../bin/build.js' ), dir, '--out', path.join( dir, 'out' ) ], { encoding: 'utf8', env } );
	};

	it.each( [
		[ 'no import reaches it', useHooks ],
		[ 'only the prototype imports the replacement', useHooks + importReplacement ],
	] )( 'fails when nothing in the package imports the replaced file and %s', ( label, entry ) => {
		const key = '@wordpress/hooks/build/validateNamespace.cjs';
		const result = build( key, entry );
		expect( result.stderr ).toContain(
			`playground: replace "${ key }" in ${ path.join( dir, 'playground.config.js' ) }: nothing in @wordpress/hooks imports that file`
		);
		expect( result.status ).toBe( 1 );
	}, 120000 );

	it.each( [
		[ 'the package imports the replaced file', useHooks ],
		[
			'the replacement is concatenated into the entry and the package imports it from a lazy chunk',
			"import replacement from './replacement.js';\nimport( '@wordpress/hooks/build-module/createAddHook.mjs' ).then( module => console.log( module, replacement ) );\n",
		],
		[ 'the prototype imports the replaced file by its key', "import * as validateNamespace from '@wordpress/hooks/build-module/validateNamespace.mjs';\nconsole.log( validateNamespace );\n" ],
	] )( 'builds when %s', ( label, entry ) => {
		const result = build( '@wordpress/hooks/build-module/validateNamespace.mjs', entry );
		expect( result.stderr ).not.toContain( 'playground: replace' );
		expect( result.status ).toBe( 0 );
	}, 120000 );
} );
