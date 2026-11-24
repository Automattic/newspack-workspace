import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { converter, formatHex, formatHex8, parse } from 'culori';
import postcss from 'postcss';
import { apcaLc, composite } from './apca.mjs';
import { accentTokens, brandBase } from './brand.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

function declarations( css, selector ) {
	const found = {};
	postcss.parse( css ).walkRules( rule => {
		if ( rule.parent.type === 'root' && rule.selector === selector ) {
			rule.walkDecls( decl => {
				found[ decl.prop ] = decl.value.trim();
			} );
		}
	} );
	return found;
}

function splitTopLevel( text ) {
	const parts = [];
	let depth = 0;
	let start = 0;
	for ( let i = 0; i < text.length; i++ ) {
		if ( text[ i ] === '(' ) {
			depth++;
		} else if ( text[ i ] === ')' ) {
			depth--;
		} else if ( text[ i ] === ',' && depth === 0 ) {
			parts.push( text.slice( start, i ) );
			start = i + 1;
		}
	}
	parts.push( text.slice( start ) );
	return parts;
}

function splitColorShare( text ) {
	const match = text.match( /^(.+?)\s+([\d.]+%)$/ );
	return match ? [ match[ 1 ], match[ 2 ] ] : [ text, '50%' ];
}

const toOklab = converter( 'oklab' );

function mixPremultiplied( a, b, share ) {
	const first = toOklab( a );
	const second = toOklab( b );
	const alphaA = first.alpha ?? 1;
	const alphaB = second.alpha ?? 1;
	const alpha = alphaA * share + alphaB * ( 1 - share );
	if ( alpha === 0 ) {
		return { mode: 'rgb', r: 0, g: 0, b: 0, alpha: 0 };
	}
	const channel = key => ( first[ key ] * alphaA * share + second[ key ] * alphaB * ( 1 - share ) ) / alpha;
	return { mode: 'oklab', l: channel( 'l' ), a: channel( 'a' ), b: channel( 'b' ), alpha };
}

export function resolveValue( value ) {
	const text = value.trim();
	const mix = text.match( /^color-mix\(in oklab, (.+)\)$/ );
	if ( mix ) {
		const [ first, second ] = splitTopLevel( mix[ 1 ] ).map( part => part.trim() );
		const [ colorA, shareA ] = splitColorShare( first );
		return mixPremultiplied( resolveValue( colorA ), resolveValue( second ), Number.parseFloat( shareA ) / 100 );
	}
	if ( text === 'transparent' ) {
		return { mode: 'rgb', r: 0, g: 0, b: 0, alpha: 0 };
	}
	const color = parse( text );
	if ( ! color ) {
		throw new Error( `Cannot parse ${ text }` );
	}
	return color;
}

export function computeScope( declared, inherited = {} ) {
	const computed = {};
	const active = new Set();
	const known = name => name in declared || name in inherited;
	const lookup = name => {
		if ( name in computed ) {
			return computed[ name ];
		}
		if ( name in declared ) {
			if ( active.has( name ) ) {
				throw new Error( `Token cycle at ${ name }` );
			}
			active.add( name );
			let value = declared[ name ];
			value = value.replace( /var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*?)\s*)?\)/g, ( match, ref, fallback ) => ( known( ref ) || fallback === undefined ? lookup( ref ) : fallback ) );
			if ( value.includes( 'var(' ) ) {
				throw new Error( `Unsupported var() syntax in ${ name }: ${ declared[ name ] }` );
			}
			active.delete( name );
			computed[ name ] = value;
			return value;
		}
		if ( name in inherited ) {
			return inherited[ name ];
		}
		throw new Error( `Missing token ${ name }` );
	};
	for ( const name of Object.keys( declared ) ) {
		lookup( name );
	}
	return { ...inherited, ...computed };
}

const tokenOf = value => value.match( /^var\(--([\w-]+)\)$/ )?.[ 1 ];

const hexOf = color => ( ( color.alpha ?? 1 ) < 1 ? formatHex8( color ) : formatHex( color ) );

function scopes( dist ) {
	const css = readFileSync( join( dist, 'brand.css' ), 'utf8' );
	const light = computeScope( declarations( css, ':root' ) );
	const inverse = computeScope( declarations( css, '.newspack-brand-inverse' ), light );
	return { light, inverse };
}

export function brandPairs( dist = join( root, 'dist' ) ) {
	const { light, inverse } = scopes( dist );
	const pair = ( name, scope, fgToken, bgToken, min ) => {
		const colorOf = token => {
			if ( ! ( `--${ token }` in scope ) ) {
				throw new Error( `Missing token --${ token }` );
			}
			return hexOf( resolveValue( scope[ `--${ token }` ] ) );
		};
		const fg = colorOf( fgToken );
		const bg = colorOf( bgToken );
		const page = colorOf( 'newspack-ui-color-surface' );
		return { name, fg, bg, lc: apcaLc( fg, composite( bg, page ), page ), min };
	};
	const L = ( name, fg, bg, min ) => pair( name, light, fg, bg, min );
	const I = ( name, fg, bg, min ) => pair( name, inverse, fg, bg, min );
	const surface = 'newspack-ui-color-surface';
	const text = 'newspack-ui-color-text';
	const inverseHeadings = '.newspack-brand-inverse h1, .newspack-brand-inverse .font-display';
	const pairs = [
		L( 'button ink on Cobalt fill', 'newspack-ui-color-btn-ink', 'newspack-ui-color-btn', 60 ),
		L( 'button ink on Cobalt fill, hover', 'newspack-ui-color-btn-ink', 'newspack-ui-color-btn-hover', 60 ),
		L( 'button ink on Cobalt fill, pressed', 'newspack-ui-color-btn-ink', 'newspack-ui-color-btn-pressed', 60 ),
		L( 'link on surface', 'newspack-ui-color-link', surface, 75 ),
		L( 'focus ring on surface', 'newspack-ui-color-focus', surface, 45 ),
		L( 'checked mark on surface', 'newspack-ui-color-mark', surface, 45 ),
		L( 'h1 on surface', tokenOf( brandBase.h1.color ), surface, 45 ),
		L( 'display text on surface', tokenOf( brandBase[ '.font-display' ].color ), surface, 45 ),
		I( 'h1 on inverse', tokenOf( brandBase[ inverseHeadings ].color ), surface, 45 ),
		I( 'display text on inverse', tokenOf( brandBase[ inverseHeadings ].color ), surface, 45 ),
		I( 'body text on inverse', text, surface, 75 ),
		I( 'muted text on inverse', 'newspack-ui-color-text-muted', surface, 60 ),
		I( 'link on inverse', 'newspack-ui-color-link', surface, 75 ),
		I( 'inverse button ink on inverse button', 'newspack-ui-color-btn-ink', 'newspack-ui-color-btn', 60 ),
		I( 'inverse button ink on inverse button, hover', 'newspack-ui-color-btn-ink', 'newspack-ui-color-btn-hover', 60 ),
		I( 'inverse button ink on inverse button, pressed', 'newspack-ui-color-btn-ink', 'newspack-ui-color-btn-pressed', 60 ),
		I( 'inverse button ink on hover, text-hover token', 'newspack-ui-color-button-text-hover', 'newspack-ui-color-btn-hover', 60 ),
		I( 'inverse focus ring on the white button', 'newspack-ui-color-focus-on-brand', 'newspack-ui-color-btn', 45 ),
		I( 'inverse checked mark on Cobalt', 'newspack-ui-color-mark', surface, 45 ),
		I( 'inverse tick on the white mark', 'newspack-ui-color-on-mark', 'newspack-ui-color-mark', 45 ),
		I( 'inverse secondary button ink on its fill', text, 'newspack-ui-color-neutral-fill', 60 ),
		I( 'inverse secondary button ink on its fill, hover', text, 'newspack-ui-color-neutral-fill-hover', 60 ),
		I( 'inverse secondary button ink on its fill, pressed', text, 'newspack-ui-color-neutral-fill-pressed', 60 ),
		I( 'inverse brand button ink on the brand fill', 'newspack-ui-color-btn-brand-on', 'newspack-ui-color-btn-brand', 60 ),
		I( 'inverse brand button ink on the brand fill, hover', 'newspack-ui-color-btn-brand-on', 'newspack-ui-color-btn-brand-hover', 60 ),
		I( 'inverse brand button ink on the brand fill, pressed', 'newspack-ui-color-btn-brand-on', 'newspack-ui-color-btn-brand-pressed', 60 ),
		I( 'inverse input border on Cobalt', 'newspack-ui-color-input-border', surface, 45 ),
		L( 'input focus edge on surface', 'newspack-ui-color-input-border-focus', surface, 45 ),
		I( 'label on inverse', 'newspack-ui-label-color', surface, 75 ),
		I( 'inactive tab on inverse', 'newspack-ui-color-text-subtle', surface, 60 ),
		I( 'field ink on field', 'newspack-ui-field-ink', 'newspack-ui-field-bg', 75 ),
		I( 'placeholder on field', 'newspack-ui-color-text-subtle', 'newspack-ui-field-bg', 60 ),
		I( 'placeholder on disabled field', 'newspack-ui-color-text-subtle', 'newspack-ui-color-input-bg-disabled', 60 ),
		I( 'default alert text on its fill', text, 'newspack-ui-color-surface-subtle', 75 ),
		I( 'default alert icon on its fill', 'newspack-ui-color-text-muted', 'newspack-ui-color-surface-subtle', 45 ),
		I( 'muted badge ink on its fill', 'newspack-ui-color-text-muted', 'newspack-ui-color-surface-sunken', 60 ),
		I( 'secondary badge ink on its fill', text, 'newspack-ui-color-neutral-fill', 60 ),
		I( 'default badge ink on its fill', 'newspack-ui-color-btn-ink', 'newspack-ui-color-btn', 60 ),
		I( 'success badge ink on its fill', 'newspack-ui-color-success-text', 'newspack-ui-color-success-tint', 60 ),
		I( 'warning badge ink on its fill', 'newspack-ui-color-warning-text', 'newspack-ui-color-warning-tint', 60 ),
		I( 'error badge ink on its fill', 'newspack-ui-color-error-text', 'newspack-ui-color-error-tint', 60 ),
		I( 'destructive button ink on its fill', 'newspack-ui-color-destructive-ink', 'newspack-ui-color-destructive-fill', 60 ),
		L( 'toast ink on toast', 'newspack-ui-color-inverse-ink', 'newspack-ui-color-inverse-bg', 75 ),
		L( 'select highlighted item on its fill', text, 'newspack-ui-color-neutral-fill', 60 ),
		L( 'select checked item on its fill', 'newspack-ui-color-on-selected', 'newspack-ui-color-selected', 60 ),
		L( 'active tab marker on surface', 'newspack-ui-color-selected', surface, 45 ),
		I( 'inverse active tab marker on Cobalt', 'newspack-ui-color-selected', surface, 45 ),
		I( 'segmented control unselected on its track', 'newspack-ui-color-text-muted', 'newspack-ui-color-neutral-fill', 60 ),
		I( 'disabled button ink on its fill', 'newspack-ui-color-disabled-ink', 'newspack-ui-color-surface-subtle', 30 ),
		I( 'inverse focus on Cobalt', 'newspack-ui-color-focus', surface, 45 ),
	];
	for ( const name of Object.keys( accentTokens ).filter( key => key.endsWith( '-ink' ) ) ) {
		const accent = name.replace( /-ink$/, '' ).replace( 'newspack-ui-color-brand-accent-', '' );
		pairs.push( L( `${ accent } ink on ${ accent }`, name, name.replace( /-ink$/, '' ), 60 ) );
		pairs.push( L( `body text on ${ accent } subtle`, text, name.replace( /-ink$/, '-subtle' ), 75 ) );
	}
	for ( let index = 1; index <= 5; index++ ) {
		pairs.push( L( `chart ${ index } on surface`, `chart-${ index }`, surface, 30 ) );
	}
	return pairs;
}

export function writeBrandReport( dist = join( root, 'dist' ) ) {
	const pairs = brandPairs( dist );
	const rows = pairs.map( p => `| ${ p.name } | ${ p.fg } | ${ p.bg } | ${ p.lc.toFixed( 1 ) } | ${ p.min } | ${ Math.abs( p.lc ) >= p.min ? 'pass' : 'FAIL' } |` );
	const body = [ '# Brand contrast (APCA)', '', '| Pair | Foreground | Background | Lc | Min | Result |', '| --- | --- | --- | --- | --- | --- |', ...rows, '' ].join( '\n' );
	const dir = dist === join( root, 'dist' ) ? join( root, 'build', 'brand' ) : join( dist, 'brand-report' );
	const file = join( dir, 'report.md' );
	mkdirSync( dir, { recursive: true } );
	writeFileSync( file, body );
	const failed = pairs.filter( p => Math.abs( p.lc ) < p.min );
	console.log( `newspack-ui-shadcn: brand contrast ${ pairs.length - failed.length }/${ pairs.length } pairs pass, report at ${ file }` );
}
