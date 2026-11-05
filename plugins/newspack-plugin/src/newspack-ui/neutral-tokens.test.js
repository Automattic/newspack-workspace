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
	const base = {};
	const media = {};
	const stack = [];
	let buffer = '';

	const declare = () => {
		const declaration = buffer.match( /^\s*(--[\w-]+)\s*:\s*([\s\S]+?)\s*$/ );
		buffer = '';
		const header = stack[ stack.length - 1 ];
		if ( ! declaration || ! header || ! header.split( ',' ).some( selector => selector.trim() === ':root' ) ) {
			return;
		}
		const queries = stack
			.slice( 0, -1 )
			.filter( entry => entry.startsWith( '@' ) )
			.map( entry => entry.replace( /\s+/g, ' ' ) );
		if ( ! queries.length ) {
			base[ declaration[ 1 ] ] = declaration[ 2 ];
			return;
		}
		const key = queries.join( ' > ' );
		media[ key ] = media[ key ] || {};
		media[ key ][ declaration[ 1 ] ] = declaration[ 2 ];
	};

	for ( const char of css.replace( /\/\*[\s\S]*?\*\//g, '' ) ) {
		if ( char === '{' ) {
			stack.push( buffer.trim() );
			buffer = '';
		} else if ( char === ';' ) {
			declare();
		} else if ( char === '}' ) {
			declare();
			stack.pop();
		} else {
			buffer += char;
		}
	}
	return { base, media };
};

const resolveValue = ( value, properties, seen = [] ) =>
	value.replace( /var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*(?:\([^()]*\)[^()]*)*))?\)/g, ( whole, name, fallback ) => {
		if ( properties[ name ] !== undefined && ! seen.includes( name ) ) {
			return resolveValue( properties[ name ], properties, [ ...seen, name ] );
		}
		return fallback !== undefined ? resolveValue( fallback.trim(), properties, seen ) : whole;
	} );

const resolveAll = ( properties, context = properties ) =>
	Object.keys( properties )
		.sort()
		.reduce( ( resolved, name ) => {
			resolved[ name ] = resolveValue( properties[ name ], context, [ name ] );
			return resolved;
		}, {} );

const resolveTokens = css => {
	const { base, media } = collectRootProperties( css );
	const tokens = resolveAll( base );
	Object.keys( media )
		.sort()
		.forEach( query => {
			tokens[ query ] = resolveAll( media[ query ], { ...base, ...media[ query ] } );
		} );
	return tokens;
};

const flatten = tokens => Object.values( tokens ).flatMap( value => ( typeof value === 'string' ? [ value ] : Object.values( value ) ) );

describe( 'Newspack UI Neutral tokens', () => {
	const actual = resolveTokens( compile() );

	it( 'resolve to the pinned values', () => {
		const expected = JSON.parse( fs.readFileSync( FIXTURE, 'utf8' ) );
		expect( Object.keys( actual ).length ).toBeGreaterThan( 100 );
		expect( actual ).toEqual( expected );
	} );

	it( 'leave no unresolved var() reference', () => {
		expect( flatten( actual ).filter( value => value.includes( 'var(' ) ) ).toEqual( [] );
	} );
} );
