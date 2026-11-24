import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { converter, formatHex, interpolate, parse } from 'culori';
import { chartOrder, chartSeries, chartSource, fontStack, gaps, noToken, radiusScale, sources } from './mapping.mjs';
import { darkCss, darkRule, darkTokens } from './dark.mjs';
import { items, siteBundles, siteKit } from '../registry/items.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const dist = join( root, 'dist' );
const tokensPath = join( root, 'build', 'newspack-ui', 'tokens.json' );
const tokenFile = JSON.parse( readFileSync( tokensPath, 'utf8' ) );
const tokens = tokenFile.properties;
const elevation = JSON.parse( readFileSync( join( root, 'build', 'newspack-ui', 'elevation.json' ), 'utf8' ) );
const newspackColors = JSON.parse( readFileSync( join( root, 'build', 'newspack-ui', 'colors.json' ), 'utf8' ) );
const registryBase = ( process.env.REGISTRY_BASE || 'https://newspack-ui-theme.space.fast' ).replace( /\/+$/, '' ).replace( /\/r$/, '' );

const toOklch = converter( 'oklch' );

function resolveColor( name, source = tokens ) {
	const token = source[ name ];
	if ( ! token ) {
		throw new Error( `Missing token ${ name }` );
	}
	const value = token.resolved.trim();
	const mix = value.match( /^color-mix\(in oklab, (\S+) ([\d.]+)%, (\S+)\)$/ );
	if ( mix ) {
		const [ , a, percent, b ] = mix;
		return interpolate( [ parse( a ), parse( b ) ], 'oklab' )( 1 - Number( percent ) / 100 );
	}
	const color = parse( value );
	if ( ! color ) {
		throw new Error( `Cannot parse ${ name }: ${ value }` );
	}
	return color;
}

function formatOklch( color ) {
	const { l, c, h, alpha } = toOklch( color );
	const round = ( n, d ) => Number( n.toFixed( d ) );
	const chroma = round( c ?? 0, 4 );
	const hue = chroma ? round( h ?? 0, 2 ) : 0;
	const a = alpha === undefined || alpha >= 1 ? '' : ` / ${ round( alpha, 3 ) }`;
	return `oklch(${ round( l, 4 ) } ${ chroma } ${ hue }${ a })`;
}

function resolveLength( name, add = 0 ) {
	const value = tokens[ name ]?.resolved;
	const match = value?.match( /^(\d+(?:\.\d+)?)px$/ );
	if ( ! match ) {
		throw new Error( `Cannot read length from ${ name }: ${ value }` );
	}
	return `${ Number( match[ 1 ] ) + add }px`;
}

const entries = [];
const light = {};

for ( const [ variable, [ token, note ] ] of Object.entries( sources ) ) {
	const color = resolveColor( token );
	const value = formatOklch( color );
	light[ variable ] = value;
	entries.push( {
		variable: `--${ variable }`,
		value,
		hex: formatHex( color ),
		token,
		tokenValue: tokens[ token ].resolved,
		note,
	} );
}

const colorStep = name => {
	if ( ! newspackColors[ name ] ) {
		throw new Error( `Missing newspack-colors value ${ name }` );
	}
	return newspackColors[ name ];
};

const series = chartSeries.map( ( [ source, note ], index ) => {
	const fromColors = ! source.startsWith( '#' );
	const hex = fromColors ? newspackColors[ source ] : source;
	if ( ! hex ) {
		throw new Error( `Missing newspack-colors value ${ source }` );
	}
	return { index, hex, token: fromColors ? `newspack-colors $${ source }` : null, note: `Insights series ${ index }. ${ note }` };
} );
const chartVars = {};
chartOrder.forEach( ( seriesIndex, position ) => {
	const item = series[ seriesIndex ];
	const value = formatOklch( parse( item.hex ) );
	light[ `chart-${ position + 1 }` ] = value;
	entries.push( { variable: `--chart-${ position + 1 }`, value, hex: formatHex( parse( item.hex ) ), token: item.token, tokenValue: item.hex, source: `Insights series ${ seriesIndex }`, note: item.note } );
} );
for ( const item of series ) {
	chartVars[ `newspack-ui-chart-${ item.index }` ] = item.hex;
	entries.push( { variable: `--newspack-ui-chart-${ item.index }`, value: item.hex, hex: item.hex, token: item.token, tokenValue: item.hex, source: `Insights series ${ item.index }`, note: item.note } );
}

const radiusVars = { radius: 'var(--newspack-ui-radius-surface)' };
entries.push( {
	variable: '--radius',
	value: tokens[ '--newspack-ui-radius-surface' ].resolved,
	token: '--newspack-ui-radius-surface',
	tokenValue: tokens[ '--newspack-ui-radius-surface' ].resolved,
	note: 'The surface radius. Stock shadcn components that calculate from --radius stay sensible; the scale below no longer derives from it.',
} );
const radiusTheme = {};
for ( const [ step, [ token, note ] ] of Object.entries( radiusScale ) ) {
	const resolved = token.startsWith( '--' ) ? tokens[ token ].resolved : token;
	radiusTheme[ `radius-${ step }` ] = token.startsWith( '--' ) ? `var(${ token })` : token;
	entries.push( { variable: `--radius-${ step }`, value: resolved, token: token.startsWith( '--' ) ? token : null, tokenValue: resolved, note } );
}

const interStack = '"Inter", system-ui, sans-serif';
if ( ! tokens[ '--newspack-ui-font-family-body' ].resolved.includes( interStack ) ) {
	throw new Error( `--newspack-ui-font-family-body no longer contains ${ interStack }` );
}
const fontSans = tokens[ '--newspack-ui-font-family-body' ].resolved.replace( interStack, fontStack );
const fontMono = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
entries.push(
	{
		variable: '--font-sans',
		value: fontSans,
		token: '--newspack-ui-font-family-body',
		tokenValue: tokens[ '--newspack-ui-font-family-body' ].resolved,
		note: 'Body font family. The newspack-ui token names Inter; the stack here also names Inter Variable, the family @fontsource-variable/inter registers, then system fonts. The theme item installs that package and imports it.',
	},
	{
		variable: '--font-mono',
		value: fontMono,
		token: null,
		tokenValue: null,
		note: 'Gap: newspack-ui has no monospace family.',
	}
);

const prefix = vars => Object.fromEntries( Object.entries( vars ).map( ( [ k, v ] ) => [ `--${ k }`, v ] ) );
const css = ( vars ) => Object.entries( vars ).map( ( [ k, v ] ) => `\t--${ k }: ${ v };` ).join( '\n' );

const tokenVars = Object.fromEntries( Object.entries( tokens ).map( ( [ name, token ] ) => [ name.slice( 2 ), token.resolved.replace( interStack, fontStack ) ] ) );
const elevationVars = Object.fromEntries( Object.entries( elevation ).map( ( [ level, value ] ) => [ `nui-elevation-${ level }`, value ] ) );
const mediaBlocks = Object.entries( tokenFile.media ?? {} ).map( ( [ query, props ] ) => [
	query,
	Object.fromEntries( Object.entries( props ).map( ( [ name, token ] ) => [ name.slice( 2 ), token.resolved ] ) ),
] );

const brandLight = {
	'newspack-ui-color-btn-brand': [ 'var(--newspack-ui-color-primary-60)', 'Fill of the Button brand variant: newspack-colors primary-600, the Newspack blue.' ],
	'newspack-ui-color-btn-brand-hover': [ 'color-mix(in oklab, var(--newspack-ui-color-btn-brand) var(--newspack-ui-color-mix-hover), var(--newspack-ui-color-surface))', 'Brand button hover: the fill mixed 85% toward the surface, the model the Neutral button and the block theme use. Dark mixes toward its own surface with the same 85%.' ],
	'newspack-ui-color-btn-brand-pressed': [ 'color-mix(in oklab, var(--newspack-ui-color-btn-brand) var(--newspack-ui-color-mix-pressed), var(--newspack-ui-color-surface))', 'Brand button pressed: the fill mixed 75% toward the surface, in light and dark.' ],
	'newspack-ui-color-btn-brand-on': [ 'var(--newspack-ui-color-neutral-0)', 'Text on the brand button: white in light, the dark page ink in dark.' ],
	'newspack-ui-color-brand-text': [ 'var(--newspack-ui-color-primary-60)', 'Brand blue for text, marks and accents: newspack-colors primary-600 (#003da5) in light, the dark primary-30 step (#95bdff) in dark.' ],
	'newspack-ui-color-btn-brand-text': [ 'var(--newspack-ui-color-brand-text)', 'Text of the brand-outline variant: the brand text blue.' ],
};
const brandDark = {
	'newspack-ui-color-btn-brand': [ 'var(--newspack-ui-color-primary-30)', 'Dark fill: the dark primary-30 step (#95bdff), the APCA mirror of the light brand and the blue the brand text uses in dark. The #1e1e1e text on it is Lc 65 at rest.' ],
	'newspack-ui-color-btn-brand-hover': [ 'color-mix(in oklab, var(--newspack-ui-color-btn-brand) var(--newspack-ui-color-mix-hover), var(--newspack-ui-color-surface))', 'Dark hover: the fill mixed 85% toward the dark surface, the light recipe. Restated here so it resolves against the dark fill and surface wherever .dark is set. The #1e1e1e text is Lc 51.5 on it, below 60 by decision.' ],
	'newspack-ui-color-btn-brand-pressed': [ 'color-mix(in oklab, var(--newspack-ui-color-btn-brand) var(--newspack-ui-color-mix-pressed), var(--newspack-ui-color-surface))', 'Dark pressed: the fill mixed 75% toward the dark surface, the light recipe. The #1e1e1e text is Lc 42.6 on it, below 60 by decision.' ],
	'newspack-ui-color-btn-brand-on': [ 'var(--newspack-ui-color-neutral-0)', 'Dark text on the brand fill: the dark page ink, #1e1e1e.' ],
	'newspack-ui-color-brand-text': [ 'var(--newspack-ui-color-primary-30)', 'Dark brand text blue: the dark primary-30 step (#95bdff), the same blue as the brand button fill.' ],
	'newspack-ui-color-btn-brand-text': [ colorStep( 'primary-050' ), 'Dark brand-outline text: newspack-colors primary-050 (#bfcfe9). The brand text blue (#95bdff) is Lc 64, 62 and 59 on the surface and the hover and pressed fills, so it misses Lc 60 on pressed; #bfcfe9 is 75, 73 and 70.' ],
};
const brandPick = vars => Object.fromEntries( Object.entries( vars ).map( ( [ name, [ value ] ] ) => [ name, value ] ) );
for ( const [ name, [ value, note ] ] of Object.entries( brandLight ) ) {
	const dark = brandDark[ name ]?.[ 0 ];
	entries.push( { variable: `--${ name }`, value, token: null, tokenValue: dark ? `${ value } (dark: ${ dark })` : value, note } );
}

const hasDark = Object.keys( darkTokens ).length > 0;
const darkVars = {};
const darkScopes = [];
if ( hasDark ) {
	const chartDark = Object.fromEntries( chartOrder.map( ( seriesIndex, position ) => [ `--chart-${ position + 1 }`, `var(--newspack-ui-chart-${ seriesIndex })` ] ) );
	for ( const [ variable, [ token ] ] of Object.entries( sources ) ) {
		if ( ! ( token in darkTokens ) && ! token.includes( 'chart' ) ) {
			console.warn( `newspack-ui-shadcn: ${ token } has no dark value, ${ variable } resolves through its light literal` );
		}
	}
	Object.assign(
		darkVars,
		{ 'color-scheme': 'dark' },
		darkTokens,
		Object.fromEntries( Object.entries( elevationVars ).map( ( [ k, v ] ) => [ `--${ k }`, v ] ) ),
		Object.fromEntries( Object.entries( sources ).map( ( [ variable, [ token ] ] ) => [ `--${ variable }`, `var(${ token })` ] ) ),
		chartDark,
		prefix( brandPick( brandDark ) )
	);
	const postcss = createRequire( import.meta.url )( 'postcss' );
	const scopeMap = [ [ '__dropdown__content', '.dark [data-slot="select-content"]' ], [ '__modal', '.dark [data-slot="dialog-content"]' ] ];
	postcss.parse( darkCss ).walkRules( rule => {
		if ( ! rule.selector.includes( 'data-color-scheme="dark"' ) ) {
			return;
		}
		const media = rule.parent.type === 'atrule' ? `@media ${ rule.parent.params }` : null;
		const scope = scopeMap.find( ( [ match ] ) => rule.selector.includes( match ) );
		const selector = scope ? scope[ 1 ] : '.dark';
		if ( ! scope && ! media ) {
			return;
		}
		const decls = {};
		rule.walkDecls( decl => {
			decls[ decl.prop ] = decl.value;
		} );
		darkScopes.push( { media, selector, decls } );
	} );
}

const shadcnVars = { ...light, ...radiusVars };
const extraVars = { ...tokenVars, ...chartVars, ...elevationVars, ...brandPick( brandLight ) };
const theme = { 'font-sans': 'var(--newspack-ui-font-family-body)', 'font-mono': fontMono, ...radiusTheme };

let themeCss = `:root {\n${ css( { 'font-sans': fontSans, 'font-mono': fontMono, ...radiusTheme, ...shadcnVars, ...extraVars } ) }\n}\n`;
for ( const [ query, props ] of mediaBlocks ) {
	themeCss += `\n@media ${ query } {\n\t:root {\n${ css( props ).replace( /^/gm, '\t' ) }\n\t}\n}\n`;
}
const block = ( selector, decls ) => `${ selector } {\n${ Object.entries( decls ).map( ( [ k, v ] ) => `\t${ k }: ${ v };` ).join( '\n' ) }\n}\n`;
if ( hasDark ) {
	themeCss += `\n${ block( '.dark', darkVars ) }`;
	for ( const scope of darkScopes.filter( item => ! item.media ) ) {
		themeCss += `\n${ block( scope.selector, scope.decls ) }`;
	}
	for ( const scope of darkScopes.filter( item => item.media ) ) {
		themeCss += `\n${ scope.media } {\n${ block( scope.selector, scope.decls ).replace( /^/gm, '\t' ).trimEnd() }\n}\n`;
	}
}

rmSync( dist, { recursive: true, force: true } );
mkdirSync( join( dist, 'registry' ), { recursive: true } );
writeFileSync( join( dist, 'theme.css' ), themeCss );
if ( darkCss ) {
	writeFileSync( join( dist, 'newspack-ui-dark.css' ), darkCss );
}

const mediaCss = Object.fromEntries( mediaBlocks.map( ( [ query, props ] ) => [ `@media ${ query }`, { ':root': prefix( props ) } ] ) );
for ( const scope of darkScopes.filter( item => item.media ) ) {
	mediaCss[ scope.media ] = { ...( mediaCss[ scope.media ] ?? {} ), [ scope.selector ]: scope.decls };
}

const schema = 'https://ui.shadcn.com/schema/registry-item.json';
const themeItem = {
	$schema: schema,
	name: 'newspack-ui-neutral',
	type: 'registry:theme',
	title: 'newspack-ui Neutral',
	description: 'shadcn variables and every newspack-ui token, from the Neutral style.',
	dependencies: [ '@fontsource-variable/inter', 'tw-animate-css' ],
	cssVars: { theme, light },
	css: {
		'@import "@fontsource-variable/inter"': {},
		'@import "tw-animate-css"': {},
		'@custom-variant dark (&:where(.dark, .dark *))': {},
		':root': prefix( { ...shadcnVars, ...extraVars } ),
		...( hasDark ? { '.dark': darkVars } : {} ),
		...Object.fromEntries( darkScopes.filter( item => ! item.media ).map( item => [ item.selector, item.decls ] ) ),
		...mediaCss,
	},
};
const write = ( name, data ) => writeFileSync( join( dist, 'registry', `${ name }.json` ), JSON.stringify( data, null, '\t' ) + '\n' );
write( 'newspack-ui-neutral', themeItem );

const url = name => `${ registryBase }/r/${ name }.json`;
const indexItems = [ { name: 'newspack-ui-neutral', type: themeItem.type, title: themeItem.title, description: themeItem.description } ];
const lineCounts = {};
const fileLines = {};
for ( const item of items ) {
	const files = item.files.map( file => {
		const content = readFileSync( join( root, 'registry', file ), 'utf8' );
		return { path: `registry/${ file }`, type: item.type, content };
	} );
	fileLines[ item.name ] = files.reduce( ( sum, file ) => sum + file.content.split( '\n' ).length - 1, 0 );
	lineCounts[ item.name ] = files.reduce( ( sum, file ) => sum + file.content.split( '\n' ).filter( line => /--newspack-ui|--nui|--btn|--alert|--badge|--tab-/.test( line ) ).length, 0 );
	const entry = {
		$schema: schema,
		name: item.name,
		type: item.type,
		title: item.title,
		description: item.description,
		...( item.dependencies?.length ? { dependencies: item.dependencies } : {} ),
		...( item.css ? { css: item.css } : {} ),
		...( item.docs ? { docs: item.docs } : {} ),
		registryDependencies: ( item.registryDependencies ?? [] ).map( url ),
		files,
	};
	if ( ! entry.registryDependencies.length ) {
		delete entry.registryDependencies;
	}
	write( item.name, entry );
	indexItems.push( { name: item.name, type: item.type, title: item.title, description: item.description, files: item.files.map( file => ( { path: `registry/${ file }`, type: item.type } ) ) } );
}

const bundle = {
	$schema: schema,
	name: 'newspack-ui',
	type: 'registry:item',
	title: 'newspack-ui',
	description: 'The newspack-ui theme and every component in it.',
	registryDependencies: [ 'newspack-ui-neutral', ...items.filter( item => item.type === 'registry:ui' ).map( item => item.name ) ].map( url ),
};
write( 'newspack-ui', bundle );
indexItems.push( { name: 'newspack-ui', type: bundle.type, title: bundle.title, description: bundle.description, registryDependencies: bundle.registryDependencies } );

const kitDir = join( dist, 'site-kit' );
mkdirSync( kitDir, { recursive: true } );
const kitIndex = [];
for ( const item of siteKit ) {
	const files = ( item.files ?? [] ).map( file => {
		const content = readFileSync( join( root, 'registry', file.path ), 'utf8' );
		if ( file.path.endsWith( '.css' ) && /\/\*/.test( content ) ) {
			throw new Error( `${ file.path }: registry stylesheets carry no comments` );
		}
		return {
			path: `registry/${ file.path }`,
			type: file.type,
			...( file.target ? { target: file.target } : {} ),
			content,
		};
	} );
	const entry = {
		$schema: schema,
		name: item.name,
		type: item.type,
		title: item.title,
		description: item.description,
		...( item.dependencies?.length ? { dependencies: item.dependencies } : {} ),
		...( item.registryDependencies?.length ? { registryDependencies: item.registryDependencies.map( url ) } : {} ),
		...( item.cssVars ? { cssVars: item.cssVars } : {} ),
		...( files.length ? { files } : {} ),
	};
	writeFileSync( join( kitDir, `${ item.name }.json` ), JSON.stringify( entry, null, '\t' ) + '\n' );
	kitIndex.push( { ...entry, $schema: undefined, files: files.map( ( { content, ...file } ) => file ) } );
}
for ( const bundleItem of siteBundles ) {
	const entry = { $schema: schema, name: bundleItem.name, type: 'registry:item', title: bundleItem.title, description: bundleItem.description, registryDependencies: bundleItem.registryDependencies.map( url ) };
	writeFileSync( join( kitDir, `${ bundleItem.name }.json` ), JSON.stringify( entry, null, '\t' ) + '\n' );
	kitIndex.push( { ...entry, $schema: undefined } );
}
writeFileSync(
	join( kitDir, 'registry.json' ),
	JSON.stringify( { $schema: 'https://ui.shadcn.com/schema/registry.json', name: 'newspack-ui-site-kit', homepage: registryBase, items: kitIndex }, null, '\t' ) + '\n'
);

writeFileSync(
	join( dist, 'registry', 'registry.json' ),
	JSON.stringify( { $schema: 'https://ui.shadcn.com/schema/registry.json', name: 'newspack-ui', homepage: registryBase, items: indexItems }, null, '\t' ) + '\n'
);

writeFileSync(
	join( dist, 'mapping.json' ),
	JSON.stringify(
		{
			source: 'newspack-ui/tokens.json',
			registryBase,
			style: 'neutral',
			dark: hasDark ? { source: 'dark-palette', rule: darkRule, tokens: Object.keys( darkTokens ).length } : null,
			tokenCount: Object.keys( tokenVars ).length,
			extraTokens: Object.keys( brandLight ),
			mediaTokenCount: mediaBlocks.reduce( ( sum, [ , props ] ) => sum + Object.keys( props ).length, 0 ),
			entries,
			gaps,
			noToken,
			chartSource,
			componentNames: items.filter( item => item.type === 'registry:ui' ).map( item => item.name ),
			lineCounts,
			fileLines,
		},
		null,
		'\t'
	) + '\n'
);

console.log( `newspack-ui-shadcn: ${ entries.length } variables, ${ Object.keys( tokenVars ).length } tokens, ${ items.length } registry items written to ${ dist }, ${ siteKit.length + siteBundles.length } site kit items to ${ kitDir }` );
