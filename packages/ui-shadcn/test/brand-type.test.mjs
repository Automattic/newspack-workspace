import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const registry = join( root, 'dist', 'registry' );
const read = path => JSON.parse( readFileSync( path, 'utf8' ) );
const brand = read( join( registry, 'newspack-brand.json' ) );
const fonts = read( join( registry, 'newspack-brand-fonts.json' ) );
const base = brand.css[ '@layer base' ];
const inter = '"Inter Variable", "Inter", ui-sans-serif, system-ui, sans-serif';

test( 'defines the heading and display font stacks', () => {
	const heading = `"American Grotesk", ${ inter }`;
	const display = `"American Grotesk Compressed", ${ inter }`;
	assert.equal( brand.cssVars.theme[ 'font-heading' ], heading );
	assert.equal( brand.cssVars.theme[ 'font-display' ], display );
	assert.equal( brand.css[ ':root' ][ '--font-heading' ], heading );
	assert.equal( brand.css[ ':root' ][ '--font-display' ], display );
} );

test( 'h1 is Cobalt Compressed Black uppercase', () => {
	assert.equal( base.h1[ 'font-family' ], 'var(--font-display)' );
	assert.equal( base.h1[ 'font-weight' ], '900' );
	assert.equal( base.h1[ 'text-transform' ], 'uppercase' );
	assert.equal( base.h1.color, 'var(--newspack-ui-color-brand-text)' );
	assert.equal( base.h1[ 'font-size' ], 'var(--newspack-ui-brand-h1-size)' );
	assert.equal( base.h1[ 'line-height' ], 'var(--newspack-ui-brand-h1-line-height)' );
	assert.equal( base[ '.newspack-brand-inverse h1, .newspack-brand-inverse .font-display' ].color, 'var(--newspack-ui-color-text)' );
} );

test( 'h2 to h5 are Medium sentence case in the text color', () => {
	const rule = base[ 'h2, h3, h4, h5' ];
	assert.equal( rule[ 'font-family' ], 'var(--font-heading)' );
	assert.equal( rule[ 'font-weight' ], '500' );
	assert.equal( rule[ 'text-transform' ], undefined );
	assert.equal( rule.color, 'var(--newspack-ui-color-text)' );
	for ( const level of [ 2, 3, 4, 5 ] ) {
		assert.equal( base[ `h${ level }` ][ 'font-size' ], `var(--newspack-ui-brand-h${ level }-size)` );
		assert.equal( base[ `h${ level }` ][ 'line-height' ], `var(--newspack-ui-brand-h${ level }-line-height)` );
	}
} );

test( 'unclassed anchors are Cobalt and underlined', () => {
	const link = base[ 'a:where(:not([class], [data-card]))' ];
	assert.equal( link.color, 'var(--newspack-ui-color-link)' );
	assert.equal( link[ 'text-decoration-line' ], 'underline' );
	assert.equal( link[ 'text-underline-offset' ], '3px' );
	assert.match( link[ 'text-decoration-color' ], /^color-mix\(in oklab, / );
	assert.equal( base[ 'a:where(:not([class], [data-card])):hover' ][ 'text-decoration-color' ], 'var(--newspack-ui-color-link)' );
} );

test( 'the anchor rule keeps the type selector so it beats preflight by order', () => {
	const selector = Object.keys( base ).find( key => key.startsWith( 'a:where(' ) && ! key.endsWith( ':hover' ) );
	assert.ok( selector, 'an anchor rule exists in base' );
	assert.ok( ! selector.startsWith( ':where(' ) );
	const typeSelectors = selector.replace( /:where\([^)]*(\([^)]*\)[^)]*)*\)/g, '' ).match( /^[a-z][a-z0-9]*/g ) ?? [];
	assert.ok( typeSelectors.length >= 1, 'specificity is at least (0,0,1)' );
} );

test( 'the inverse surface paints in the base layer so utilities can override it', () => {
	assert.deepEqual( base[ '.newspack-brand-inverse' ], { 'background-color': 'var(--newspack-ui-color-surface)', color: 'var(--newspack-ui-color-text)' } );
	assert.ok( ! ( 'background-color' in brand.css[ '.newspack-brand-inverse' ] ) );
	assert.ok( ! ( 'color' in brand.css[ '.newspack-brand-inverse' ] ) );
	const css = readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' );
	const unlayered = css.slice( css.lastIndexOf( '\n.newspack-brand-inverse {' ) );
	assert.ok( ! /\n\s*(background-color|color):/.test( unlayered ) );
} );

test( 'display and eyebrow styles exist', () => {
	assert.equal( base[ '.font-display' ][ 'font-family' ], 'var(--font-display)' );
	assert.equal( base[ '.font-display' ][ 'text-transform' ], 'uppercase' );
	assert.equal( base[ '.font-display' ][ 'font-weight' ], '900' );
	assert.equal( base[ '.font-display' ][ 'line-height' ], 'var(--newspack-ui-brand-h1-line-height)' );
	assert.equal( base[ '.font-display' ].color, 'var(--newspack-ui-color-brand-text)' );
	const eyebrow = base[ '.newspack-brand-eyebrow' ];
	assert.equal( eyebrow[ 'font-family' ], 'var(--font-sans)' );
	assert.equal( eyebrow[ 'font-weight' ], '600' );
	assert.equal( eyebrow[ 'text-transform' ], 'uppercase' );
	assert.equal( eyebrow[ 'line-height' ], 'var(--newspack-ui-line-height-small)' );
	assert.equal( eyebrow[ 'font-size' ], 'var(--newspack-ui-font-size-small)' );
} );

test( 'brand.css carries the type rules', () => {
	const css = readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' );
	assert.match( css, /@layer base \{/ );
	assert.match( css, /--font-display: "American Grotesk Compressed"/ );
	assert.match( css, /\.newspack-brand-inverse h1, \.newspack-brand-inverse \.font-display \{/ );
} );

test( 'the fonts item ships one stylesheet file with five @font-face rules', () => {
	assert.equal( fonts.css, undefined );
	assert.equal( fonts.cssVars, undefined );
	assert.deepEqual( Object.keys( fonts ).sort(), [ '$schema', 'description', 'docs', 'files', 'name', 'title', 'type' ] );
	assert.equal( fonts.name, 'newspack-brand-fonts' );
	assert.equal( fonts.type, 'registry:item' );
	assert.match( fonts.docs, /Automattic domains only/ );
	assert.match( fonts.docs, /Import styles\/newspack-brand-fonts\.css once, before the theme/ );
	assert.equal( fonts.files.length, 1 );
	const [ file ] = fonts.files;
	assert.equal( file.type, 'registry:file' );
	assert.equal( file.target, 'styles/newspack-brand-fonts.css' );
	assert.ok( ! /\/\*/.test( file.content ) );
	const blocks = file.content.match( /@font-face \{[^}]*\}/g );
	assert.equal( blocks.length, 5 );
	const expected = [
		[ 'American Grotesk Compressed', '900', 'normal', 'american-grotesk-compressed-black.woff2' ],
		[ 'American Grotesk', '400', 'normal', 'american-grotesk-regular.woff2' ],
		[ 'American Grotesk', '400', 'italic', 'american-grotesk-italic.woff2' ],
		[ 'American Grotesk', '500', 'normal', 'american-grotesk-medium.woff2' ],
		[ 'American Grotesk', '500', 'italic', 'american-grotesk-medium-italic.woff2' ],
	];
	blocks.forEach( ( block, index ) => {
		const [ family, weight, style, name ] = expected[ index ];
		assert.ok( block.includes( `font-family: "${ family }";` ) );
		assert.ok( block.includes( `font-style: ${ style };` ) );
		assert.ok( block.includes( `font-weight: ${ weight };` ) );
		assert.ok( block.includes( 'font-display: swap;' ) );
		assert.ok( block.includes( `src: url("/fonts/american-grotesk/${ name }") format("woff2");` ) );
	} );
} );

test( 'registry.json lists the fonts item and mapping records the type', () => {
	const index = read( join( registry, 'registry.json' ) );
	assert.ok( index.items.some( item => item.name === 'newspack-brand-fonts' && item.type === 'registry:item' ) );
	assert.ok( read( join( root, 'dist', 'mapping.json' ) ).brand.type.length > 0 );
} );

test( 'no American Grotesk font files are tracked', () => {
	const files = execFileSync( 'git', [ 'ls-files', '.' ], { cwd: root, encoding: 'utf8' } );
	assert.ok( ! /\.(woff2|otf|ttf)$/m.test( files ) );
} );
