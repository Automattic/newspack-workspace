/**
 * Keeps the block theme's elevation presets on the same geometry and tokens as the newspack-ui mixin.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '../../../..' );
const THEME = path.join( ROOT, 'themes/newspack-block-theme' );
const LEVELS = [ 1, 2, 3, 4 ];

const COMPILE_SCRIPT = `
	const sass = require( 'sass' );
	const [ source, loadPath, nodeModules ] = process.argv.slice( 1 );
	process.stdout.write(
		sass.compileString( source, { loadPaths: [ loadPath, nodeModules ], style: 'expanded', quietDeps: true, logger: sass.Logger.silent } ).css
	);
`;

const SOURCE = [
	'@use "mixins";',
	'@use "variables/colors";',
	...LEVELS.map( level => `.level-${ level } { @include mixins.newspack-ui-elevation( ${ level } ); }` ),
].join( '\n' );

const css = execFileSync(
	process.execPath,
	[ '-e', COMPILE_SCRIPT, SOURCE, path.resolve( __dirname, 'scss' ), path.resolve( __dirname, '../../node_modules' ) ],
	{ cwd: path.resolve( __dirname, '../..' ), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
).replace( /\/\*[\s\S]*?\*\//g, '' );

const declaration = ( selector, property ) => {
	const rule = [ ...css.matchAll( /([^{}]+)\{([^{}]*)\}/g ) ].find( ( [ , selectors ] ) =>
		selectors.split( ',' ).some( s => s.trim() === selector )
	);
	const match = rule && rule[ 2 ].match( new RegExp( `(?:^|;|\\s)${ property }\\s*:\\s*([^;]+)` ) );
	return match ? match[ 1 ].trim() : null;
};

const normalize = value =>
	value
		.replace( /--wp--custom--elevation--/g, '--newspack-ui-elevation-' )
		.replace( /--wp--preset--color--contrast/g, '--newspack-ui-color-contrast' )
		.replace( /\(\s+/g, '(' )
		.replace( /\s+\)/g, ')' )
		.replace( /#000000\b/gi, '#000' )
		.replace( /\s+/g, ' ' )
		.trim();

const readJson = file => JSON.parse( fs.readFileSync( path.join( THEME, file ), 'utf8' ) );
const theme = readJson( 'theme.json' );
const presets = Object.fromEntries( theme.settings.shadow.presets.map( preset => [ preset.slug, preset ] ) );

const palette = fs.readFileSync( path.resolve( __dirname, '../../includes/class-newspack-ui-palette.php' ), 'utf8' );
const darkTokens = Object.fromEntries(
	[ ...palette.match( /ELEVATION_DARK = \[([\s\S]*?)\];/ )[ 1 ].matchAll( /'--newspack-ui-elevation-(\w+)'\s*=>\s*'([^']+)'/g ) ].map(
		( [ , name, value ] ) => [ name, value ]
	)
);

describe( 'Elevation scale', () => {
	it.each( LEVELS )( 'theme preset elevation-%i matches the newspack-ui mixin', level => {
		const compiled = declaration( `.level-${ level }`, 'box-shadow' );
		expect( compiled ).toContain( 'var(--newspack-ui-elevation-edge)' );
		expect( normalize( presets[ `elevation-${ level }` ].shadow ) ).toBe( normalize( compiled ) );
	} );

	it( 'theme elevation defaults match the newspack-ui Neutral tokens', () => {
		[ 'ink', 'edge', 'strength' ].forEach( name => {
			expect( normalize( theme.settings.custom.elevation[ name ] ) ).toBe(
				normalize( declaration( ':root', `--newspack-ui-elevation-${ name }` ) )
			);
		} );
	} );

	it.each( [ 'styles/10-nocturne.json', 'styles/11-nocturne-alt.json' ] )( '%s uses the dark tokens the Theme adapter prints', file => {
		const elevation = readJson( file ).settings.custom.elevation;
		expect( Object.keys( darkTokens ).sort() ).toEqual( [ 'edge', 'ink', 'strength' ] );
		Object.entries( darkTokens ).forEach( ( [ name, value ] ) => {
			expect( normalize( elevation[ name ] ) ).toBe( normalize( value ) );
		} );
	} );
} );
