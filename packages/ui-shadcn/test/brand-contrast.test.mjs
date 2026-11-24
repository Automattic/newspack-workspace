import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatHex, formatHex8, interpolate, parse } from 'culori';
import { brandPairs, computeScope, resolveValue } from '../scripts/brand-report.mjs';

const pairs = brandPairs();

const expectedNames = [
	'button ink on Cobalt fill',
	'button ink on Cobalt fill, hover',
	'button ink on Cobalt fill, pressed',
	'link on surface',
	'focus ring on surface',
	'checked mark on surface',
	'h1 on surface',
	'display text on surface',
	'h1 on inverse',
	'display text on inverse',
	'body text on inverse',
	'muted text on inverse',
	'link on inverse',
	'inverse button ink on inverse button',
	'inverse button ink on inverse button, hover',
	'inverse button ink on inverse button, pressed',
	'inverse button ink on hover, text-hover token',
	'inverse focus ring on the white button',
	'inverse checked mark on Cobalt',
	'inverse tick on the white mark',
	'inverse secondary button ink on its fill',
	'inverse secondary button ink on its fill, hover',
	'inverse secondary button ink on its fill, pressed',
	'inverse brand button ink on the brand fill',
	'inverse brand button ink on the brand fill, hover',
	'inverse brand button ink on the brand fill, pressed',
	'inverse input border on Cobalt',
	'input focus edge on surface',
	'label on inverse',
	'inactive tab on inverse',
	'field ink on field',
	'placeholder on field',
	'placeholder on disabled field',
	'default alert text on its fill',
	'default alert icon on its fill',
	'muted badge ink on its fill',
	'secondary badge ink on its fill',
	'default badge ink on its fill',
	'success badge ink on its fill',
	'warning badge ink on its fill',
	'error badge ink on its fill',
	'destructive button ink on its fill',
	'toast ink on toast',
	'select highlighted item on its fill',
	'select checked item on its fill',
	'active tab marker on surface',
	'inverse active tab marker on Cobalt',
	'segmented control unselected on its track',
	'disabled button ink on its fill',
	'inverse focus on Cobalt',
	'emerald ink on emerald',
	'body text on emerald subtle',
	'morganite ink on morganite',
	'body text on morganite subtle',
	'amber ink on amber',
	'body text on amber subtle',
	'chart 1 on surface',
	'chart 2 on surface',
	'chart 3 on surface',
	'chart 4 on surface',
	'chart 5 on surface',
];

test( 'covers exactly the expected brand color pairs', () => {
	assert.deepEqual( pairs.map( pair => pair.name ), expectedNames );
} );

test( 'mixing with transparent keeps the color and scales the alpha', () => {
	const color = resolveValue( 'color-mix(in oklab, #fff 30%, transparent)' );
	assert.equal( formatHex8( color ), '#ffffff4d' );
} );

test( 'an opaque mix matches the oklab interpolation the build uses', () => {
	const expected = formatHex( interpolate( [ parse( '#1e1e1e' ), parse( '#fff' ) ], 'oklab' )( 1 - 0.85 ) );
	assert.equal( formatHex( resolveValue( 'color-mix(in oklab, #1e1e1e 85%, #fff)' ) ), expected );
} );

test( 'a token not restated on the override block keeps its inherited value', () => {
	const root = computeScope( { '--text': '#111111', '--link': 'var(--text)', '--hover': 'color-mix(in oklab, var(--text) 85%, #fff)' } );
	const inverse = computeScope( { '--text': '#ffffff' }, root );
	assert.equal( inverse[ '--link' ], '#111111' );
	assert.equal( inverse[ '--hover' ], root[ '--hover' ] );
	assert.equal( inverse[ '--text' ], '#ffffff' );
} );

test( 'a restated token resolves against the override block', () => {
	const root = computeScope( { '--text': '#111111', '--hover': 'color-mix(in oklab, var(--text) 85%, #fff)' } );
	const inverse = computeScope( { '--text': '#ffffff', '--hover': 'color-mix(in oklab, var(--text) 85%, #000)' }, root );
	assert.equal( inverse[ '--hover' ], 'color-mix(in oklab, #ffffff 85%, #000)' );
} );

test( 'a spaced var() resolves', () => {
	const scope = computeScope( { '--a': '#fff', '--b': 'var( --a )' } );
	assert.equal( scope[ '--b' ], '#fff' );
} );

test( 'a var() fallback is used for a missing token and ignored for a known one', () => {
	const scope = computeScope( { '--a': '#fff', '--b': 'var(--a, #000)', '--c': 'var( --missing , #123456 )' } );
	assert.equal( scope[ '--b' ], '#fff' );
	assert.equal( scope[ '--c' ], '#123456' );
} );

test( 'a var() that cannot be resolved throws instead of hanging', () => {
	assert.throws( () => computeScope( { '--a': 'var(--missing)' } ), /Missing token --missing/ );
	assert.throws( () => computeScope( { '--a': '#fff', '--b': 'var(--missing, var(--a))' } ), /Unsupported var\(\) syntax/ );
} );

test( 'mixing two translucent colors weights each by its alpha', () => {
	const color = resolveValue( 'color-mix(in oklab, #ffffff80 50%, #00000080)' );
	assert.ok( Math.abs( color.alpha - 0.5019607843137255 ) < 1e-9 );
	assert.equal( formatHex( color ), formatHex( interpolate( [ parse( '#fff' ), parse( '#000' ) ], 'oklab' )( 0.5 ) ) );
} );

test( 'a nested translucent mix keeps the inner alpha', () => {
	const color = resolveValue( 'color-mix(in oklab, color-mix(in oklab, #fff 50%, transparent) 50%, transparent)' );
	assert.equal( formatHex8( color ), '#ffffff40' );
} );

for ( const pair of pairs ) {
	test( `${ pair.name } meets Lc ${ pair.min }`, () => {
		assert.ok( Math.abs( pair.lc ) >= pair.min, `${ pair.name }: ${ pair.fg } on ${ pair.bg } is Lc ${ pair.lc.toFixed( 1 ) }, needs ${ pair.min }` );
	} );
}
