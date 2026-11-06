/**
 * Pins compiled Newspack UI rules that must keep reading the adapter's tokens.
 */

const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const COMPILE_SCRIPT = `
	const sass = require( 'sass' );
	const [ entry, loadPath ] = process.argv.slice( 1 );
	process.stdout.write(
		sass.compile( entry, { loadPaths: [ loadPath ], style: 'expanded', quietDeps: true, logger: sass.Logger.silent } ).css
	);
`;

const css = execFileSync(
	process.execPath,
	[ '-e', COMPILE_SCRIPT, path.resolve( __dirname, 'style.scss' ), path.resolve( __dirname, '../../node_modules' ) ],
	{ cwd: path.resolve( __dirname, '../..' ), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
).replace( /\/\*[\s\S]*?\*\//g, '' );

const rulesFor = selector =>
	[ ...css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ]
		.filter( ( [ , selectors ] ) => selectors.split( ',' ).some( s => s.trim() === selector ) )
		.map( ( [ , , body ] ) => body );

const declarationsOf = ( selector, property ) =>
	rulesFor( selector ).flatMap( body =>
		[ ...body.matchAll( new RegExp( `(?:^|;|\\s)${ property }\\s*:\\s*([^;]+)`, 'g' ) ) ].map( match => match[ 1 ].trim() )
	);

describe( 'Newspack UI compiled rules', () => {
	const HEADING_WEIGHT = 'var(--newspack-ui-heading-font-weight, var(--newspack-ui-font-weight-strong))';

	it( 'modal headings read the theme heading weight', () => {
		[ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].forEach( tag => {
			const weights = declarationsOf( `.newspack-ui__modal ${ tag }`, 'font-weight' );
			expect( weights.length ).toBeGreaterThan( 0 );
			weights.forEach( weight => expect( weight ).toBe( HEADING_WEIGHT ) );
		} );
	} );

	it( 'modal strong keeps the strong weight', () => {
		expect( declarationsOf( '.newspack-ui__modal strong', 'font-weight' ) ).toEqual( [ 'var(--newspack-ui-font-weight-strong)' ] );
	} );

	it( 'the inline sign-in heading reads the theme heading weight', () => {
		const weights = declarationsOf( '.woocommerce-account .newspack-reader-auth__inline-wrapper h2', 'font-weight' );
		expect( weights.length ).toBeGreaterThan( 0 );
		weights.forEach( weight => expect( weight ).toBe( HEADING_WEIGHT ) );
	} );

	it( 'the Google button resets typography and radius to Neutral values', () => {
		const bodies = rulesFor( '.newspack-ui .newspack-ui__button--google-oauth' ).join( ';' );
		[
			[ 'button-font-family', 'var(--newspack-ui-font-family)' ],
			[ 'button-font-size', '16px' ],
			[ 'button-line-height', '1.5' ],
			[ 'button-font-weight', 'var(--newspack-ui-font-weight-strong)' ],
			[ 'button-letter-spacing', 'normal' ],
			[ 'button-text-transform', 'none' ],
			[ 'button-white-space', 'nowrap' ],
			[ 'radius-button', 'var(--newspack-ui-border-radius-l)' ],
		].forEach( ( [ name, value ] ) => {
			expect( bodies ).toContain( `--newspack-ui-${ name }: ${ value }` );
		} );
	} );
} );
