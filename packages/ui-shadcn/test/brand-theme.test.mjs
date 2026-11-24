import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import postcss from 'postcss';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse } from 'culori';
import { formatOklch } from '../scripts/oklch.mjs';
import { brandChart, brandFonts, brandTypeTokens } from '../scripts/brand.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const registry = join( root, 'dist', 'registry' );
const read = path => JSON.parse( readFileSync( path, 'utf8' ) );
const brand = read( join( registry, 'newspack-brand.json' ) );
const neutral = read( join( registry, 'newspack-ui-neutral.json' ) );
const baseline = process.env.NEUTRAL_BASELINE;

const overridden = [
	'--newspack-ui-color-btn',
	'--newspack-ui-color-btn-ink',
	'--newspack-ui-color-btn-hover',
	'--newspack-ui-color-btn-pressed',
	'--newspack-ui-color-focus',
	'--newspack-ui-color-focus-halo',
	'--newspack-ui-color-mark',
	'--newspack-ui-color-selected',
	'--newspack-ui-color-link',
	'--newspack-ui-color-input-border-focus',
];

test( 'is a registry theme named newspack-brand', () => {
	assert.equal( brand.type, 'registry:theme' );
	assert.equal( brand.name, 'newspack-brand' );
} );

test( 'brand.css starts with the dark variant line', () => {
	assert.ok( readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' ).startsWith( '@custom-variant dark (&:where(.dark, .dark *));' ) );
} );

test( 'keeps the dark variant line and replaces Neutral dark with blue mode', () => {
	assert.ok( '@custom-variant dark (&:where(.dark, .dark *))' in brand.css );
	const darkKeys = Object.keys( brand.css ).filter( key => key.startsWith( '.dark' ) );
	assert.deepEqual( darkKeys, [ '.dark' ] );
	assert.equal( brand.css[ '.dark' ][ '--newspack-ui-color-surface' ], 'var(--newspack-ui-color-primary-60)' );
	for ( const key of Object.keys( brand.css[ '.dark' ] ) ) {
		assert.ok( ! /^--newspack-ui-color-(neutral|primary|success|error|warning|overlay)-\d+$/.test( key ), `palette step ${ key } redefined in blue mode` );
	}
	for ( const [ key, value ] of Object.entries( brand.css ) ) {
		if ( key.startsWith( '@media' ) ) {
			for ( const selector of Object.keys( value ) ) {
				assert.ok( ! selector.startsWith( '.dark ' ), `Neutral dark selector ${ selector } in ${ key }` );
			}
		}
	}
	assert.equal( brand.cssVars.dark, undefined );
} );

test( 'blue mode and the inverse section share one set of values', () => {
	assert.deepEqual( brand.css[ '.newspack-brand-inverse' ], brand.css[ '.dark' ] );
	const contrast = brand.css[ '@media (prefers-contrast: more)' ];
	assert.ok( contrast[ ':root' ] );
	assert.ok( contrast[ '.dark' ] );
	assert.deepEqual( contrast[ '.newspack-brand-inverse' ], contrast[ '.dark' ] );
	const css = readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' );
	const body = selector => css.match( new RegExp( `^${ selector.replace( '.', '\\.' ) } \\{([\\s\\S]*?)\\n\\}`, 'm' ) )[ 1 ];
	assert.equal( body( '.dark' ), body( '.newspack-brand-inverse' ) );
	assert.ok( css.lastIndexOf( '@media (prefers-contrast: more)' ) > css.indexOf( '.newspack-brand-inverse {' ) );
} );

test( 'blue mode restates every shadcn variable, pointing primary at the white button', () => {
	const blue = brand.css[ '.dark' ];
	for ( const name of Object.keys( brand.cssVars.light ) ) {
		assert.ok( blue[ `--${ name }` ], name );
	}
	assert.equal( blue[ '--primary' ], 'var(--newspack-ui-color-btn)' );
	assert.equal( blue[ '--background' ], 'var(--newspack-ui-color-surface)' );
	assert.equal( blue[ '--ring' ], 'var(--newspack-ui-color-focus)' );
	assert.equal( blue[ '--chart-1' ], 'var(--newspack-ui-color-primary-0)' );
} );

test( 'blue mode supplies status, invalid and accent values', () => {
	const blue = brand.css[ '.dark' ];
	for ( const name of [ 'success', 'success-text', 'success-tint', 'success-border', 'warning', 'warning-dot', 'warning-text', 'warning-tint', 'warning-border', 'error', 'error-text', 'error-tint', 'error-border', 'destructive-text' ] ) {
		assert.ok( blue[ `--newspack-ui-color-${ name }` ], name );
	}
	for ( const name of [ 'emerald', 'morganite', 'amber' ] ) {
		assert.match( blue[ `--newspack-ui-color-brand-accent-${ name }-subtle` ], /^color-mix\(in oklab, / );
	}
	for ( const value of Object.values( blue ) ) {
		assert.ok( ! /#[0-9a-f]{3,8}\b/i.test( value ), `raw hex in blue mode: ${ value }` );
		assert.ok( ! value.includes( 'srgb' ), value );
	}
} );

test( 'points the button, focus, mark, selected and link tokens at Cobalt', () => {
	const root = brand.css[ ':root' ];
	const mix = level => `color-mix(in oklab, var(--newspack-ui-color-btn) var(--newspack-ui-color-mix-${ level }), var(--newspack-ui-color-surface))`;
	assert.equal( root[ '--newspack-ui-color-btn' ], 'var(--newspack-ui-color-primary-60)' );
	assert.equal( root[ '--newspack-ui-color-btn-ink' ], 'var(--newspack-ui-color-neutral-0)' );
	assert.equal( root[ '--newspack-ui-color-btn-hover' ], mix( 'hover' ) );
	assert.equal( root[ '--newspack-ui-color-btn-pressed' ], mix( 'pressed' ) );
	for ( const name of [ 'focus', 'mark', 'selected' ] ) {
		assert.equal( root[ `--newspack-ui-color-${ name }` ], 'var(--newspack-ui-color-primary-60)' );
	}
	assert.equal( root[ '--newspack-ui-color-link' ], 'var(--newspack-ui-color-brand-text)' );
	assert.equal( root[ '--newspack-ui-color-input-border-focus' ], 'var(--newspack-ui-color-focus)' );
} );

test( 'sets the shadcn variables in both places', () => {
	const cobalt = formatOklch( parse( '#003da5' ) );
	const white = formatOklch( parse( '#ffffff' ) );
	const chart = brandChart.map( hex => formatOklch( parse( hex ) ) );
	assert.equal( brandChart.length, 5 );
	for ( const scope of [ brand.cssVars.light, Object.fromEntries( Object.entries( brand.css[ ':root' ] ).map( ( [ k, v ] ) => [ k.slice( 2 ), v ] ) ) ] ) {
		assert.equal( scope.primary, cobalt );
		assert.equal( scope[ 'primary-foreground' ], white );
		assert.equal( scope.ring, cobalt );
		assert.equal( scope[ 'sidebar-primary' ], cobalt );
		assert.equal( scope[ 'sidebar-primary-foreground' ], white );
		assert.equal( scope[ 'sidebar-ring' ], cobalt );
		chart.forEach( ( value, index ) => assert.equal( scope[ `chart-${ index + 1 }` ], value ) );
	}
} );

test( 'every other root key equals Neutral', () => {
	const own = Object.keys( brand.css[ ':root' ] );
	const accents = own.filter( key => key.startsWith( '--newspack-ui-color-brand-accent-' ) );
	assert.ok( accents.length >= 9 );
	const shadcn = [ '--primary', '--primary-foreground', '--ring', '--sidebar-primary', '--sidebar-ring', '--chart-1', '--chart-2', '--chart-3', '--chart-4', '--chart-5' ];
	const type = [ ...Object.keys( brandFonts ), ...Object.keys( brandTypeTokens ) ].map( name => `--${ name }` );
	const skip = new Set( [ ...overridden, ...shadcn, ...accents, ...type ] );
	for ( const [ key, value ] of Object.entries( neutral.css[ ':root' ] ) ) {
		if ( ! skip.has( key ) ) {
			assert.equal( brand.css[ ':root' ][ key ], value, key );
		}
	}
	for ( const key of own ) {
		assert.ok( skip.has( key ) || key in neutral.css[ ':root' ], `unexpected key ${ key }` );
	}
	assert.deepEqual( brand.dependencies, neutral.dependencies );
} );

test( 'accent tokens pair a fill with Neutral 900 ink', () => {
	const rootCss = brand.css[ ':root' ];
	for ( const name of [ 'emerald', 'morganite', 'amber' ] ) {
		assert.ok( rootCss[ `--newspack-ui-color-brand-accent-${ name }` ] );
		assert.ok( rootCss[ `--newspack-ui-color-brand-accent-${ name }-subtle` ] );
		assert.equal( rootCss[ `--newspack-ui-color-brand-accent-${ name }-ink` ], 'var(--newspack-ui-color-neutral-90)' );
	}
} );

test( 'writes brand.css and the mapping section', () => {
	const css = readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' );
	assert.ok( css.indexOf( ':root {' ) !== -1 && css.indexOf( ':root {' ) < css.indexOf( '.newspack-brand-inverse {' ) );
	assert.deepEqual( css.match( /^\s*\.dark[^{]*\{/gm ).map( line => line.trim() ), [ '.dark {', '.dark {' ] );
	assert.ok( ! /\/\*/.test( css ) );
	const mapping = read( join( root, 'dist', 'mapping.json' ) );
	assert.ok( mapping.brand.entries.length > 0 );
	assert.ok( mapping.brand.blue.length > 0 );
	assert.ok( mapping.brand.blueContrast.length > 0 );
	assert.ok( mapping.brand.accents.length > 0 );
	assert.equal( mapping.style, 'neutral' );
} );

test( 'registry.json lists newspack-brand', () => {
	const index = read( join( registry, 'registry.json' ) );
	assert.ok( index.items.some( item => item.name === 'newspack-brand' && item.type === 'registry:theme' ) );
} );

test( 'inverse tokens are present', () => {
	const css = readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' );
	assert.match( css, /\.newspack-brand-inverse \{[^}]*--newspack-ui-color-surface: var\(--newspack-ui-color-primary-60\)/ );
	assert.match( css, /color-mix\(in oklab, var\(--newspack-ui-color-neutral-0\) 30%, transparent\)/ );
} );

test( 'inverse block restates the derived button and focus tokens', () => {
	const css = readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' );
	const block = css.slice( css.indexOf( '.newspack-brand-inverse {' ) );
	const mix = level => `--newspack-ui-color-btn-${ level }: color-mix(in oklab, var(--newspack-ui-color-btn) var(--newspack-ui-color-mix-${ level }), var(--newspack-ui-color-surface));`;
	assert.ok( block.includes( mix( 'hover' ) ) );
	assert.ok( block.includes( mix( 'pressed' ) ) );
	assert.ok( block.includes( '--newspack-ui-color-focus-halo: color-mix(in oklab, var(--newspack-ui-color-focus) var(--newspack-ui-focus-halo-mix), transparent);' ) );
} );

test( 'Neutral output is unchanged from the baseline', t => {
	if ( ! baseline ) {
		t.skip( 'set NEUTRAL_BASELINE to a build copy to compare against' );
		return;
	}
	const file = join( baseline, 'dist', 'registry', 'newspack-ui-neutral.json' );
	if ( ! existsSync( file ) ) {
		t.skip( `no baseline at ${ file }` );
		return;
	}
	const before = read( file );
	const after = structuredClone( neutral );
	assert.deepEqual( after, withLink( before ) );
} );

function withLink( theme ) {
	const next = structuredClone( theme );
	for ( const scope of [ ':root', '.dark' ] ) {
		next.css[ scope ][ '--newspack-ui-color-link' ] = 'var(--newspack-ui-color-text)';
	}
	return next;
}

test( 'Neutral gives links their own token, equal to the text color', () => {
	for ( const scope of [ ':root', '.dark' ] ) {
		assert.equal( neutral.css[ scope ][ '--newspack-ui-color-link' ], 'var(--newspack-ui-color-text)', scope );
	}
	const button = readFileSync( join( registry, 'button.json' ), 'utf8' );
	assert.ok( button.includes( '--newspack-ui-color-link' ) );
	assert.ok( ! button.includes( 'text-(color:--newspack-ui-color-text) underline' ) );
} );

test( 'the inverse surface ships in the registry item and restates what components read', () => {
	const inverse = brand.css[ '.newspack-brand-inverse' ];
	assert.ok( inverse );
	assert.equal( inverse[ '--newspack-ui-color-surface' ], 'var(--newspack-ui-color-primary-60)' );
	for ( const name of [ 'button-text-hover', 'focus-on-brand', 'brand-text', 'mark', 'on-mark', 'selected', 'neutral-fill', 'neutral-fill-hover', 'neutral-fill-pressed', 'btn-brand', 'btn-brand-hover', 'btn-brand-pressed', 'btn-brand-on', 'btn-brand-text', 'input-border' ] ) {
		assert.ok( inverse[ `--newspack-ui-color-${ name }` ], name );
	}
	assert.equal( inverse[ '--newspack-ui-color-mark' ], 'var(--newspack-ui-color-neutral-0)' );
	assert.equal( inverse[ '--newspack-ui-color-on-mark' ], 'var(--newspack-ui-color-primary-60)' );
	assert.equal( inverse[ '--newspack-ui-color-focus-on-brand' ], 'var(--newspack-ui-color-primary-60)' );
	const css = readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' );
	for ( const [ prop, value ] of Object.entries( inverse ) ) {
		assert.ok( css.includes( `${ prop }: ${ value };` ), prop );
	}
} );

test( 'Neutral item matches Neutral theme.css and the brand step never rewrites it', () => {
	const themeRoot = {};
	postcss.parse( readFileSync( join( root, 'dist', 'theme.css' ), 'utf8' ) ).each( node => {
		if ( node.type === 'rule' && node.selector === ':root' ) {
			node.walkDecls( decl => {
				themeRoot[ decl.prop ] = decl.value;
			} );
		}
	} );
	for ( const [ key, value ] of Object.entries( neutral.css[ ':root' ] ) ) {
		assert.equal( themeRoot[ key ], value, key );
	}
	const copy = mkdtempSync( join( tmpdir(), 'brand-dist-' ) );
	try {
		cpSync( join( root, 'dist' ), copy, { recursive: true } );
		const file = join( copy, 'registry', 'newspack-ui-neutral.json' );
		const before = readFileSync( file, 'utf8' );
		const mtime = statSync( file ).mtimeMs;
		execFileSync( process.execPath, [ join( root, 'scripts', 'build-brand.mjs' ) ], { cwd: root, stdio: 'ignore', env: { ...process.env, BRAND_DIST: copy } } );
		assert.equal( readFileSync( file, 'utf8' ), before );
		assert.equal( statSync( file ).mtimeMs, mtime );
		const index = read( join( copy, 'registry', 'registry.json' ) );
		assert.equal( index.items.filter( item => item.name === 'newspack-brand' ).length, 1 );
		assert.equal( index.items[ index.items.findIndex( item => item.name === 'newspack-ui-neutral' ) + 1 ].name, 'newspack-brand' );
		assert.deepEqual( read( join( copy, 'registry', 'newspack-brand.json' ) ), brand );
	} finally {
		rmSync( copy, { recursive: true, force: true } );
	}
} );

test( 'documents blue mode and what it does not cover', () => {
	assert.match( brand.docs, /Blue mode is the \.dark class/ );
	assert.doesNotMatch( brand.docs, /Alerts/ );
	assert.ok( read( join( root, 'dist', 'mapping.json' ) ).brand.unsupported.length >= 2 );
} );
