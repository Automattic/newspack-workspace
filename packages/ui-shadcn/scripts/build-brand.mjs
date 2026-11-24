import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse } from 'culori';
import postcss from 'postcss';
import { accentTokens, blueChart, blueContrast, blueShadcn, blueTokens, blueUnsupported, brandBase, brandChart, brandFontFiles, brandFonts, brandShadcn, brandTokens, brandTypeTokens } from './brand.mjs';
import { formatOklch } from './oklch.mjs';
import { writeBrandReport } from './brand-report.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const dist = process.env.BRAND_DIST ? resolve( process.env.BRAND_DIST ) : join( root, 'dist' );
const registryDir = join( dist, 'registry' );
const readJson = path => JSON.parse( readFileSync( path, 'utf8' ) );
const writeJson = ( path, data ) => writeFileSync( path, JSON.stringify( data, null, '\t' ) + '\n' );

const neutral = readJson( join( registryDir, 'newspack-ui-neutral.json' ) );
const brand = structuredClone( neutral );

const isDark = selector => selector.startsWith( '.dark' );
for ( const key of Object.keys( brand.css ) ) {
	if ( isDark( key ) ) {
		delete brand.css[ key ];
	} else if ( key.startsWith( '@media' ) ) {
		for ( const selector of Object.keys( brand.css[ key ] ) ) {
			if ( isDark( selector ) ) {
				delete brand.css[ key ][ selector ];
			}
		}
		if ( ! Object.keys( brand.css[ key ] ).length ) {
			delete brand.css[ key ];
		}
	}
}
delete brand.cssVars.dark;

const oklch = hex => formatOklch( parse( hex ) );
const shadcn = Object.fromEntries( Object.entries( brandShadcn ).map( ( [ name, [ hex ] ] ) => [ name, oklch( hex ) ] ) );
brandChart.forEach( ( hex, index ) => {
	shadcn[ `chart-${ index + 1 }` ] = oklch( hex );
} );
const tokenValues = group => Object.fromEntries( Object.entries( group ).map( ( [ name, [ value ] ] ) => [ name, value ] ) );
const varName = name => ( name === 'color-scheme' ? name : `--${ name }` );
const prefix = vars => Object.fromEntries( Object.entries( vars ).map( ( [ name, value ] ) => [ varName( name ), value ] ) );

Object.assign( brand.cssVars.light, shadcn );
Object.assign( brand.cssVars.theme, tokenValues( brandFonts ) );
Object.assign( brand.css[ ':root' ], prefix( shadcn ), prefix( tokenValues( brandTokens ) ), prefix( tokenValues( accentTokens ) ), prefix( tokenValues( brandFonts ) ), prefix( tokenValues( brandTypeTokens ) ) );
brand.css[ '@layer base' ] = brandBase;

brand.name = 'newspack-brand';
brand.title = 'Newspack brand';
const shadcnNames = Object.keys( neutral.cssVars.light );
const neutralDark = neutral.css[ '.dark' ];
const blueShadcnValues = Object.fromEntries( shadcnNames.map( name => [ name, neutralDark[ `--${ name }` ] ] ) );
Object.assign( blueShadcnValues, tokenValues( blueShadcn ) );
blueChart.forEach( ( [ value ], index ) => {
	blueShadcnValues[ `chart-${ index + 1 }` ] = value;
} );
for ( const name of shadcnNames ) {
	if ( ! blueShadcnValues[ name ] ) {
		throw new Error( `No blue value for --${ name }` );
	}
}
const blue = { ...prefix( tokenValues( blueTokens ) ), ...prefix( blueShadcnValues ) };
const blueHighContrast = prefix( tokenValues( blueContrast ) );
const contrastQuery = '@media (prefers-contrast: more)';
const blueSelectors = [ '.dark', '.newspack-brand-inverse' ];

brand.description = 'Newspack brand theme, light, with blue sections. For newspack.com and anything under space.fast.';
brand.docs = `The page is always light, and body gets the surface, text color and Inter. On a site built with the site kit, set DARK_MODE = false in site.ts, or the theme script puts .dark on html and the whole page turns blue; a deck has no site.ts or theme script and needs nothing. A section with the .newspack-brand-inverse or .dark class is blue: a Cobalt surface with white text, white buttons and a white focus ring; the two classes are equivalent, and dark: utilities apply inside a .dark section. h1 and .font-display are American Grotesk Compressed Black, uppercase and Cobalt; h2 is Compressed Black in sentence case and the text color; h3 to h5 are American Grotesk Medium. .newspack-brand-card-title gives a heading inside a Card the h5 size, so it sits below the section's h2 while keeping its level. Without the newspack-brand-fonts item they fall back to Inter 900 and 500. Not covered: ${ blueUnsupported.join( ' ' ) }`;

const fontsUrl = 'https://newspack-ui-theme.space.fast/fonts/american-grotesk/';
const fontFileNames = brandFontFiles.map( ( [ , , , file ] ) => file );
const fontFileList = `${ fontFileNames.slice( 0, -1 ).join( ', ' ) } and ${ fontFileNames.at( -1 ) }`;
const downloadCommand = `curl -fsSL --create-dirs -o 'public/fonts/american-grotesk/#1.woff2' '${ fontsUrl }{${ fontFileNames.map( file => file.replace( /\.woff2$/, '' ) ).join( ',' ) }}.woff2'`;
const fontsCss = brandFontFiles.map( ( [ family, weight, style, file ] ) => `@font-face {\n\tfont-family: "${ family }";\n\tfont-style: ${ style };\n\tfont-weight: ${ weight };\n\tfont-display: swap;\n\tsrc: url("/fonts/american-grotesk/${ file }") format("woff2");\n}\n` ).join( '\n' );
const fonts = {
	$schema: neutral.$schema,
	name: 'newspack-brand-fonts',
	type: 'registry:item',
	title: 'Newspack brand fonts',
	description: `American Grotesk @font-face rules for the Newspack brand theme. For newspack.com and anything under space.fast only. The five woff2 files are at ${ fontsUrl }; the docs give the download command.`,
	docs: `For newspack.com and anything under space.fast only. Download the five American Grotesk files (${ fontFileList }) from ${ fontsUrl } into this site's public/fonts/american-grotesk/, from the project root: ${ downloadCommand } Then import styles/newspack-brand-fonts.css once, before the theme. Without the fonts the brand theme falls back to Inter.`,
	files: [ { path: 'registry/styles/newspack-brand-fonts.css', type: 'registry:file', target: 'styles/newspack-brand-fonts.css', content: fontsCss } ],
};
writeJson( join( registryDir, `${ fonts.name }.json` ), fonts );

const index = readJson( join( registryDir, 'registry.json' ) );
index.items = index.items.filter( item => item.name !== brand.name && item.name !== fonts.name );
const brandAt = index.items.findIndex( item => item.name === neutral.name ) + 1;
index.items.splice( brandAt, 0, { name: brand.name, type: brand.type, title: brand.title, description: brand.description } );
index.items.splice( brandAt + 1, 0, { name: fonts.name, type: fonts.type, title: fonts.title, description: fonts.description } );
writeJson( join( registryDir, 'registry.json' ), index );

const block = ( selector, decls ) => `${ selector } {\n${ Object.entries( decls ).map( ( [ k, v ] ) => `\t${ k }: ${ v };` ).join( '\n' ) }\n}\n`;
const overrides = { ...prefix( shadcn ), ...prefix( tokenValues( brandTokens ) ), ...prefix( tokenValues( accentTokens ) ), ...prefix( tokenValues( brandFonts ) ), ...prefix( tokenValues( brandTypeTokens ) ) };
const themeCss = postcss.parse( readFileSync( join( dist, 'theme.css' ), 'utf8' ) );
themeCss.walkRules( rule => {
	if ( isDark( rule.selector ) ) {
		rule.remove();
		return;
	}
	if ( rule.parent.type === 'root' && rule.selector === ':root' ) {
		const seen = new Set();
		rule.walkDecls( decl => {
			if ( decl.prop in overrides ) {
				decl.value = overrides[ decl.prop ];
				seen.add( decl.prop );
			}
		} );
		for ( const [ prop, value ] of Object.entries( overrides ) ) {
			if ( ! seen.has( prop ) ) {
				rule.append( { prop, value } );
			}
		}
	}
} );
themeCss.walkAtRules( 'media', atRule => {
	if ( ! atRule.nodes?.length ) {
		atRule.remove();
	}
} );
const rootContrast = brand.css[ contrastQuery ];
delete brand.css[ contrastQuery ];
for ( const selector of blueSelectors ) {
	brand.css[ selector ] = { ...blue };
	rootContrast[ selector ] = { ...blueHighContrast };
}
brand.css[ contrastQuery ] = rootContrast;
writeJson( join( registryDir, 'newspack-brand.json' ), brand );
const baseCss = `@layer base {\n${ Object.entries( brandBase ).map( ( [ selector, decls ] ) => block( selector, decls ).replace( /^/gm, '\t' ).replace( /\t$/, '' ) ).join( '\n' ) }}\n`;
const blueCss = blueSelectors.map( selector => block( selector, blue ) ).join( '\n' );
const blueContrastCss = `${ contrastQuery } {\n${ blueSelectors.map( selector => block( selector, blueHighContrast ).replace( /^/gm, '\t' ).replace( /\t$/, '' ) ).join( '\n' ) }}\n`;
const brandCss = `@custom-variant dark (&:where(.dark, .dark *));\n\n${ themeCss.toString().trim().replace( /\n{3,}/g, '\n\n' ) }\n\n${ baseCss }\n${ blueCss }\n${ blueContrastCss }`;
writeFileSync( join( dist, 'brand.css' ), brandCss );

const entry = ( variable, value, note ) => ( { variable, value, note } );
const mappingPath = join( dist, 'mapping.json' );
const mapping = readJson( mappingPath );
mapping.brand = {
	entries: [
		...Object.entries( brandShadcn ).map( ( [ name, [ , note ] ] ) => entry( `--${ name }`, shadcn[ name ], note ) ),
		...brandChart.map( ( hex, index ) => entry( `--chart-${ index + 1 }`, shadcn[ `chart-${ index + 1 }` ], `Brand chart series ${ index + 1 }: ${ hex }.` ) ),
		...Object.entries( brandTokens ).map( ( [ name, [ value, note ] ] ) => entry( `--${ name }`, value, note ) ),
	],
	blue: [
		...Object.entries( blueTokens ).map( ( [ name, [ value, note ] ] ) => entry( varName( name ), value, note ) ),
		...shadcnNames.map( name => entry( `--${ name }`, blueShadcnValues[ name ], blueShadcn[ name ]?.[ 1 ] ?? ( name.startsWith( 'chart-' ) ? `Blue chart series ${ name.slice( 6 ) }: ${ blueChart[ Number( name.slice( 6 ) ) - 1 ][ 1 ] }` : 'The token Neutral\'s dark block points it at.' ) ) ),
	],
	blueContrast: Object.entries( blueContrast ).map( ( [ name, [ value, note ] ] ) => entry( varName( name ), value, note ) ),
	unsupported: blueUnsupported,
	type: [
		...Object.entries( brandFonts ).map( ( [ name, [ value, note ] ] ) => entry( `--${ name }`, value, note ) ),
		...Object.entries( brandTypeTokens ).map( ( [ name, [ value, note ] ] ) => entry( `--${ name }`, value, note ) ),
	],
	accents: Object.entries( accentTokens ).map( ( [ name, [ value, note ] ] ) => entry( `--${ name }`, value, note ) ),
};
writeJson( mappingPath, mapping );

console.log( `newspack-ui-shadcn: brand theme written, ${ mapping.brand.entries.length } overrides, ${ mapping.brand.accents.length } accents` );

writeBrandReport( dist );
