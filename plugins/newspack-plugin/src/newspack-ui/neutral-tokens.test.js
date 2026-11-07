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

const stylesheet = compile();

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
	const actual = resolveTokens( stylesheet );

	it( 'resolve to the pinned values', () => {
		const expected = JSON.parse( fs.readFileSync( FIXTURE, 'utf8' ) );
		expect( Object.keys( actual ).length ).toBeGreaterThan( 100 );
		expect( actual ).toEqual( expected );
	} );

	it( 'leave no unresolved var() reference', () => {
		expect( flatten( actual ).filter( value => value.includes( 'var(' ) ) ).toEqual( [] );
	} );

	it( 'read destructive buttons through the destructive role tokens', () => {
		const rules = [ ...stylesheet.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ].filter( ( [ , selector ] ) =>
			selector.includes( '.newspack-ui__button--destructive' )
		);
		const background = rules.find(
			( [ , selector, body ] ) =>
				selector.split( ',' ).some( part => part.trim() === '.newspack-ui .newspack-ui__button--destructive' ) &&
				body.includes( '--newspack-ui-button-bg:' )
		);
		expect( background[ 2 ] ).toMatch( /--newspack-ui-button-bg:\s*var\(--newspack-ui-color-destructive-fill\)/ );
		expect( rules.filter( ( [ , , body ] ) => body.includes( '--newspack-ui-color-error-fill' ) ) ).toEqual( [] );
	} );

	it( 'read the field tokens only from the text input and select selectors', () => {
		const reading = [ ...stylesheet.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ].filter( ( [ , , body ] ) =>
			/var\(--newspack-ui-field-(?:radius|border-width|border-color|bg|ink|font-family)/.test( body )
		);
		const uncovered = /type="?(?:range|date|month|week|time|datetime|datetime-local|zip|color|file|checkbox|radio)"?\]/;
		expect( reading.length ).toBeGreaterThan( 0 );
		reading.forEach( ( [ , selector ] ) => {
			expect( selector ).not.toMatch( uncovered );
		} );
		expect( reading.some( ( [ , selector ] ) => /input\[type="?text"?\]/.test( selector ) ) ).toBe( true );
		expect( reading.some( ( [ , selector ] ) => /\bselect\b/.test( selector ) ) ).toBe( true );
	} );

	const rulesOf = css => [ ...css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ];

	it( 'draw the code input from the field background and ink', () => {
		const rule = rulesOf( stylesheet ).find(
			( [ , selector, body ] ) => selector.includes( '__code-input input' ) && body.includes( 'linear-gradient' )
		);
		expect( rule[ 2 ] ).toMatch( /var\(--newspack-ui-field-bg\);/ );
		expect( rule[ 2 ] ).toMatch( /linear-gradient\(var\(--newspack-ui-field-ink\), var\(--newspack-ui-field-ink\)\)/ );
	} );

	it( 'draw select2 from the field tokens', () => {
		const rules = rulesOf( stylesheet );
		const selection = rules.find(
			( [ , selector, body ] ) => selector.trim().endsWith( '.select2-selection--single' ) && body.includes( 'display: flex' )
		);
		expect( selection[ 2 ] ).toMatch( /background:\s*var\(--newspack-ui-field-bg\)/ );
		expect( selection[ 2 ] ).toMatch( /border-radius:\s*var\(--newspack-ui-field-radius\)/ );
		expect( selection[ 2 ] ).toMatch( /color:\s*var\(--newspack-ui-field-ink\)/ );
		const rendered = rules.find( ( [ , selector ] ) => selector.trim().endsWith( '.select2-selection__rendered' ) );
		expect( rendered[ 2 ] ).toMatch( /font-family:\s*var\(--newspack-ui-field-font-family\)/ );
		const dropdown = rules.find( ( [ , selector ] ) => selector.trim().endsWith( '.select2-dropdown' ) );
		expect( dropdown[ 2 ] ).not.toMatch( /--newspack-ui-field-/ );
	} );

	it( "read the select caret, placeholder and toggle tones with today's fallbacks", () => {
		expect( stylesheet ).toMatch(
			/background:\s*var\(--newspack-ui-field-bg\)\s*var\(--newspack-ui-field-caret, var\(--newspack-ui-field-caret-dark\)\)/
		);
		expect( stylesheet ).toMatch( /::placeholder\s*\{[^}]*var\(--newspack-ui-field-ink-subtle, var\(--newspack-ui-color-text-subtle\)\)/ );
		expect( stylesheet ).toMatch( /--newspack-ui-button-ink:\s*var\(--newspack-ui-field-ink-muted, var\(--newspack-ui-color-text-muted\)\)/ );
	} );

	it( 'return the covered field border to the input border under high contrast', () => {
		const blocks = [ ...stylesheet.matchAll( /@media \(prefers-contrast: more\)\s*\{\s*([^{}]+)\{([^{}]*)\}/g ) ];
		const fieldBlocks = blocks.filter( ( [ , , body ] ) => /border-color:\s*var\(--newspack-ui-color-input-border\)/.test( body ) );
		expect( fieldBlocks.some( ( [ , selector ] ) => /input\[type="?text"?\]/.test( selector ) ) ).toBe( true );
		expect( fieldBlocks.some( ( [ , selector ] ) => /(^|[\s,])\.newspack-ui select/.test( selector ) ) ).toBe( true );
		expect( fieldBlocks.some( ( [ , selector ] ) => selector.trim() === '.newspack-ui__currency-input' ) ).toBe( true );
	} );

	it( 'draw the currency wrapper from the field border and radius', () => {
		const wrapper = rulesOf( stylesheet ).find(
			( [ , selector, body ] ) => selector.trim() === '.newspack-ui__currency-input' && body.includes( 'grid-template-columns' )
		);
		expect( wrapper[ 2 ] ).toMatch( /border-radius:\s*var\(--newspack-ui-field-radius\)/ );
		expect( wrapper[ 2 ] ).toMatch(
			/border:\s*var\(--newspack-ui-field-border-width\) solid var\(--newspack-ui-field-border-color, var\(--newspack-ui-color-input-border\)\)/
		);
	} );
} );
