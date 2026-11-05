/**
 * Pins every :root custom property the Newspack UI stylesheet declares.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const FIXTURE = path.resolve( __dirname, '../../tests/fixtures/newspack-ui-neutral-tokens.json' );

const COMPILE_SCRIPT = `
	const sass = require( 'sass' );
	const [ entry, loadPath ] = process.argv.slice( 1 );
	process.stdout.write(
		sass.compile( entry, { loadPaths: [ loadPath ], style: 'expanded', quietDeps: true, logger: sass.Logger.silent } ).css
	);
`;

const compile = () =>
	execFileSync(
		process.execPath,
		[ '-e', COMPILE_SCRIPT, path.resolve( __dirname, 'style.scss' ), path.resolve( __dirname, '../../node_modules' ) ],
		{
			cwd: path.resolve( __dirname, '../..' ),
			encoding: 'utf8',
			maxBuffer: 64 * 1024 * 1024,
		}
	);

const collectRootProperties = css => {
	const properties = {};
	const rule = /(^|\})\s*:root\s*\{([^}]*)\}/g;
	let match;
	while ( ( match = rule.exec( css ) ) !== null ) {
		const declaration = /(--[\w-]+)\s*:\s*([^;]+);/g;
		let found;
		while ( ( found = declaration.exec( match[ 2 ] ) ) !== null ) {
			properties[ found[ 1 ] ] = found[ 2 ].trim();
		}
	}
	return properties;
};

const resolveValue = ( value, properties, seen = [] ) =>
	value.replace( /var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*(?:\([^()]*\)[^()]*)*))?\)/g, ( whole, name, fallback ) => {
		if ( properties[ name ] !== undefined && ! seen.includes( name ) ) {
			return resolveValue( properties[ name ], properties, [ ...seen, name ] );
		}
		return fallback !== undefined ? resolveValue( fallback.trim(), properties, seen ) : whole;
	} );

const resolveAll = properties =>
	Object.keys( properties )
		.sort()
		.reduce( ( resolved, name ) => {
			resolved[ name ] = resolveValue( properties[ name ], properties, [ name ] );
			return resolved;
		}, {} );

describe( 'Newspack UI Neutral tokens', () => {
	it( 'resolve to the pinned values', () => {
		const actual = resolveAll( collectRootProperties( compile() ) );
		const expected = JSON.parse( fs.readFileSync( FIXTURE, 'utf8' ) );
		expect( Object.keys( actual ).length ).toBeGreaterThan( 100 );
		expect( actual ).toEqual( expected );
	} );
} );
