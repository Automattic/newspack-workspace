import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as sass from 'sass';
import postcss from 'postcss';

const pkgRoot = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const src = resolve( pkgRoot, '../../plugins/newspack-plugin/src/newspack-ui' );
const motion = resolve( pkgRoot, '../motion' );
const dist = join( pkgRoot, 'build', 'newspack-ui' );

if ( ! existsSync( join( src, 'style.scss' ) ) ) {
	throw new Error( `newspack-ui source not found at ${ src }` );
}

rmSync( dist, { recursive: true, force: true } );
mkdirSync( dist, { recursive: true } );

const motionImporter = {
	findFileUrl( url ) {
		return url === 'newspack-motion' ? pathToFileURL( join( motion, '_index.scss' ) ) : null;
	},
};

const compiled = sass.compile( join( src, 'style.scss' ), {
	importers: [ motionImporter ],
	style: 'compressed',
	quietDeps: true,
	silenceDeprecations: [ 'import', 'global-builtin' ],
} );
const parsed = postcss.parse( compiled.css.replace( /(url\("?\.\/fonts\/[^"?)]+)\?v=[^")]+/g, '$1' ) );
const colors = {};
parsed.walkRules( ':export', rule => {
	rule.walkDecls( decl => {
		colors[ decl.prop ] = decl.value;
	} );
	rule.remove();
} );
const css = parsed.toString();
writeFileSync( join( dist, 'newspack-ui.css' ), css );

const base = {};
const media = {};
const root = postcss.parse( css );
root.walkDecls( /^--newspack-ui-/, decl => {
	const rule = decl.parent;
	if ( 'rule' !== rule.type || ! rule.selectors.includes( ':root' ) ) {
		return;
	}
	const at = rule.parent.type === 'atrule' ? rule.parent : null;
	if ( ! at ) {
		base[ decl.prop ] = decl.value;
	} else if ( 'media' === at.name ) {
		( media[ at.params ] ||= {} )[ decl.prop ] = decl.value;
	}
} );

const resolveValue = ( value, seen = new Set() ) =>
	value.replace( /var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*(?:\([^()]*\)[^()]*)*))?\)/g, ( whole, name, fallback ) => {
		if ( seen.has( name ) ) {
			return whole;
		}
		if ( name in base ) {
			return resolveValue( base[ name ], new Set( seen ).add( name ) );
		}
		return fallback !== undefined ? resolveValue( fallback, seen ) : whole;
	} );

const tokens = {
	style: 'neutral',
	selector: ':root',
	properties: Object.fromEntries( Object.entries( base ).map( ( [ name, value ] ) => [ name, { value, resolved: resolveValue( value ) } ] ) ),
	media: Object.fromEntries(
		Object.entries( media ).map( ( [ query, props ] ) => [
			query,
			Object.fromEntries( Object.entries( props ).map( ( [ name, value ] ) => [ name, { value, resolved: resolveValue( value ) } ] ) ),
		] )
	),
};
writeFileSync( join( dist, 'tokens.json' ), JSON.stringify( tokens, null, 2 ) + '\n' );

const elevationSource = [ 1, 2, 3, 4 ].map( level => `.l${ level } { @include mixins.newspack-ui-elevation(${ level }); }` ).join( '\n' );
const elevationCss = sass.compileString( `@use "mixins";\n${ elevationSource }`, {
	loadPaths: [ join( src, 'scss' ) ],
	importers: [ motionImporter ],
	style: 'expanded',
	quietDeps: true,
} ).css;
const elevation = {};
postcss.parse( elevationCss ).walkDecls( 'box-shadow', decl => {
	elevation[ decl.parent.selector.replace( '.l', '' ) ] = decl.value.replace( /\s+/g, ' ' ).trim();
} );
writeFileSync( join( dist, 'elevation.json' ), JSON.stringify( elevation, null, 2 ) + '\n' );
writeFileSync( join( dist, 'colors.json' ), JSON.stringify( colors, null, 2 ) + '\n' );
console.log( `newspack-ui: ${ Object.keys( base ).length } tokens` );
