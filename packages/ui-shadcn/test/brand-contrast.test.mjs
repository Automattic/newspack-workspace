import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatHex, formatHex8, interpolate, parse } from 'culori';
import { brandPairs, brandScopes, computeScope, resolveValue } from '../scripts/brand-report.mjs';

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
	'input focus edge on surface',
	'toast ink on toast',
	'select highlighted item on its fill',
	'select checked item on its fill',
	'active tab marker on surface',
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
	'body text on blue',
	'muted text on blue',
	'link on blue',
	'h1 on blue',
	'display text on blue',
	'h1 in a blue section',
	'button ink on the white button',
	'button ink on the white button, hover',
	'button ink on the white button, pressed',
	'button ink on hover, text-hover token',
	'focus ring on blue',
	'focus ring on the white button',
	'checked mark on blue',
	'tick on the white mark',
	'secondary button ink on its fill',
	'secondary button ink on its fill, hover',
	'secondary button ink on its fill, pressed',
	'brand button ink on the brand fill',
	'brand button ink on the brand fill, hover',
	'brand button ink on the brand fill, pressed',
	'brand outline button ink on blue',
	'destructive button ink on its fill',
	'disabled button ink on its fill',
	'input border on blue',
	'input focus edge on blue',
	'label on blue',
	'field ink on field',
	'placeholder on field',
	'placeholder on disabled field',
	'invalid field edge on field',
	'invalid checkbox and radio edge on blue',
	'field error text on blue',
	'inactive tab on blue',
	'active tab marker on blue',
	'segmented control unselected on its track',
	'default alert text on its fill',
	'default alert icon on its fill',
	'success alert text on its fill',
	'success alert icon on its fill',
	'warning alert text on its fill',
	'warning alert icon on its fill',
	'error and destructive alert text on its fill',
	'error and destructive alert icon on its fill',
	'muted badge ink on its fill',
	'secondary badge ink on its fill',
	'default badge ink on its fill',
	'success badge ink on its fill',
	'warning badge ink on its fill',
	'error badge ink on its fill',
	'emerald ink on emerald, blue',
	'body text on emerald subtle, blue',
	'morganite ink on morganite, blue',
	'body text on morganite subtle, blue',
	'amber ink on amber, blue',
	'body text on amber subtle, blue',
	'chart 1 on blue',
	'chart 2 on blue',
	'chart 3 on blue',
	'chart 4 on blue',
	'chart 5 on blue',
	'high-contrast input border on blue',
	'placeholder on high-contrast disabled field',
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

test( 'a .newspack-brand-inverse section resolves exactly like a .dark section', () => {
	const { blue, inverse } = brandScopes();
	assert.deepEqual( inverse, blue );
} );

for ( const pair of pairs ) {
	test( `${ pair.name } meets Lc ${ pair.min }`, () => {
		assert.ok( Math.abs( pair.lc ) >= pair.min, `${ pair.name }: ${ pair.fg } on ${ pair.bg } is Lc ${ pair.lc.toFixed( 1 ) }, needs ${ pair.min }` );
	} );
}
