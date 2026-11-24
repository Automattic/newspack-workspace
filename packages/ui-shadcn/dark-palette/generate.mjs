import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse, converter, formatHex, displayable } from 'culori';
import postcss from 'postcss';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const BUILD = path.join( HERE, '../build' );
const OUT = path.join( BUILD, 'dark-palette' );
const TOKENS_PATH = path.join( BUILD, 'newspack-ui', 'tokens.json' );
const CSS_PATH = path.join( BUILD, 'newspack-ui', 'newspack-ui.css' );
const CSS_HREF = path.relative( OUT, CSS_PATH );

const LIGHT_BASE = '#ffffff';
const DARK_BASE = '#1e1e1e';
const FLOOR = { text: 60, mark: 25, border: 15 };
const EXACT_LC = 1;
const SNAP_LC = 0.5;
const SNAP_DELTA_E = 0.02;
const P = '--newspack-ui-color-';

const DARK_SELECTOR = '.newspack-ui[data-color-scheme="dark"]';
const AUTO_SELECTOR = '.newspack-ui[data-color-scheme="auto"]';

const SCALE_FAMILIES = [ 'neutral', 'primary', 'success', 'error', 'warning' ];

const PINS = {
	neutral: {
		0: { value: DARK_BASE, reason: 'Dark page background: the designer set neutral-900 (#1e1e1e) as the dark base, the swap of white.' },
		90: { value: '#ffffff', reason: 'Primary text, primary button fill and inverse surfaces: the designer asked for white, the exact swap of #1e1e1e.' },
	},
};

const CHROMA_FLOOR_SHARE = 0.5;
const CHART_MAX_LC = 80;
const TINT_MIRROR_LC = 30;
const COLORS_SCSS = path.join( HERE, '../../colors/colors.module.scss' );
const CHART_DARK_STEPS = { 0: 'primary-100', 1: 'primary-200', 2: 'primary-300', 3: 'quaternary-500', 4: 'quaternary-600', 5: 'quaternary-700' };

const RAISED = {
	2: { selector: ':is(.newspack-ui__dropdown__content, .select2-dropdown)', surface: `var(${ P }neutral-5)`, label: 'dropdowns and the select menu' },
	4: { selector: ':is(.newspack-ui__modal, .newspack-ui__modal__steps)', surface: `var(${ P }neutral-10)`, label: 'modals and step sheets' },
};

const OVERRIDES = [
	{
		token: '--newspack-ui-elevation-ink',
		value: '#000',
		reason: 'Light derives shadow ink from the contrast color mixed toward black. In dark the contrast color is white, so the same formula gives a mid-gray shadow that reads as a glow. Shadows stay black, as Newspack_UI_Palette::ELEVATION_DARK already does for dark publisher bases.',
	},
	{
		token: '--newspack-ui-elevation-strength',
		value: '2.5',
		reason: 'Black at the light shadow alphas (6% to 24%) is invisible on #1e1e1e. 2.5 times the light alphas is the value Newspack_UI_Palette uses for dark bases, so both dark paths agree.',
	},
	{
		token: '--newspack-ui-elevation-edge',
		value: 'color-mix(in oklab, var(--newspack-ui-color-contrast) 14%, transparent)',
		reason: 'Shadows alone do not outline a dark surface on a dark page. A 14% white hairline draws the edge of every elevated surface; same share as Newspack_UI_Palette::ELEVATION_DARK.',
	},
	{
		token: '--newspack-ui-field-caret',
		value: 'var(--newspack-ui-field-caret-light)',
		reason: 'The select caret is an SVG with a baked #1e1e1e fill, which disappears on the dark field. The white caret already ships as a token.',
	},
];

const toOklch = converter( 'oklch' );
const toOklab = converter( 'oklab' );
const toRgb = converter( 'rgb' );

const round = ( n, d = 1 ) => Math.round( n * 10 ** d ) / 10 ** d;
const fmt = n => ( Object.is( round( n ), -0 ) ? '0.0' : round( n ).toFixed( 1 ) );

function rgba255( color ) {
	const c = toRgb( typeof color === 'string' ? parse( color ) : color );
	const ch = v => Math.round( Math.min( 1, Math.max( 0, v ?? 0 ) ) * 255 );
	return [ ch( c.r ), ch( c.g ), ch( c.b ), c.alpha ?? 1 ];
}

function hex255( [ r, g, b ] ) {
	return '#' + [ r, g, b ].map( v => v.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

function composite( fg, bg ) {
	const f = rgba255( fg );
	const b = rgba255( bg );
	const a = f[ 3 ];
	return hex255( [ 0, 1, 2 ].map( i => Math.round( f[ i ] * a + b[ i ] * ( 1 - a ) ) ) );
}

function apcaLuminance( hex ) {
	const [ r, g, b ] = hex.replace( '#', '' ).match( /.{2}/g ).map( x => parseInt( x, 16 ) / 255 );
	const y = 0.2126729 * Math.pow( r, 2.4 ) + 0.7151522 * Math.pow( g, 2.4 ) + 0.072175 * Math.pow( b, 2.4 );
	return y > 0.022 ? y : y + Math.pow( 0.022 - y, 1.414 );
}

function apcaContrast( textY, backgroundY ) {
	if ( Math.abs( backgroundY - textY ) < 0.0005 ) {
		return 0;
	}
	if ( backgroundY > textY ) {
		const sapc = ( Math.pow( backgroundY, 0.56 ) - Math.pow( textY, 0.57 ) ) * 1.14;
		return sapc < 0.1 ? 0 : ( sapc - 0.027 ) * 100;
	}
	const sapc = ( Math.pow( backgroundY, 0.65 ) - Math.pow( textY, 0.62 ) ) * 1.14;
	return sapc > -0.1 ? 0 : ( sapc + 0.027 ) * 100;
}

function apca( fg, bg, page = bg ) {
	const bgHex = composite( bg, page );
	const fgHex = composite( fg, bgHex );
	return apcaContrast( apcaLuminance( fgHex ), apcaLuminance( bgHex ) );
}

function matchParen( str, open ) {
	let depth = 0;
	for ( let i = open; i < str.length; i++ ) {
		if ( str[ i ] === '(' ) depth++;
		if ( str[ i ] === ')' && --depth === 0 ) return i;
	}
	throw new Error( `Unbalanced parentheses in ${ str }` );
}

function splitTopLevel( str ) {
	const parts = [];
	let depth = 0;
	let start = 0;
	for ( let i = 0; i < str.length; i++ ) {
		if ( str[ i ] === '(' ) depth++;
		else if ( str[ i ] === ')' ) depth--;
		else if ( str[ i ] === ',' && depth === 0 ) {
			parts.push( str.slice( start, i ) );
			start = i + 1;
		}
	}
	parts.push( str.slice( start ) );
	return parts;
}

function substitute( expr, defs, seen = [] ) {
	let out = '';
	let i = 0;
	while ( i < expr.length ) {
		const j = expr.indexOf( 'var(', i );
		if ( j < 0 ) {
			out += expr.slice( i );
			break;
		}
		out += expr.slice( i, j );
		const end = matchParen( expr, j + 3 );
		const [ name, ...rest ] = splitTopLevel( expr.slice( j + 4, end ) );
		const key = name.trim();
		if ( defs[ key ] !== undefined && ! seen.includes( key ) ) {
			out += substitute( defs[ key ], defs, [ ...seen, key ] );
		} else if ( rest.length ) {
			out += substitute( rest.join( ',' ).trim(), defs, seen );
		} else {
			throw new Error( `Unresolved ${ key }` );
		}
		i = end + 1;
	}
	return out;
}

function mixOklab( c1, p1, c2, p2 ) {
	if ( p1 === null && p2 === null ) [ p1, p2 ] = [ 50, 50 ];
	else if ( p1 === null ) p1 = 100 - p2;
	else if ( p2 === null ) p2 = 100 - p1;
	const sum = p1 + p2;
	const multiplier = sum < 100 ? sum / 100 : 1;
	const w1 = p1 / sum;
	const w2 = p2 / sum;
	const a = toOklab( c1 );
	const b = toOklab( c2 );
	const aa = a.alpha ?? 1;
	const ba = b.alpha ?? 1;
	const alpha = aa * w1 + ba * w2;
	if ( alpha === 0 ) return { mode: 'oklab', l: 0, a: 0, b: 0, alpha: 0 };
	const ch = k => ( ( a[ k ] ?? 0 ) * aa * w1 + ( b[ k ] ?? 0 ) * ba * w2 ) / alpha;
	return { mode: 'oklab', l: ch( 'l' ), a: ch( 'a' ), b: ch( 'b' ), alpha: alpha * multiplier };
}

function evalColor( str, current ) {
	const s = str.trim();
	if ( /^currentcolor$/i.test( s ) ) return evalColor( current, current );
	if ( s.startsWith( 'color-mix(' ) ) {
		const parts = splitTopLevel( s.slice( 10, matchParen( s, 9 ) ) );
		if ( parts[ 0 ].trim() !== 'in oklab' ) throw new Error( `Not an oklab mix: ${ s }` );
		const [ x, y ] = parts.slice( 1 ).map( part => {
			const m = part.trim().match( /^([\s\S]*?)\s+(-?[\d.]+)%$/ );
			return m ? { c: evalColor( m[ 1 ], current ), p: Number( m[ 2 ] ) } : { c: evalColor( part, current ), p: null };
		} );
		return mixOklab( x.c, x.p, y.c, y.p );
	}
	const parsed = parse( s );
	if ( ! parsed ) throw new Error( `Cannot parse color ${ s }` );
	return parsed;
}

function resolveColor( expr, defs, current ) {
	const text = substitute( expr.startsWith( '--' ) ? `var(${ expr })` : expr, defs );
	const cur = current ? substitute( current.startsWith( '--' ) ? `var(${ current })` : current, defs ) : undefined;
	return evalColor( text, cur );
}

function describe( color ) {
	const [ r, g, b, a ] = rgba255( color );
	const hex = hex255( [ r, g, b ] );
	return a < 1 ? `${ hex } @ ${ round( a * 100, 0 ) }%` : hex;
}

fs.mkdirSync( OUT, { recursive: true } );
const tokens = JSON.parse( fs.readFileSync( TOKENS_PATH, 'utf8' ) );
const LIGHT_DEFS = Object.fromEntries( Object.entries( tokens.properties ).map( ( [ k, v ] ) => [ k, v.value ] ) );
const MEDIA_CONTRAST = Object.fromEntries( Object.entries( tokens.media[ '(prefers-contrast: more)' ] || {} ).map( ( [ k, v ] ) => [ k, v.value ] ) );

const PRIMITIVE = new RegExp( `^${ P }(neutral|overlay|primary|success|error|warning)-(\\d+)$` );
const scalesLight = {};
for ( const [ name, value ] of Object.entries( LIGHT_DEFS ) ) {
	const m = name.match( PRIMITIVE );
	if ( m ) ( scalesLight[ m[ 1 ] ] ||= {} )[ m[ 2 ] ] = value;
}

const NON_COLOR = new Set( [ `${ P }mix-hover`, `${ P }mix-pressed`, `${ P }border-outline-mix`, '--newspack-ui-field-caret-dark', '--newspack-ui-field-caret-light' ] );
const SEMANTIC = Object.keys( LIGHT_DEFS ).filter(
	name =>
		! PRIMITIVE.test( name ) &&
		! NON_COLOR.has( name ) &&
		( name.startsWith( P ) || name.startsWith( '--newspack-ui-elevation-' ) || [ '--newspack-ui-field-bg', '--newspack-ui-field-ink', '--newspack-ui-label-color' ].includes( name ) )
);

function lOf( hex ) {
	return toOklab( hex ).l;
}

const maxChromaCache = new Map();
function maxChroma( l, h ) {
	const key = `${ l.toFixed( 5 ) }|${ h.toFixed( 3 ) }`;
	if ( maxChromaCache.has( key ) ) return maxChromaCache.get( key );
	let lo = 0;
	let hi = 0.4;
	for ( let i = 0; i < 28; i++ ) {
		const mid = ( lo + hi ) / 2;
		if ( displayable( { mode: 'oklch', l, c: mid, h } ) ) lo = mid;
		else hi = mid;
	}
	maxChromaCache.set( key, lo );
	return lo;
}

function chromaRule( lightHex ) {
	const { l, c = 0, h = 0 } = toOklch( lightHex );
	const hue = h || 0;
	const light = c || 0;
	const relative = light > 0 ? Math.min( 1, light / maxChroma( l, hue ) ) : 0;
	return {
		hue,
		at: L => {
			const max = maxChroma( L, hue );
			return Math.min( max, Math.max( light, CHROMA_FLOOR_SHARE * relative * max ) );
		},
	};
}

function hexAtL( l, rule ) {
	const L = Math.min( 1, Math.max( 0, l ) );
	return formatHex( { mode: 'oklch', l: L, c: rule.at( L ), h: rule.hue } );
}

function solveForLc( lightHex, target, bg ) {
	const rule = chromaRule( lightHex );
	const f = l => apca( hexAtL( l, rule ), bg );
	let lo = lOf( bg );
	let hi = 1;
	for ( let i = 0; i < 48; i++ ) {
		const mid = ( lo + hi ) / 2;
		if ( f( mid ) > target ) lo = mid;
		else hi = mid;
	}
	const centre = ( lo + hi ) / 2;
	let best = { hex: hexAtL( centre, rule ), err: Math.abs( f( centre ) - target ), dist: 0 };
	for ( let d = -0.012; d <= 0.012; d += 0.0002 ) {
		const hex = hexAtL( centre + d, rule );
		const err = Math.abs( apca( hex, bg ) - target );
		if ( err < best.err - 1e-9 || ( Math.abs( err - best.err ) < 1e-9 && Math.abs( d ) < best.dist ) ) best = { hex, err, dist: Math.abs( d ) };
	}
	return best.hex;
}

function deltaE( a, b ) {
	const x = toOklab( a );
	const y = toOklab( b );
	return Math.hypot( x.l - y.l, ( x.a ?? 0 ) - ( y.a ?? 0 ), ( x.b ?? 0 ) - ( y.b ?? 0 ) );
}

const PALETTE_HEXES = Object.fromEntries(
	SCALE_FAMILIES.map( family => [ family, Object.values( scalesLight[ family ] ).map( v => formatHex( parse( v ) ) ) ] )
);

function buildScale( family ) {
	const steps = {};
	const exceptions = [];
	for ( const [ step, raw ] of Object.entries( scalesLight[ family ] ) ) {
		const light = formatHex( parse( raw ) );
		const lightLc = apca( light, LIGHT_BASE );
		const target = -lightLc;
		let dark;
		let method;
		let note;
		const pin = PINS[ family ]?.[ step ];
		if ( pin ) {
			dark = pin.value;
			method = 'pinned';
			note = pin.reason;
		} else if ( lightLc === 0 ) {
			dark = hexAtL( lOf( DARK_BASE ) + ( 1 - lOf( light ) ), chromaRule( light ) );
			method = 'mirrored-l';
			note = `Below the APCA low clip on white (Lc 0). Placed by mirroring its oklab L distance from white (${ round( 1 - lOf( light ), 3 ) }) above #1e1e1e.`;
		} else if ( family !== 'neutral' && Math.abs( lightLc ) < TINT_MIRROR_LC ) {
			const reversed = solveForLc( light, target, DARK_BASE );
			dark = hexAtL( lOf( DARK_BASE ) + ( 1 - lOf( light ) ), chromaRule( light ) );
			method = 'mirrored-l, low tint';
			note = `Low tint (light Lc ${ fmt( lightLc ) }, under ${ TINT_MIRROR_LC }). The reversed-Lc value ${ reversed } (Lc ${ fmt( apca( reversed, DARK_BASE ) ) }) was a heavy mid-tone, so the step mirrors its oklab L distance from white (${ round( 1 - lOf( light ), 3 ) }) above #1e1e1e instead.`;
			tintMoves.push( { family, step, light, lightLc, reversed, reversedLc: apca( reversed, DARK_BASE ), dark } );
		} else {
			dark = solveForLc( light, target, DARK_BASE );
			method = 'reversed-lc';
			const snap = PALETTE_HEXES[ family ]
				.filter( cand => Math.abs( apca( cand, DARK_BASE ) - target ) <= SNAP_LC && deltaE( cand, dark ) <= SNAP_DELTA_E )
				.sort( ( a, b ) => Math.abs( apca( a, DARK_BASE ) - target ) - Math.abs( apca( b, DARK_BASE ) - target ) )[ 0 ];
			if ( snap ) {
				note = `Snapped from ${ dark } to the existing newspack-colors value ${ snap }.`;
				dark = snap;
				method = 'reversed-lc, snapped';
			}
		}
		const darkLc = apca( dark, DARK_BASE );
		if ( method !== 'mirrored-l, low tint' && Math.abs( darkLc - target ) > EXACT_LC ) exceptions.push( { family, step, light, dark, lightLc, darkLc, method } );
		const o = toOklch( dark );
		steps[ step ] = {
			light,
			dark,
			lightLc: round( lightLc, 2 ),
			darkLc: round( darkLc, 2 ),
			method,
			darkOklch: { l: round( o.l, 4 ), c: round( o.c || 0, 4 ), h: round( o.h || 0, 1 ) },
			...( note ? { note } : {} ),
		};
	}
	return { steps, exceptions };
}

function overlayLc( alpha, text, base ) {
	const black = `rgba(0, 0, 0, ${ alpha })`;
	return apca( composite( black, text ), composite( black, base ) );
}

function buildOverlay() {
	const steps = {};
	let previous = 0;
	for ( const [ step, raw ] of Object.entries( scalesLight.overlay ) ) {
		const alpha = rgba255( raw )[ 3 ];
		const lightLc = overlayLc( alpha, '#1e1e1e', LIGHT_BASE );
		const target = -lightLc;
		let dark;
		let method;
		if ( lightLc === 0 ) {
			dark = round( Math.max( alpha, previous ), 3 );
			method = 'clipped, light alpha kept';
		} else if ( Math.abs( overlayLc( alpha, '#ffffff', DARK_BASE ) - target ) <= EXACT_LC ) {
			dark = round( alpha, 3 );
			method = 'light alpha already matches';
		} else {
			let lo = 0;
			let hi = 1;
			for ( let i = 0; i < 40; i++ ) {
				const mid = ( lo + hi ) / 2;
				if ( overlayLc( mid, '#ffffff', DARK_BASE ) < target ) lo = mid;
				else hi = mid;
			}
			dark = Math.round( ( ( lo + hi ) / 2 ) * 1000 ) / 1000;
			method = 'reversed-lc';
		}
		previous = dark;
		steps[ step ] = {
			light: `rgba(0, 0, 0, ${ round( alpha, 3 ) })`,
			dark: method === 'reversed-lc' ? `rgba(0, 0, 0, ${ dark })` : raw,
			lightAlpha: round( alpha, 3 ),
			darkAlpha: dark,
			lightLc: round( lightLc, 2 ),
			darkLc: round( overlayLc( dark, '#ffffff', DARK_BASE ), 2 ),
			method,
		};
	}
	return steps;
}

const scales = {};
const scaleExceptions = [];
const tintMoves = [];
for ( const family of SCALE_FAMILIES ) {
	const { steps, exceptions } = buildScale( family );
	scales[ family ] = steps;
	scaleExceptions.push( ...exceptions );
}
const overlay = buildOverlay();

const DARK_PRIMITIVES = {};
for ( const family of SCALE_FAMILIES ) {
	for ( const [ step, s ] of Object.entries( scales[ family ] ) ) DARK_PRIMITIVES[ `${ P }${ family }-${ step }` ] = s.dark;
}
for ( const [ step, s ] of Object.entries( overlay ) ) DARK_PRIMITIVES[ `${ P }overlay-${ step }` ] = s.dark;

function solveTintPressed() {
	const defs = { ...LIGHT_DEFS, ...DARK_PRIMITIVES };
	const ink = resolveColor( `${ P }error-text`, defs );
	for ( let share = 100; share >= 0; share-- ) {
		const value = `color-mix(in oklab, var(${ P }error-5) ${ share }%, var(${ P }error-0))`;
		const bg = resolveColor( value, defs );
		if ( Math.abs( apca( ink, bg, DARK_BASE ) ) >= FLOOR.text ) return { share, value };
	}
	throw new Error( 'No error-tint-pressed share reaches the text floor' );
}

const tintPressed = solveTintPressed();
if ( tintPressed.share < 100 ) OVERRIDES.push( {
	token: `${ P }error-tint-pressed`,
	value: tintPressed.value,
	reason: `Pressed destructive ghost and outline buttons put error-text (error-60) on error-tint-pressed (error-5). From the scale alone that is Lc -59.6, just under the text floor. The pressed tint keeps error-5 but mixes it toward error-0 in oklab, at the largest share (${ tintPressed.share }%) that reaches Lc 60. Light has the same pair at Lc 58.0, also under the floor.`,
} );

function solveFillForInk( seedHex, inkHex, target ) {
	const rule = chromaRule( seedHex );
	const f = l => apca( inkHex, hexAtL( l, rule ) );
	let lo = 0;
	let hi = 1;
	for ( let i = 0; i < 48; i++ ) {
		const mid = ( lo + hi ) / 2;
		if ( f( mid ) < target ) lo = mid;
		else hi = mid;
	}
	const centre = ( lo + hi ) / 2;
	let best = { hex: hexAtL( centre, rule ), err: Infinity, dist: 0 };
	for ( let d = -0.012; d <= 0.012; d += 0.0002 ) {
		const hex = hexAtL( centre + d, rule );
		const err = Math.abs( f( centre + d ) - target );
		if ( err < best.err - 1e-9 || ( Math.abs( err - best.err ) < 1e-9 && Math.abs( d ) < best.dist ) ) best = { hex, err, dist: Math.abs( d ) };
	}
	const snap = PALETTE_HEXES.error
		.filter( cand => Math.abs( apca( inkHex, cand ) - target ) <= SNAP_LC && deltaE( cand, best.hex ) <= SNAP_DELTA_E )
		.sort( ( a, b ) => Math.abs( apca( inkHex, a ) - target ) - Math.abs( apca( inkHex, b ) - target ) )[ 0 ];
	return snap || best.hex;
}

const DESTRUCTIVE_STEPS = [
	[ 'error-fill', 'error-fill' ],
	[ 'error-fill-hover', 'error-fill-hover' ],
	[ 'error-fill-pressed', 'error-fill-pressed' ],
];
const destructiveFills = DESTRUCTIVE_STEPS.map( ( [ token ] ) => {
	const seed = describe( resolveColor( `${ P }${ token }`, LIGHT_DEFS ) );
	const lightLc = apca( '#ffffff', seed );
	const dark = solveFillForInk( seed, '#ffffff', lightLc );
	return { token: `${ P }${ token }`, seed, lightLc, dark, darkLc: apca( '#ffffff', dark ), onPage: apca( dark, DARK_BASE ), hue: toOklch( dark ).h, chroma: toOklch( dark ).c, l: toOklch( dark ).l };
} );
OVERRIDES.push( {
	token: `${ P }on-error`,
	value: `var(${ P }neutral-90)`,
	reason: 'Designer call: filled error surfaces keep white text in dark, not the swapped #1e1e1e. on-error feeds destructive-ink, so the solid destructive button and its focus ring follow.',
} );
for ( const f of destructiveFills ) {
	OVERRIDES.push( {
		token: f.token,
		value: f.dark,
		reason: `Designer call: a saturated red fill with white text instead of the swapped pastel. Solved by APCA for white text at the light Lc (${ fmt( f.lightLc ) } on ${ f.seed }), hue held at ${ round( f.hue, 0 ) }° in oklch; white on it reads Lc ${ fmt( f.darkLc ) }. It lands on the newspack-colors value light already uses, so light and dark share one destructive red. destructive-fill aliases it.`,
	} );
}

const NOTICE_BORDER_FLOOR = 15;
const noticeBorders = [ 'error', 'success', 'warning' ].map( status => {
	const border = `${ P }${ status }-border`;
	const tint = `${ P }${ status }-tint`;
	const lightBorder = describe( resolveColor( border, LIGHT_DEFS ) );
	const lightTint = describe( resolveColor( tint, LIGHT_DEFS ) );
	const darkTint = describe( resolveColor( tint, { ...LIGHT_DEFS, ...DARK_PRIMITIVES } ) );
	const lightLc = apca( lightBorder, lightTint );
	const target = -Math.max( Math.abs( lightLc ), NOTICE_BORDER_FLOOR );
	let dark = solveForLc( lightBorder, target, darkTint );
	if ( Math.abs( apca( dark, darkTint ) ) < NOTICE_BORDER_FLOOR ) {
		const rule = chromaRule( lightBorder );
		let l = lOf( dark );
		while ( Math.abs( apca( dark, darkTint ) ) < NOTICE_BORDER_FLOOR && l < 1 ) {
			l += 0.0005;
			dark = hexAtL( l, rule );
		}
	}
	return { status, token: border, lightBorder, lightTint, darkTint, lightLc, target, dark, darkLc: apca( dark, darkTint ) };
} );
for ( const b of noticeBorders ) {
	OVERRIDES.push( {
		token: b.token,
		value: b.dark,
		reason: `Designer call: with the low tints mirrored, the ${ b.status } notice border nearly vanished on its tint. Light measures ${ b.lightBorder } on ${ b.lightTint } at Lc ${ fmt( b.lightLc ) }; dark takes that Lc reversed, floored at ${ NOTICE_BORDER_FLOOR } so the edge shows without becoming a mark, against its own dark tint (${ b.darkTint }), hue held. ${ b.dark } reads Lc ${ fmt( b.darkLc ) }. The tint and the ${ b.status }-5 step are unchanged.`,
	} );
}

function solveDisabledInk() {
	const defs = { ...LIGHT_DEFS, ...DARK_PRIMITIVES };
	const lightLc = apca( resolveColor( `${ P }disabled-ink`, LIGHT_DEFS ), resolveColor( `${ P }surface-subtle`, LIGHT_DEFS ), LIGHT_BASE );
	const bg = resolveColor( `${ P }surface-subtle`, defs );
	let best = null;
	for ( let share = 0; share <= 100; share++ ) {
		const value = `color-mix(in oklab, var(${ P }text) ${ share }%, var(${ P }surface))`;
		const err = Math.abs( apca( resolveColor( value, defs ), bg, DARK_BASE ) + lightLc );
		if ( ! best || err < best.err ) best = { share, value, err, lightLc };
	}
	return best;
}

const disabledInk = solveDisabledInk();
OVERRIDES.push( {
	token: `${ P }disabled-ink`,
	value: disabledInk.value,
	reason: `Disabled button text is 45% of the text color mixed into the surface. In light that reads Lc ${ fmt( disabledInk.lightLc ) } on the disabled fill; in dark the same share reads about Lc -29 and the label all but disappears. Disabled text has no floor, but it should stay as readable as in light, so the share is solved (${ disabledInk.share }%) for the light Lc reversed. Still an oklab mix of the same two tokens.`,
} );

function shareForLc( fill, ink, base, target ) {
	for ( let share = 100; share >= 0; share-- ) {
		const bg = mixOklab( parse( fill ), share, parse( base ), 100 - share );
		if ( Math.abs( apca( ink, bg ) ) < target ) return { share: share + 1, hex: describe( mixOklab( parse( fill ), share + 1, parse( base ), 100 - share - 1 ) ) };
	}
	return { share: 0, hex: base };
}

const CHART_LIGHT = [
	{ hex: '#00296e', source: 'primary-70' },
	{ hex: '#2055b0', source: 'primary-50' },
	{ hex: '#6086c7', source: 'primary-30' },
	{ hex: '#9a3412', source: null },
	{ hex: '#c2410c', source: null },
	{ hex: '#ea580c', source: null },
	{ hex: '#6c6c6c', source: 'neutral-60' },
	{ hex: '#393939', source: 'neutral-80' },
];

const chartOffset = Math.max( 0, Math.max( ...CHART_LIGHT.map( c => apca( c.hex, LIGHT_BASE ) ) ) - CHART_MAX_LC );

const NEWSPACK_COLORS = Object.fromEntries(
	[ ...fs.readFileSync( COLORS_SCSS, 'utf8' ).matchAll( /^\$([a-z]+-\d+):\s*(#[0-9a-fA-F]{3,8});/gm ) ].map( ( [ , name, hex ] ) => [ name, formatHex( parse( hex ) ) ] )
);

const charts = CHART_LIGHT.map( ( s, i ) => {
	const lightLc = apca( s.hex, LIGHT_BASE );
	const colorsStep = CHART_DARK_STEPS[ i ];
	if ( colorsStep && ! NEWSPACK_COLORS[ colorsStep ] ) throw new Error( `newspack-colors has no ${ colorsStep }` );
	const target = colorsStep ? apca( NEWSPACK_COLORS[ colorsStep ], DARK_BASE ) : -( lightLc - chartOffset );
	let dark = colorsStep ? NEWSPACK_COLORS[ colorsStep ] : solveForLc( s.hex, target, DARK_BASE );
	let value = dark;
	let snapped = null;
	const family = ! colorsStep && s.source ? s.source.split( '-' )[ 0 ] : null;
	if ( family ) {
		const match = Object.entries( scales[ family ] )
			.filter( ( [ , st ] ) => Math.abs( apca( st.dark, DARK_BASE ) - target ) <= EXACT_LC )
			.sort( ( a, b ) => Math.abs( apca( a[ 1 ].dark, DARK_BASE ) - target ) - Math.abs( apca( b[ 1 ].dark, DARK_BASE ) - target ) )[ 0 ];
		if ( match ) {
			snapped = `${ family }-${ match[ 0 ] }`;
			dark = match[ 1 ].dark;
			value = `var(${ P }${ snapped })`;
		}
	}
	const darkLc = apca( dark, DARK_BASE );
	return { token: `--newspack-ui-chart-${ i }`, light: s.hex, dark, value, source: s.source, snapped, lightLc, target, darkLc, darkHue: round( toOklch( dark ).h || 0, 1 ), darkChroma: round( toOklch( dark ).c || 0, 3 ), colorsStep };
} );

const chartPairs = [ [ 0, 3 ], [ 1, 4 ], [ 2, 5 ], [ 0, 1 ], [ 1, 2 ], [ 3, 4 ], [ 4, 5 ], [ 2, 3 ], [ 5, 6 ], [ 6, 7 ], [ 0, 7 ] ].map( ( [ a, b ] ) => ( {
	a,
	b,
	lightLc: apca( charts[ b ].light, charts[ a ].light ),
	darkLc: apca( charts[ b ].dark, charts[ a ].dark ),
	lightDE: deltaE( charts[ a ].light, charts[ b ].light ),
	darkDE: deltaE( charts[ a ].dark, charts[ b ].dark ),
} ) );

const surfaceDependents = ( () => {
	const deps = new Set( [ `${ P }surface` ] );
	let grew = true;
	while ( grew ) {
		grew = false;
		for ( const name of SEMANTIC ) {
			if ( deps.has( name ) ) continue;
			if ( [ ...deps ].some( d => LIGHT_DEFS[ name ].includes( `var(${ d })` ) || LIGHT_DEFS[ name ].includes( `var(${ d },` ) ) ) {
				deps.add( name );
				grew = true;
			}
		}
	}
	deps.delete( `${ P }surface` );
	return [ ...deps ].filter( name => ! OVERRIDES.some( o => o.token === name ) );
} )();

const SCALE_DEFS = { ...LIGHT_DEFS, ...DARK_PRIMITIVES };
const DARK_DEFS = { ...SCALE_DEFS, ...Object.fromEntries( OVERRIDES.map( o => [ o.token, o.value ] ) ) };
const ctxDefs = ( defs, ctx ) => ( RAISED[ ctx ] ? { ...defs, [ `${ P }surface` ]: RAISED[ ctx ].surface } : defs );

const t = name => `${ P }${ name }`;
const mix = ( a, pct, b ) => `color-mix(in oklab, ${ a } ${ pct }, ${ b })`;
const v = name => `var(${ t( name ) })`;

const PAIRS = [
	[ 'Body text on the page', 'text', 'surface', 'text', '.newspack-ui, notices, modal content' ],
	[ 'Muted text on the page', 'text-muted', 'surface', 'text', 'helper text, badge ink, modal footer' ],
	[ 'Subtle text on the page', 'text-subtle', 'surface', 'text', 'inactive tabs, optional labels' ],
	[ 'Placeholder in a field', 'text-subtle', 'input-bg', 'text', 'input::placeholder' ],
	[ 'Field text', '--newspack-ui-field-ink', '--newspack-ui-field-bg', 'text', 'text inputs, select' ],
	[ 'Text in a box or default notice', 'text', 'surface-subtle', 'text', '__box, __notice' ],
	[ 'Muted text in a box', 'text-muted', 'surface-subtle', 'text', '__box helper text' ],
	[ 'Default badge', 'text-muted', 'surface-sunken', 'text', '__badge' ],
	[ 'Secondary badge', 'text', 'neutral-fill', 'text', '__badge--secondary' ],
	[ 'Primary badge', 'btn-ink', 'btn', 'text', '__badge--primary' ],
	[ 'Primary button', 'btn-ink', 'btn', 'text', '__button--primary' ],
	[ 'Primary button, hover', 'button-text-hover', 'btn-hover', 'text', '__button--primary:hover' ],
	[ 'Primary button, pressed', 'btn-ink', 'btn-pressed', 'text', '__button--primary:active' ],
	[ 'Secondary button', 'text', 'neutral-fill', 'text', '__button, __button--secondary' ],
	[ 'Secondary button, hover', 'text', 'neutral-fill-hover', 'text', '__button--secondary:hover' ],
	[ 'Secondary button, pressed', 'text', 'neutral-fill-pressed', 'text', '__button--secondary:active' ],
	[ 'Ghost or outline button', 'text', 'surface', 'text', '__button--ghost, --outline' ],
	[ 'Ghost button, hover', 'text', 'neutral-fill', 'text', '__button--ghost:hover' ],
	[ 'Ghost button, pressed', 'text', 'neutral-fill-hover', 'text', '__button--ghost:active' ],
	[ 'Ghost-light button', 'text-muted', 'surface', 'text', '__button--ghost-light' ],
	[ 'Ghost-light button, hover', 'text-muted', 'neutral-fill', 'text', '__button--ghost-light:hover' ],
	[ 'Primary-light button', 'contrast', 'base', 'text', '__button--primary-light' ],
	[ 'Primary-light button, hover', 'contrast', mix( v( 'base' ), v( 'mix-hover' ), v( 'contrast' ) ), 'text', '__button--primary-light:hover' ],
	[ 'Primary-light button, pressed', 'contrast', mix( v( 'base' ), v( 'mix-pressed' ), v( 'contrast' ) ), 'text', '__button--primary-light:active' ],
	[ 'Destructive button', 'destructive-ink', 'destructive-fill', 'text', '__button--destructive' ],
	[ 'Destructive button, hover', 'destructive-ink', 'destructive-fill-hover', 'text', '__button--destructive:hover' ],
	[ 'Destructive button, pressed', 'destructive-ink', 'destructive-fill-pressed', 'text', '__button--destructive:active' ],
	[ 'Destructive ghost button', 'destructive-text', 'surface', 'text', '__button--destructive.--ghost' ],
	[ 'Destructive ghost, hover', 'destructive-text', 'error-tint-hover', 'text', '__button--destructive.--ghost:hover' ],
	[ 'Destructive ghost, pressed', 'error-text', 'error-tint-pressed', 'text', '__button--destructive.--ghost:active' ],
	[ 'Ghost-dark button on inverse', 'inverse-ink', 'inverse-bg', 'text', '__button--ghost-dark in a snackbar' ],
	[ 'Ghost-dark button, hover', 'inverse-ink', mix( v( 'inverse-bg' ), v( 'mix-hover' ), v( 'inverse-ink' ) ), 'text', '__button--ghost-dark:hover' ],
	[ 'Snackbar', 'inverse-ink', 'inverse-bg', 'text', '__snackbar__item' ],
	[ 'Highlighted option', 'on-selected', 'selected', 'text', 'select2 highlighted option' ],
	[ 'Selected option', 'text', 'neutral-fill', 'text', 'select2 selected option' ],
	[ 'Segmented control, inactive', 'text-muted', 'neutral-fill', 'text', '__segmented-control__tabs button' ],
	[ 'Inline error and required mark', 'error-text', 'surface', 'text', '__inline-error, __required' ],
	[ 'Error badge', 'error-text', 'error-tint', 'text', '__badge--error' ],
	[ 'Success badge', 'success-text', 'success-tint', 'text', '__badge--success' ],
	[ 'Warning badge', 'warning-text', 'warning-tint', 'text', '__badge--warning' ],
	[ 'Error notice text', 'text', 'error-tint', 'text', '__notice--error' ],
	[ 'Success notice text', 'text', 'success-tint', 'text', '__notice--success' ],
	[ 'Warning notice text', 'text', 'warning-tint', 'text', '__notice--warning' ],
	[ 'Modal text', 'text', 'surface', 'text', '__modal', 4 ],
	[ 'Modal footer text', 'text-muted', 'surface', 'text', '__modal__footer', 4 ],
	[ 'Modal field text', '--newspack-ui-field-ink', '--newspack-ui-field-bg', 'text', 'field inside a modal', 4 ],
	[ 'Dropdown item', 'text', 'surface', 'text', '__dropdown__content', 2 ],
	[ 'Disabled button text', 'disabled-ink', 'surface-subtle', 'disabled', '__button:disabled' ],
	[ 'Disabled field text', 'text-subtle', 'input-bg-disabled', 'disabled', 'input:disabled' ],
	[ 'Field border', 'input-border', 'input-bg', 'mark', 'text inputs, select, checkbox edge' ],
	[ 'Field border, hover', 'input-border-hover', 'input-bg', 'mark', 'input:hover' ],
	[ 'Field border, focus', 'input-border-focus', 'input-bg', 'mark', 'input:focus' ],
	[ 'Field border in a box', 'text-subtle', 'surface-subtle', 'mark', '__box field override' ],
	[ 'Field border in a modal', 'input-border', 'input-bg', 'mark', 'field inside a modal', 4 ],
	[ 'Focus ring', 'focus', 'surface', 'mark', 'focus-edge, focus-line' ],
	[ 'Focus ring in a modal', 'focus', 'surface', 'mark', 'focus inside a modal', 4 ],
	[ 'Focus ring on the primary button', 'focus-on-brand', 'btn', 'mark', '__button--primary:focus-visible' ],
	[ 'Focus ring on the destructive button', 'destructive-ink', 'destructive-fill', 'mark', '__button--destructive:focus-visible' ],
	[ 'Checkbox, checked', 'mark', 'surface', 'mark', 'input:checked fill' ],
	[ 'Check glyph', 'on-mark', 'mark', 'mark', 'input:checked glyph' ],
	[ 'Selected card edge', 'selected', 'surface', 'mark', '__input-card:has(:checked)' ],
	[ 'Tab indicator', 'selected', 'surface', 'mark', '__tabs selected' ],
	[ 'Outline button edge', mix( v( 'text' ), v( 'border-outline-mix' ), 'transparent' ), 'surface', 'mark', '__button--outline, __badge--outline' ],
	[ 'Destructive outline edge', mix( v( 'destructive-text' ), '70%', 'transparent' ), 'surface', 'mark', '__button--destructive.--outline' ],
	[ 'Error field border', 'error', 'input-bg', 'mark', '__field-error' ],
	[ 'Error icon', 'error', 'surface', 'mark', '__icon--error, status' ],
	[ 'Error notice icon', 'error', 'error-tint', 'mark', '__notice--error svg' ],
	[ 'Success icon', 'success', 'surface', 'mark', '__icon--success, status' ],
	[ 'Success notice icon', 'success', 'success-tint', 'mark', '__notice--success svg' ],
	[ 'Warning dot', 'warning-dot', 'surface', 'mark', '__status--warning, __badge--warning' ],
	[ 'Warning notice icon', 'warning-dot', 'warning-tint', 'mark', '__notice--warning svg' ],
	[ 'Default notice icon', 'text-muted', 'surface-subtle', 'mark', '__notice svg' ],
	[ 'Default status dot', 'text-subtle', 'surface', 'mark', '__status, __badge--dot' ],
	[ 'Snackbar icon', 'inverse-ink', 'inverse-bg', 'mark', '__snackbar__icon' ],
	[ 'Divider and card border', 'border', 'surface', 'decor', 'hr, __box--border, tables' ],
	[ 'Notice border', 'border', 'surface-subtle', 'decor', '__notice' ],
	[ 'Error notice border', 'error-border', 'error-tint', 'border', '__notice--error' ],
	[ 'Success notice border', 'success-border', 'success-tint', 'border', '__notice--success' ],
	[ 'Warning notice border', 'warning-border', 'warning-tint', 'border', '__notice--warning' ],
	[ 'Disabled field border', 'input-border-disabled', 'input-bg-disabled', 'decor', 'input:disabled' ],
	[ 'Focus halo', 'focus-halo', 'surface', 'decor', 'focus halo' ],
	[ 'Link underline', mix( v( 'text' ), '50%', v( 'surface' ) ), 'surface', 'decor', '__link' ],
	[ 'Box fill against the page', 'surface-subtle', 'surface', 'decor', '__box, __notice' ],
	[ 'Sunken fill against the page', 'surface-sunken', 'surface', 'decor', '__badge, disabled fields' ],
	[ 'Neutral fill against the page', 'neutral-fill', 'surface', 'decor', 'secondary button' ],
	[ 'Destructive fill against the page', 'destructive-fill', 'surface', 'decor', '__button--destructive' ],
].map( ( [ label, fg, bg, role, where, ctx = 0 ] ) => ( {
	label,
	fg: fg.startsWith( '--' ) || fg.includes( '(' ) || fg === 'transparent' ? fg : t( fg ),
	bg: bg.startsWith( '--' ) || bg.includes( '(' ) ? bg : t( bg ),
	role,
	where,
	ctx,
} ) );

function evalPair( pair, defs, page ) {
	const d = ctxDefs( defs, pair.ctx );
	const bg = resolveColor( pair.bg, d );
	const fg = resolveColor( pair.fg, d, `${ P }text` );
	return { lc: apca( fg, bg, page ), fg: describe( fg ), bg: describe( composite( bg, page ) ) };
}

const pairResults = PAIRS.map( pair => {
	const light = evalPair( pair, LIGHT_DEFS, LIGHT_BASE );
	const scale = evalPair( pair, SCALE_DEFS, DARK_BASE );
	const dark = evalPair( pair, DARK_DEFS, DARK_BASE );
	const floor = FLOOR[ pair.role ] ?? null;
	const pass = floor === null ? null : Math.abs( dark.lc ) >= floor - 1e-9;
	return { ...pair, light, scale, dark, floor, pass, drift: Math.abs( dark.lc ) - Math.abs( light.lc ) };
} );

const raisedSurfaces = Object.entries( RAISED ).map( ( [ level, r ] ) => {
	const c = resolveColor( r.surface, DARK_DEFS );
	return { level, ...r, hex: describe( c ), lcOnPage: apca( c, DARK_BASE ), lDelta: lOf( describe( c ) ) - lOf( DARK_BASE ) };
} );

const edgeOnPage = apca( resolveColor( '--newspack-ui-elevation-edge', DARK_DEFS ), DARK_BASE );
const scrim = overlay[ 50 ];
const scrimmedPage = composite( scrim.dark, DARK_BASE );
const modalSurface = describe( resolveColor( RAISED[ 4 ].surface, DARK_DEFS ) );
const modalOnScrim = apca( modalSurface, scrimmedPage );
const lightModalOnScrim = apca( '#ffffff', composite( scrim.light, LIGHT_BASE ) );
const edgeOverScrim = composite( resolveColor( '--newspack-ui-elevation-edge', DARK_DEFS ), scrimmedPage );
const edgeOnScrimLc = apca( edgeOverScrim, scrimmedPage );

const hueOf = hex => toOklch( hex ).h;
const hueGap = ( a, b ) => {
	const d = Math.abs( a - b ) % 360;
	return d > 180 ? 360 - d : d;
};
const brandHue = hueOf( scales.primary[ 60 ].dark );
const statusHues = {
	error: hueOf( scales.error[ 50 ].dark ),
	success: hueOf( scales.success[ 50 ].dark ),
	warning: hueOf( scales.warning[ 40 ].dark ),
};

const decl = ( name, value ) => `\t${ name }: ${ value };`;
const ALIAS_LINES = SEMANTIC.filter( name => ! OVERRIDES.some( o => o.token === name ) ).map( name => decl( name, LIGHT_DEFS[ name ] ) );

function blocks( root ) {
	const out = [];
	out.push( `${ root } {` );
	out.push( '\tcolor-scheme: dark;' );
	for ( const [ name, value ] of Object.entries( DARK_PRIMITIVES ) ) out.push( decl( name, value ) );
	out.push( ...ALIAS_LINES );
	for ( const o of OVERRIDES ) out.push( decl( o.token, o.value ) );
	for ( const c of charts ) out.push( decl( c.token, c.value ) );
	out.push( '\tbackground-color: var(--newspack-ui-color-body-bg);' );
	out.push( '\tcolor: var(--newspack-ui-color-text);' );
	out.push( '}' );
	out.push( '' );
	out.push( '@media (prefers-contrast: more) {' );
	out.push( `\t${ root } {` );
	for ( const [ name, value ] of Object.entries( MEDIA_CONTRAST ) ) out.push( `\t${ decl( name, value ) }` );
	out.push( '\t}' );
	out.push( '}' );
	for ( const r of Object.values( RAISED ) ) {
		out.push( '' );
		out.push( `${ root } ${ r.selector } {` );
		out.push( decl( `${ P }surface`, r.surface ) );
		for ( const name of surfaceDependents ) out.push( decl( name, LIGHT_DEFS[ name ] ) );
		out.push( '}' );
		const mediaDeps = Object.keys( MEDIA_CONTRAST ).filter( name => surfaceDependents.includes( name ) );
		if ( mediaDeps.length ) {
			out.push( '' );
			out.push( '@media (prefers-contrast: more) {' );
			out.push( `\t${ root } ${ r.selector } {` );
			for ( const name of mediaDeps ) out.push( `\t${ decl( name, MEDIA_CONTRAST[ name ] ) }` );
			out.push( '\t}' );
			out.push( '}' );
		}
	}
	return out;
}

const indent = lines => lines.map( l => ( l ? `\t${ l }` : l ) );
const css = [ ...blocks( DARK_SELECTOR ), '', '@media (prefers-color-scheme: dark) {', ...indent( blocks( AUTO_SELECTOR ) ), '}', '' ].join( '\n' );

const darkTokens = { ...DARK_PRIMITIVES };
for ( const name of SEMANTIC ) if ( ! OVERRIDES.some( o => o.token === name ) ) darkTokens[ name ] = LIGHT_DEFS[ name ];
for ( const o of OVERRIDES ) darkTokens[ o.token ] = o.value;
for ( const c of charts ) darkTokens[ c.token ] = c.value;

const darkScales = {
	lightBase: LIGHT_BASE,
	darkBase: DARK_BASE,
	rule: 'Dark step N has the signed APCA Lc of light step N on white, with the sign flipped, measured on #1e1e1e. Steps below the APCA low clip mirror their oklab L distance from white above #1e1e1e. Hue kept in oklch; chroma kept and reduced only to stay in sRGB.',
	tolerance: { exactLc: EXACT_LC, snapLc: SNAP_LC, snapDeltaE: SNAP_DELTA_E },
	scales: { ...scales, overlay },
	exceptions: scaleExceptions.map( e => ( { ...e, lightLc: round( e.lightLc, 2 ), darkLc: round( e.darkLc, 2 ) } ) ),
};

fs.writeFileSync( path.join( OUT, 'dark-scales.json' ), JSON.stringify( darkScales, null, '\t' ) + '\n' );
fs.writeFileSync( path.join( OUT, 'dark-tokens.json' ), JSON.stringify( darkTokens, null, '\t' ) + '\n' );
fs.writeFileSync( path.join( OUT, 'dark.css' ), css );

const tableRow = cells => `| ${ cells.join( ' | ' ) } |`;
const verdict = r => ( r.pass === null ? ( r.role === 'disabled' ? 'exempt' : 'no floor' ) : r.pass ? 'pass' : '**fail**' );
const failures = pairResults.filter( r => r.pass === false );
const scaleOnlyFailures = pairResults.filter( r => r.floor !== null && Math.abs( r.scale.lc ) < r.floor );
const changedByOverride = pairResults.filter( r => Math.abs( r.scale.lc - r.dark.lc ) > 0.05 );
const bigDrift = pairResults.filter( r => r.role !== 'decor' && r.drift < -10 );

const lightSemantic = Object.fromEntries( SEMANTIC.map( n => [ n, ( () => {
	try {
		return describe( resolveColor( n, LIGHT_DEFS, `${ P }text` ) );
	} catch {
		return null;
	}
} )() ] ) );
const darkSemantic = Object.fromEntries( SEMANTIC.map( n => [ n, ( () => {
	try {
		return describe( resolveColor( n, DARK_DEFS, `${ P }text` ) );
	} catch {
		return null;
	}
} )() ] ) );

const report = [];
report.push( '# Newspack UI Neutral: dark palette report' );
report.push( '' );
report.push( 'Generated by `generate.mjs`. Every number below uses the APCA 0.0.98G-4g formula with its W3 constants, computed in `generate.mjs`. Color mixes are evaluated in oklab, translucent colors are composited in 8-bit sRGB over the surface they sit on, as a browser does.' );
report.push( '' );
report.push( '## Method' );
report.push( '' );
report.push( `- Light is measured on white (${ LIGHT_BASE }), dark on neutral-900 (${ DARK_BASE }).` );
report.push( '- Each primitive scale (neutral, primary, success, error, warning) gets a dark scale with the same step names. Dark step N has the signed Lc of light step N, sign flipped. For example light neutral-50 is Lc 57.1 on white, so dark neutral-50 is Lc -57.1 on #1e1e1e.' );
report.push( `- The solver keeps the oklch hue and searches lightness. Chroma keeps the light step's chroma, reduced only where the color would leave sRGB, so high-lightness dark steps never glow more than their light step. It is raised only where that would leave a step grayer than half of the light step's relative saturation (its chroma as a share of the most sRGB allows at that lightness and hue). That floor lifts the dark tints and borders (error-0, error-5, primary-0 and similar) that otherwise went muddy at mid lightness. The result is rounded to hex and the nearest hex to the target Lc is kept.` );
report.push( `- Steps that APCA clips to Lc 0 on white (neutral-5, neutral-10 and the step-0 tints of success, error and warning) cannot be placed by Lc. They mirror their oklab L distance from white above #1e1e1e instead (dark L = 0.235 + (1 - light L)), keeping hue and chroma. They also measure Lc 0 on #1e1e1e, so the "same number reversed" rule still holds for them.` );
report.push( `- Low tints on the hue scales (light Lc under ${ TINT_MIRROR_LC }: primary-0, primary-5, success-5, error-5, warning-5) use the same mirrored lightness rule. APCA's curve is steep near a dark base, so the reversed Lc put them on heavy mid-tones (#5d7296, #8f4f57, #406a48, #5d4f15). ${ TINT_MIRROR_LC } is the line because, in newspack-ui, every hue step under it is a tint or a decorative border (error-border, error-tint-pressed, success-border, warning-border); the first steps above it (primary-10 at 40, warning-30 at 43) are mark-weight colors and keep the reversed rule. Neutral is left out: neutral-20 to -40 (Lc 16 to 27) are the border and disabled-edge grays, which need their reversed weight to stay visible on the dark page. See "What the tint rule moved".` );
report.push( `- A solved value snaps to an existing newspack-colors value of the same scale when that value is within ${ SNAP_LC } Lc of the target and within ${ SNAP_DELTA_E } oklab distance. Any step more than ${ EXACT_LC } Lc from its target is logged as an exception.` );
report.push( '- Dark mode is then the primitives redefined under the opt-in selectors. The semantic tokens keep their light aliases; `dark.css` re-declares them unchanged because a custom property resolves its `var()` where it is declared, so aliases computed on `:root` would otherwise keep the light values.' );
report.push( '- Every real foreground and background pair below is then measured three ways: light, dark from the scale swap alone, and dark after the few explicit overrides.' );
report.push( '' );
report.push( '## Pinned values' );
report.push( '' );
for ( const [ family, pins ] of Object.entries( PINS ) ) {
	for ( const [ step, pin ] of Object.entries( pins ) ) {
		const s = scales[ family ][ step ];
		report.push( `- **${ family }-${ step }** = \`${ pin.value }\` (light \`${ s.light }\`, light Lc ${ fmt( s.lightLc ) }, dark Lc ${ fmt( s.darkLc ) }). ${ pin.reason }` );
	}
}
const onFill = [
	[ 'Primary button and primary badge ink', 'btn-ink' ],
	[ 'Primary button fill', 'btn' ],
	[ 'Snackbar ink', 'inverse-ink' ],
	[ 'Snackbar fill', 'inverse-bg' ],
	[ 'Destructive button ink', 'destructive-ink' ],
	[ 'Check glyph and highlighted option ink', 'on-mark' ],
	[ 'Text on the brand color', 'against-primary' ],
];
report.push( '- On-fill text follows from the pins without extra overrides, because every on-fill token aliases neutral-0 or neutral-90:' );
for ( const [ label, name ] of onFill ) report.push( `  - ${ label } (\`${ t( name ) }\`): light \`${ lightSemantic[ t( name ) ] }\`, dark \`${ darkSemantic[ t( name ) ] }\`.` );
report.push( '' );
report.push( '## Dark scales' );
report.push( '' );
for ( const family of [ ...SCALE_FAMILIES ] ) {
	report.push( `### ${ family }` );
	report.push( '' );
	report.push( tableRow( [ 'Step', 'Light', 'Light Lc', 'Dark', 'Dark Lc', 'Method' ] ) );
	report.push( tableRow( [ '---', '---', '---:', '---', '---:', '---' ] ) );
	for ( const [ step, s ] of Object.entries( scales[ family ] ) ) report.push( tableRow( [ step, `\`${ s.light }\``, fmt( s.lightLc ), `\`${ s.dark }\``, fmt( s.darkLc ), s.method ] ) );
	report.push( '' );
}
report.push( '### overlay' );
report.push( '' );
report.push( 'The overlay steps are black at an alpha, so they cannot be placed by their own Lc against a base. They are placed by what they do: page text (#1e1e1e on white in light, white on #1e1e1e in dark) is measured under the overlay, and the dark alpha is solved so the covered text keeps the same Lc, reversed. A step whose covered text clips to Lc 0 in light keeps its light alpha, or the previous dark alpha if that is higher, so the scale stays in order.' );
report.push( '' );
report.push( tableRow( [ 'Step', 'Light alpha', 'Covered text Lc, light', 'Dark alpha', 'Covered text Lc, dark', 'Method' ] ) );
report.push( tableRow( [ '---', '---:', '---:', '---:', '---:', '---' ] ) );
for ( const [ step, s ] of Object.entries( overlay ) ) report.push( tableRow( [ step, s.lightAlpha, fmt( s.lightLc ), s.darkAlpha, fmt( s.darkLc ), s.method ] ) );
report.push( '' );
report.push( '### Scale exceptions' );
report.push( '' );
if ( scaleExceptions.length ) {
	for ( const e of scaleExceptions ) report.push( `- ${ e.family }-${ e.step }: target ${ fmt( -e.lightLc ) }, got ${ fmt( e.darkLc ) } (${ e.method }).` );
} else {
	report.push( `None. Every step lands within ${ EXACT_LC } Lc of its reversed target, and the pinned and mirrored steps match exactly or within rounding.` );
}
report.push( '' );
report.push( '### What the tint rule moved' );
report.push( '' );
report.push( tableRow( [ 'Step', 'Light', 'Light Lc', 'Reversed-Lc value', 'Its Lc', 'Now', 'Now Lc' ] ) );
report.push( tableRow( [ '---', '---', '---:', '---', '---:', '---', '---:' ] ) );
for ( const m of tintMoves ) report.push( tableRow( [ `${ m.family }-${ m.step }`, `\`${ m.light }\``, fmt( m.lightLc ), `\`${ m.reversed }\``, fmt( m.reversedLc ), `\`${ m.dark }\``, fmt( apca( m.dark, DARK_BASE ) ) ] ) );
report.push( '' );
const tintTokens = new Set( tintMoves.map( m => `${ m.family }-${ m.step }` ) );
const tintAliases = SEMANTIC.filter( n => [ ...tintTokens ].some( st => LIGHT_DEFS[ n ].includes( `${ P }${ st })` ) ) );
report.push( `Tokens that read these steps: ${ tintAliases.map( n => `\`${ n.slice( P.length ) }\`` ).join( ', ' ) }. Pairs that use them: ${ pairResults.filter( r => tintAliases.some( n => r.fg === n || r.bg === n ) ).map( r => `${ r.label } (${ fmt( r.dark.lc ) }, ${ verdict( r ) })` ).join( '; ' ) }. The destructive ghost pressed pair, which failed at Lc -59.6 on the old error-5, now passes at the scale alone, so its override is gone. From the scale alone the notice borders dropped to Lc 0 on their tints and nearly vanished; they now have their own overrides (see Overrides).` );
report.push( '' );
report.push( '## Every pair' );
report.push( '' );
report.push( `Floors: text Lc ${ FLOOR.text }, marks Lc ${ FLOOR.mark }, colored notice borders Lc ${ FLOOR.border } in dark (they have no floor in light, where they read 8.5 to 13.9). Decorative pairs (dividers, fills, halo) and disabled states have no floor and are listed for reference. "Scale only" is the dark value before the overrides in the next section.` );
report.push( '' );
report.push( tableRow( [ 'Pair', 'Foreground', 'Background', 'Role', 'Light Lc', 'Dark Lc, scale only', 'Dark Lc', 'Dark colors', 'Result' ] ) );
report.push( tableRow( [ '---', '---', '---', '---', '---:', '---:', '---:', '---', '---' ] ) );
for ( const r of pairResults ) {
	const short = s => ( s.startsWith( P ) ? s.slice( P.length ) : s.startsWith( '--newspack-ui-' ) ? s.slice( 14 ) : s.replaceAll( 'var(--newspack-ui-color-', 'var(' ) );
	const where = r.ctx ? ` (${ RAISED[ r.ctx ].label })` : '';
	report.push(
		tableRow( [
			r.label + where,
			`\`${ short( r.fg ) }\``,
			`\`${ short( r.bg ) }\``,
			r.role,
			fmt( r.light.lc ),
			fmt( r.scale.lc ),
			fmt( r.dark.lc ),
			`${ r.dark.fg } on ${ r.dark.bg }`,
			verdict( r ),
		] )
	);
}
report.push( '' );
report.push( `${ pairResults.length } pairs: ${ pairResults.filter( r => r.role === 'text' ).length } text, ${ pairResults.filter( r => r.role === 'mark' ).length } marks, ${ pairResults.filter( r => r.role === 'border' ).length } notice borders, ${ pairResults.filter( r => r.role === 'decor' ).length } decorative, ${ pairResults.filter( r => r.role === 'disabled' ).length } disabled. ${ failures.length ? `${ failures.length } fail: ${ failures.map( f => f.label ).join( ', ' ) }.` : 'None fails its floor.' } ${ scaleOnlyFailures.length ? `Before the overrides, ${ scaleOnlyFailures.length } failed: ${ scaleOnlyFailures.map( f => f.label ).join( ', ' ) }.` : 'None failed before the overrides either.' }` );
report.push( '' );
if ( bigDrift.length ) {
	report.push( 'Pairs that lose more than 10 Lc against light (still above their floor unless marked above):' );
	report.push( '' );
	for ( const r of bigDrift ) report.push( `- ${ r.label }: ${ fmt( r.light.lc ) } in light, ${ fmt( r.dark.lc ) } in dark.` );
	report.push( '' );
}
report.push( '## Overrides' );
report.push( '' );
report.push( 'Only these tokens get a dark value of their own. Everything else is the scale swap.' );
report.push( '' );
for ( const o of OVERRIDES ) report.push( `- \`${ o.token }: ${ o.value }\`. ${ o.reason }` );
report.push( `- Pairs whose Lc the overrides change: ${ changedByOverride.length ? changedByOverride.map( r => r.label ).join( ', ' ) : 'none (the overrides only touch shadows and the caret)' }.` );
report.push( '' );
report.push( '## Surfaces and elevation' );
report.push( '' );
report.push( `- Page background: \`${ DARK_BASE }\` (neutral-0 in the dark scale).` );
report.push( '- Elevation 1 (cards, boxes) stays on the page surface, as in light, where boxes are a fill or a border rather than a raised layer.' );
for ( const r of raisedSurfaces ) report.push( `- Elevation ${ r.level }, ${ r.label }: surface \`${ r.surface }\` = \`${ r.hex }\`, oklab L +${ round( r.lDelta, 3 ) } over the page (APCA reads Lc ${ fmt( r.lcOnPage ) }, below its clip, as for any two near-black grays). Taken from the mirrored tint steps, so the raised surfaces stay on the scale. The surface-derived tokens (subtle, sunken, fills, input background) are re-declared in that scope so boxes, fields and fills inside it step up with it.` );
report.push( '- Elevation 3 is the snackbar, which uses the inverse pair and becomes a white bar with #1e1e1e text in dark. Same inversion as light, where it is a #1e1e1e bar on a white page.' );
report.push( `- Shadows: black ink at 2.5 times the light alphas, plus a 14% white hairline on every elevated surface (\`${ composite( resolveColor( '--newspack-ui-elevation-edge', DARK_DEFS ), DARK_BASE ) }\` on the page, oklab L +${ round( lOf( composite( resolveColor( '--newspack-ui-elevation-edge', DARK_DEFS ), DARK_BASE ) ) - lOf( DARK_BASE ), 3 ) }; APCA reads Lc ${ fmt( edgeOnPage ) } because both are below its clip). The four steps keep their light offsets and blurs: elevation 1 reads 15% and 15% black, 2 reads 20% and 35%, 3 reads 20% and 50%, 4 reads 20% and 60%.` );
report.push( '' );
report.push( '## Scrim' );
report.push( '' );
report.push( `- Light: \`${ scrim.light }\`. Page text under it measures Lc ${ fmt( scrim.lightLc ) }; a white dialog against the scrimmed page measures Lc ${ fmt( lightModalOnScrim ) }.` );
report.push( `- Dark: overlay-50 becomes \`${ scrim.dark }\`, which pushes the page text back to Lc ${ fmt( scrim.darkLc ) }, the same amount reversed. Plain 50% black gives Lc ${ fmt( overlayLc( 0.5, '#ffffff', DARK_BASE ) ) }, close enough that the difference is invisible; the solved value is kept so the overlay scale follows one rule.` );
report.push( '- Judgment call: no higher alpha. The plan expected one, but measured by what it does to the page behind, 50% black already pushes a dark page back as far as it pushes a light one. A heavier scrim would not help the dialog edge either (see below), and would hide more of the page than light does.' );
report.push( `- The dialog itself (\`${ modalSurface }\`) against the scrimmed page (\`${ scrimmedPage }\`) measures Lc ${ fmt( modalOnScrim ) }: APCA cannot separate two near-blacks, so the separation in dark comes from the raised surface, the hairline edge (Lc ${ fmt( edgeOnScrimLc ) } on the scrim) and the dimmed page, not from the scrim alone.` );
report.push( '' );
report.push( '## Hover and pressed mixes' );
report.push( '' );
report.push( '- The mixes keep their light formulas and stay in oklab. Their direction falls out of the swap: neutral fills mix the text color into the surface, which is white into #1e1e1e in dark, so hover and pressed get lighter. The primary button mixes its fill toward the surface, which is white toward #1e1e1e, so hover and pressed get darker, the mirror of light where #1e1e1e mixes toward white.' );
report.push( `- Primary button: white fill (\`${ darkSemantic[ t( 'btn' ) ] }\`) with #1e1e1e text, hover \`${ darkSemantic[ t( 'btn-hover' ) ] }\`, pressed \`${ darkSemantic[ t( 'btn-pressed' ) ] }\`. Lc ${ fmt( pairResults.find( r => r.label === 'Primary button' ).dark.lc ) } normal, ${ fmt( pairResults.find( r => r.label === 'Primary button, hover' ).dark.lc ) } hover, ${ fmt( pairResults.find( r => r.label === 'Primary button, pressed' ).dark.lc ) } pressed. Light is ${ fmt( pairResults.find( r => r.label === 'Primary button' ).light.lc ) }, ${ fmt( pairResults.find( r => r.label === 'Primary button, hover' ).light.lc ) } and ${ fmt( pairResults.find( r => r.label === 'Primary button, pressed' ).light.lc ) }.` );
const hoverMatch = shareForLc( '#ffffff', '#1e1e1e', '#1e1e1e', Math.abs( pairResults.find( r => r.label === 'Primary button, hover' ).light.lc ) );
const pressedMatch = shareForLc( '#ffffff', '#1e1e1e', '#1e1e1e', Math.abs( pairResults.find( r => r.label === 'Primary button, pressed' ).light.lc ) );
report.push( `- Judgment call: the hover and pressed shares (85%, 75%) stay as in light. Mixing in oklab moves lightness by the same amount in both modes (15% and 25% of the white to #1e1e1e distance), so the state change looks the same size. APCA reads the dark text on the lighter fills lower than light does (Lc 81 and 68 against 100 and 93), but both stay well above 60. Matching the light Lc exactly would need ${ hoverMatch.share }% (\`${ hoverMatch.hex }\`) for hover and ${ pressedMatch.share }% (\`${ pressedMatch.hex }\`) for pressed, which would make the hover hard to see on a white button.` );
report.push( '- The same reasoning keeps the 60% outline edge and the 30% focus halo: their dark Lc is lower than light (see the drift list above) but the edge stays well above Lc 25 and the halo only backs up a full-strength ring. The disabled ink is the exception, see Overrides.' );
report.push( '' );
report.push( '## Status colors' );
report.push( '' );
report.push( `- Dark hues (oklch): error-50 ${ round( statusHues.error, 0 ) }°, success-50 ${ round( statusHues.success, 0 ) }°, warning-40 ${ round( statusHues.warning, 0 ) }°. Neutral has no brand hue: its accent is gray. The brand scale (primary-60, ${ round( brandHue, 0 ) }°) sits ${ round( hueGap( brandHue, statusHues.error ), 0 ) }° from error and ${ round( hueGap( brandHue, statusHues.success ), 0 ) }° from success, well past the 25° threshold Newspack_UI_Palette uses.` );
report.push( '- newspack-colors has no info scale and newspack-ui has no info role. The default notice (neutral tint, muted icon) plays that part, and its pairs are in the table.' );
report.push( '' );
report.push( '## Chart series' );
report.push( '' );
report.push( `The Insights series are only used as marks (strokes, fills, legend swatches), never as text, so Lc 25 is the floor; Lc 60 is shown for reference. Designer call: series 0 to 5 take existing newspack-colors steps, read from the package (\`colors.module.scss\`), instead of computed values. The blues are primary-100, -200 and -300; the ambers are quaternary-500, -600 and -700. Every dark series is then an existing token, the blue and amber tiers carry similar weight, and blue against amber stays the color-blind-robust pairing Insights uses in light. The earlier computed series left a pale, washed-out blue tier against much stronger oranges. Series 6 and 7 keep the dark neutral grays: the light Lc minus a shared ${ fmt( chartOffset ) } offset, which lands them on dark neutral-50 and neutral-60. The newspack-colors steps use the light scale's names (primary-100 is light primary-10), and the dark scale redefines those tokens, so \`dark.css\` writes them as hex.` );
report.push( '' );
report.push( tableRow( [ 'Token', 'Light', 'Light Lc on white', 'Dark', 'Dark Lc on #1e1e1e', 'Dark chroma', 'Value', 'Mark (25)', 'Text (60)' ] ) );
report.push( tableRow( [ '---', '---', '---:', '---', '---:', '---:', '---', '---', '---' ] ) );
for ( const c of charts ) report.push( tableRow( [ c.token, `\`${ c.light }\``, fmt( c.lightLc ), `\`${ c.dark }\``, fmt( c.darkLc ), c.darkChroma, c.colorsStep ? `newspack-colors ${ c.colorsStep }` : c.snapped ? `\`var(--newspack-ui-color-${ c.snapped })\`` : 'solved, hue kept', Math.abs( c.darkLc ) >= 25 ? 'pass' : 'fail', Math.abs( c.darkLc ) >= 60 ? 'pass' : 'fail' ] ) );
report.push( '' );
report.push( 'Order within each family is kept: series 0, 1, 2 (blue) and 3, 4, 5 (amber) run from most to least contrast in both modes, which in dark means lightest to darkest. None is used as text, so the Lc 60 column is informational.' );
report.push( '' );
report.push( 'Series next to each other, measured as one mark against the other (APCA) and as oklab distance. Insights pairs series 0 with 3 when it compares two series.' );
report.push( '' );
report.push( tableRow( [ 'Series', 'Light Lc', 'Light oklab distance', 'Dark Lc', 'Dark oklab distance' ] ) );
report.push( tableRow( [ '---', '---:', '---:', '---:', '---:' ] ) );
for ( const c of chartPairs ) report.push( tableRow( [ `${ c.a } and ${ c.b }`, fmt( c.lightLc ), round( c.lightDE, 3 ), fmt( c.darkLc ), round( c.darkDE, 3 ) ] ) );
report.push( '' );
const pair03 = chartPairs.find( c => c.a === 0 && c.b === 3 );
const pair45 = chartPairs.find( c => c.a === 4 && c.b === 5 );
report.push( `Series 0 and 3 (light blue against amber): Lc ${ fmt( pair03.darkLc ) } between them, oklab distance ${ round( pair03.darkDE, 3 ) } (light: ${ fmt( pair03.lightLc ) } and ${ round( pair03.lightDE, 3 ) }). They are about the same lightness, as navy and rust are in light, so they separate by hue. The closest pair is 4 and 5 (oklab ${ round( pair45.darkDE, 3 ) }, against ${ round( pair45.lightDE, 3 ) } in light), two neighboring amber steps, left as the designer chose them.` );
report.push( '' );
report.push( '## Semantic tokens, light and dark' );
report.push( '' );
report.push( tableRow( [ 'Token', 'Alias', 'Light', 'Dark' ] ) );
report.push( tableRow( [ '---', '---', '---', '---' ] ) );
for ( const n of SEMANTIC ) {
	const ov = OVERRIDES.find( o => o.token === n );
	report.push( tableRow( [ `\`${ n }\``, `\`${ ( ov ? ov.value : LIGHT_DEFS[ n ] ).replaceAll( '|', '\\|' ) }\``, lightSemantic[ n ] ? `\`${ lightSemantic[ n ] }\`` : 'n/a', darkSemantic[ n ] ? `\`${ darkSemantic[ n ] }\`` : 'n/a' ] ) );
}
report.push( '' );
report.push( '## Left unchanged' );
report.push( '' );
report.push( '- Mix shares (`--newspack-ui-color-mix-hover` 85%, `--newspack-ui-color-mix-pressed` 75%, `--newspack-ui-color-border-outline-mix` 60%, `--newspack-ui-focus-halo-mix` 30%): the swap already gives each mix the right direction and the pairs above pass with them.' );
report.push( '- `--newspack-ui-color-destructive-edge` and `--newspack-ui-color-divider-end` stay transparent.' );
report.push( '- `--newspack-ui-color-primary` and `--newspack-ui-color-against-primary` keep their aliases. With no theme color they read the dark primary scale. A site whose theme sets `--newspack-theme-color-primary` keeps its own brand color in dark, which this palette cannot know.' );
report.push( '- The secondary, tertiary and quaternary scales of newspack-colors are not aliased by newspack-ui, so they get no dark scale here.' );
report.push( '' );
report.push( '## For a designer to look at' );
report.push( '' );
report.push( '- Body text is pure white on #1e1e1e (Lc -106), as asked. It is the strongest contrast APCA can report on this base. Long reading at that level can halate for some readers; APCA guidance puts comfortable dark-mode body text nearer Lc 90 (about dark neutral-80 here). I kept it pinned to white.' );
report.push( '- The destructive button keeps the light red fill and white text in dark (designer call). It is the one filled surface that does not swap, and its hover and pressed steps get darker in both modes. The destructive ghost and outline buttons keep the swapped light red text.' );
report.push( '- The snackbar and the select highlight invert to white with #1e1e1e text in dark, as the inverse role implies.' );
report.push( '- The primary-light button turns dark in dark mode, since it is "the base color on a contrast surface"; it only appears on inverse surfaces, which are white in dark.' );
report.push( '- Chart series 4 and 5 (quaternary-600 and -700) are close neighbors in dark; next to each other in a pie they deserve a look. The chart series are the one place the palette departs from the reversed-Lc rule.' );
report.push( '- The tints (step 0 of each status scale and neutral-5/10) sit below the APCA clip in both modes, so their placement is a lightness rule, not a contrast measurement.' );
report.push( '' );

fs.writeFileSync( path.join( OUT, 'report.md' ), report.join( '\n' ) );

const STATE_MAP = [
	[ ':hover', '.is-hover' ],
	[ ':active', '.is-active' ],
	[ ':focus-visible', '.is-focus' ],
];
const stateRoot = postcss.parse( fs.readFileSync( CSS_PATH, 'utf8' ) );
const stateOut = postcss.root();
stateRoot.walkRules( rule => {
	if ( rule.parent.type === 'atrule' && /keyframes/.test( rule.parent.name ) ) return;
	const selectors = rule.selectors.filter( s => /newspack-ui__button|input|select|textarea/.test( s ) && STATE_MAP.some( ( [ pseudo ] ) => s.includes( pseudo ) ) && ! /:not\([^)]*:(hover|active|focus-visible)/.test( s ) );
	if ( ! selectors.length ) return;
	const mapped = selectors.map( s => STATE_MAP.reduce( ( acc, [ pseudo, cls ] ) => acc.replaceAll( pseudo, cls ), s ) );
	const clone = rule.clone( { selector: mapped.join( ',' ) } );
	if ( rule.parent.type === 'atrule' ) {
		const at = rule.parent.clone( { nodes: [] } );
		at.append( clone );
		stateOut.append( at );
	} else {
		stateOut.append( clone );
	}
} );
fs.writeFileSync( path.join( OUT, 'preview-states.css' ), stateOut.toString() + '\n' );

const lightChartCss = CHART_LIGHT.map( ( c, i ) => `--newspack-ui-chart-${ i }: ${ c.hex };` ).join( ' ' );

function strip() {
	const fam = [ ...SCALE_FAMILIES, 'overlay' ];
	const rows = fam
		.map( family => {
			const steps = family === 'overlay' ? overlay : scales[ family ];
			const cell = ( mode, s ) => {
				const value = s[ mode ];
				const lc = mode === 'light' ? s.lightLc : s.darkLc;
				const swatch = family === 'overlay' ? `<span class="sw" style="background:${ value }"><span class="sw-text">Aa</span></span>` : `<span class="sw" style="background:${ value }"></span>`;
				return `<div class="cell">${ swatch }<span class="lc">${ fmt( lc ) }</span><span class="hex">${ family === 'overlay' ? round( mode === 'light' ? s.lightAlpha : s.darkAlpha, 3 ) : value }</span></div>`;
			};
			const row = mode =>
				`<div class="strip strip--${ mode }"><div class="strip-label">${ family } · ${ mode }</div>${ Object.entries( steps )
					.map( ( [ step, s ] ) => `<div class="col"><span class="step">${ step }</span>${ cell( mode, s ) }</div>` )
					.join( '' ) }</div>`;
			return `<div class="strip-pair">${ row( 'light' ) }${ row( 'dark' ) }</div>`;
		} )
		.join( '' );
	return rows;
}

const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm-.75 4h1.5v5h-1.5V8Zm0 6.5h1.5V16h-1.5v-1.5Z"/></svg>';
const CHECK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"/></svg>';
const CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="m13.06 12 4.47-4.47-1.06-1.06L12 10.94 7.53 6.47 6.47 7.53 10.94 12l-4.47 4.47 1.06 1.06L12 13.06l4.47 4.47 1.06-1.06L13.06 12Z"/></svg>';

function buttons() {
	const variants = [
		[ 'Primary', 'newspack-ui__button--primary' ],
		[ 'Secondary', 'newspack-ui__button--secondary' ],
		[ 'Ghost', 'newspack-ui__button--ghost' ],
		[ 'Outline', 'newspack-ui__button--outline' ],
		[ 'Ghost light', 'newspack-ui__button--ghost-light' ],
		[ 'Delete', 'newspack-ui__button--destructive' ],
		[ 'Delete', 'newspack-ui__button--destructive newspack-ui__button--ghost' ],
		[ 'Delete', 'newspack-ui__button--destructive newspack-ui__button--outline' ],
	];
	const states = [
		[ '', '' ],
		[ 'is-hover', 'hover' ],
		[ 'is-active', 'pressed' ],
		[ 'is-focus', 'focus' ],
		[ 'disabled', 'disabled' ],
	];
	return `<table class="btn-grid"><thead><tr><th></th>${ states.map( ( [ , l ] ) => `<th>${ l || 'rest' }</th>` ).join( '' ) }</tr></thead><tbody>${ variants
		.map(
			( [ label, cls ] ) =>
				`<tr><th>${ cls.replace( /newspack-ui__button--/g, '' ) }</th>${ states
					.map( ( [ state ] ) => `<td><button type="button" class="newspack-ui__button newspack-ui__button--small ${ cls } ${ state === 'disabled' ? '' : state }"${ state === 'disabled' ? ' disabled' : '' }>${ label }</button></td>` )
					.join( '' ) }</tr>`
		)
		.join( '' ) }</tbody></table>`;
}

function chart() {
	const n = CHART_LIGHT.length;
	const bars = CHART_LIGHT.map( ( c, i ) => {
		const h = 40 + ( ( i * 37 ) % 60 );
		return `<rect x="${ 12 + i * 34 }" y="${ 120 - h }" width="24" height="${ h }" rx="3" style="fill:var(--newspack-ui-chart-${ i })"/>`;
	} ).join( '' );
	const lines = CHART_LIGHT.map( ( c, i ) => {
		const pts = Array.from( { length: 8 }, ( _, k ) => `${ 12 + k * 38 },${ 20 + ( ( ( i * 13 + k * ( 7 + i ) ) % 70 ) + i * 4 ) }` ).join( ' ' );
		return `<polyline points="${ pts }" fill="none" stroke-width="2" style="stroke:var(--newspack-ui-chart-${ i })"/>`;
	} ).join( '' );
	const legend = CHART_LIGHT.map( ( c, i ) => `<span class="legend"><i style="background:var(--newspack-ui-chart-${ i })"></i>Series ${ i }</span>` ).join( '' );
	return `<svg viewBox="0 0 ${ 12 + n * 34 } 124" class="chart" role="img" aria-label="Bars">${ bars }</svg><svg viewBox="0 0 290 124" class="chart" role="img" aria-label="Lines">${ lines }</svg><div class="legends">${ legend }</div>`;
}

function side( scheme ) {
	return `<section class="newspack-ui side" data-color-scheme="${ scheme }">
<h2 class="side-title">${ scheme === 'dark' ? 'Dark' : 'Light' }</h2>
<h3>Text on surfaces</h3>
<div class="surfaces">
<div class="surf"><p>Page surface. Body text sits here.</p><p class="newspack-ui__helper-text">Muted helper text.</p><p class="subtle">Subtle text for optional labels.</p></div>
<div class="newspack-ui__box"><p>Box on surface-subtle.</p><p class="newspack-ui__helper-text">Muted text in a box.</p></div>
<div class="newspack-ui__box newspack-ui__box--border"><p>Bordered box on the page.</p><p class="newspack-ui__helper-text">Card style.</p></div>
<div class="newspack-ui__dropdown__content preview-static"><ul><li><button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small">Account</button></li><li><button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small is-hover">Hovered item</button></li></ul></div>
</div>
<h3>Buttons</h3>
${ buttons() }
<div class="inverse-demo newspack-ui__snackbar preview-static"><div class="newspack-ui__snackbar__item active"><div class="newspack-ui__snackbar__icon">${ CHECK }</div><div class="newspack-ui__snackbar__content">Snackbar on the inverse surface.</div><button type="button" class="newspack-ui__button newspack-ui__button--ghost-dark newspack-ui__button--icon newspack-ui__button--x-small newspack-ui__snackbar__close is-hover" aria-label="Close">${ CLOSE }</button></div></div>
<h3>Inputs</h3>
<div class="fields">
<label for="f-${ scheme }-1">Email</label><input id="f-${ scheme }-1" type="text" placeholder="you@example.com">
<input type="text" class="is-hover" value="Hovered field" aria-label="Hovered field">
<input type="text" class="is-focus" value="Focused field" aria-label="Focused field">
<input type="text" disabled value="Disabled field" aria-label="Disabled field">
<div class="newspack-ui__field-error"><input type="text" value="Invalid field" aria-invalid="true" aria-label="Invalid field"><p class="newspack-ui__inline-error">This field is required.</p></div>
<select aria-label="Select"><option>Select an option</option></select>
<div class="checks"><label><input type="checkbox"> Unchecked</label><label><input type="checkbox" checked> Checked</label><label><input type="radio" name="r-${ scheme }" checked> Radio</label><label><input type="checkbox" class="is-focus" checked> Focused</label><label><input type="checkbox" disabled> Disabled</label></div>
<label class="newspack-ui__input-card"><input type="checkbox" checked> <strong>Selected card</strong></label>
</div>
<h3>Notices</h3>
<div class="newspack-ui__notice">${ ICON }<div>Default notice. Plays the info role.</div></div>
<div class="newspack-ui__notice newspack-ui__notice--success">${ ICON }<div>Success notice with <a href="#">a link</a>.</div></div>
<div class="newspack-ui__notice newspack-ui__notice--warning">${ ICON }<div>Warning notice.</div></div>
<div class="newspack-ui__notice newspack-ui__notice--error">${ ICON }<div>Error notice.</div></div>
<h3>Badges and status</h3>
<div class="badges"><span class="newspack-ui__badge">Default</span><span class="newspack-ui__badge newspack-ui__badge--primary">Primary</span><span class="newspack-ui__badge newspack-ui__badge--secondary">Secondary</span><span class="newspack-ui__badge newspack-ui__badge--outline">Outline</span><span class="newspack-ui__badge newspack-ui__badge--success newspack-ui__badge--dot">Success</span><span class="newspack-ui__badge newspack-ui__badge--warning newspack-ui__badge--dot">Warning</span><span class="newspack-ui__badge newspack-ui__badge--error newspack-ui__badge--dot">Error</span></div>
<div class="badges"><span class="newspack-ui__status newspack-ui__status--success">${ CHECK }Active</span><span class="newspack-ui__status newspack-ui__status--warning">${ ICON }Pending</span><span class="newspack-ui__status newspack-ui__status--error">${ ICON }Failed</span><a class="newspack-ui__link" href="#">Text link</a></div>
<h3>Dialog over the page</h3>
<div class="stage">
<div class="stage-page"><p><strong>Page content</strong> behind the dialog.</p><p>Body copy stays legible enough to place you, but recedes.</p><div class="newspack-ui__box newspack-ui__box--border"><p>A card on the page.</p></div></div>
<div class="newspack-ui__modal-container preview-modal" data-state="open"><div class="newspack-ui__modal-container__overlay"></div><div class="newspack-ui__modal newspack-ui__modal--small"><header class="newspack-ui__modal__header"><h2>Sign in</h2><button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--icon newspack-ui__modal__close" aria-label="Close">${ CLOSE }</button></header><section class="newspack-ui__modal__content"><p>Dialog body text on the raised surface.</p><div class="newspack-ui__notice">${ ICON }<div>A notice inside the dialog.</div></div><input type="text" placeholder="Email address" aria-label="Email address"><button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">Continue</button></section><footer class="newspack-ui__modal__footer"><p>Muted footer text.</p></footer></div></div>
</div>
<h3>Chart series</h3>
${ chart() }
</section>`;
}

const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Neutral dark palette</title>
<link rel="stylesheet" href="${ CSS_HREF }">
<link rel="stylesheet" href="preview-states.css">
<link rel="stylesheet" href="dark.css">
<style>
:root { ${ lightChartCss } --frame: #8a8a8a; }
body { margin: 0; background: #e9e9e9; font-family: Inter, system-ui, sans-serif; color: #1e1e1e; }
.page-head { padding: 24px 24px 8px; }
.page-head h1 { font-size: 22px; margin: 0 0 4px; }
.page-head p { margin: 0; font-size: 14px; color: #5d5d5d; max-width: 70ch; }
.strips { padding: 8px 24px 24px; display: grid; gap: 12px; }
.strip-pair { display: grid; gap: 0; border-radius: 10px; overflow: hidden; }
.strip { display: grid; grid-template-columns: 140px repeat(12, minmax(0, 1fr)); align-items: start; padding: 10px 12px; gap: 6px; }
.strip--light { background: #fff; color: #1e1e1e; }
.strip--dark { background: #1e1e1e; color: #fff; }
.strip-label { font-size: 12px; font-weight: 600; padding-top: 18px; }
.col { display: grid; gap: 3px; justify-items: center; font-size: 10px; }
.step { opacity: .7; }
.cell { display: grid; gap: 2px; justify-items: center; }
.sw { width: 100%; min-width: 34px; height: 30px; border-radius: 6px; display: grid; place-items: center; }
.strip--light .sw { box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
.strip--dark .sw { box-shadow: inset 0 0 0 1px rgba(255,255,255,.06); }
.sw-text { font-size: 13px; font-weight: 600; }
.strip--light .sw-text { color: #1e1e1e; mix-blend-mode: normal; }
.strip--dark .sw-text { color: #fff; }
.lc { font-variant-numeric: tabular-nums; font-weight: 600; }
.hex { opacity: .7; font-variant-numeric: tabular-nums; }
.sides { display: grid; grid-template-columns: 1fr 1fr; }
.side[data-color-scheme="light"] { background: #fff; }
.side { padding: 24px; min-width: 0; }
.side-title { margin: 0 0 8px; font-size: 20px; }
.side h3 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; margin: 28px 0 10px; color: var(--newspack-ui-color-text-muted); }
.surfaces { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.surfaces > * { margin: 0 !important; }
.surf { padding: 16px; }
.surf p, .newspack-ui__box p { margin: 0 0 4px; }
.subtle { color: var(--newspack-ui-color-text-subtle); }
.preview-static { position: static !important; display: block !important; opacity: 1 !important; visibility: visible !important; transform: none !important; pointer-events: auto !important; }
.newspack-ui__dropdown__content.preview-static ul { list-style: none; margin: 0; padding: 4px; }
.inverse-demo { padding: 12px 0 0 !important; }
.inverse-demo .newspack-ui__snackbar__item { position: relative; }
.btn-grid { border-collapse: collapse; width: 100%; }
.btn-grid th { font-size: 11px; font-weight: 500; text-align: left; color: var(--newspack-ui-color-text-muted); padding: 4px; }
.btn-grid td { padding: 4px; }
.fields { display: grid; gap: 10px; max-width: 420px; }
.fields input, .fields select { width: 100%; }
.fields .newspack-ui__field-error p { margin: 4px 0 0; }
.checks { display: flex; flex-wrap: wrap; gap: 12px; }
.badges { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 10px; }
.stage { position: relative; height: 430px; border-radius: 12px; overflow: hidden; outline: 1px solid var(--newspack-ui-color-border); }
.stage-page { padding: 20px; background: var(--newspack-ui-color-surface); height: 100%; box-sizing: border-box; }
.preview-modal { position: absolute !important; inset: 0 !important; height: 100% !important; }
.preview-modal .newspack-ui__modal { margin: 0 24px; }
.chart { width: 48%; height: auto; display: inline-block; vertical-align: top; }
.legends { display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px; margin-top: 8px; }
.legend { display: inline-flex; align-items: center; gap: 6px; }
.legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
@media (max-width: 900px) { .sides { grid-template-columns: 1fr; } .strip { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<header class="page-head"><h1>Neutral dark palette</h1><p>Light scales on white, dark scales on #1e1e1e. The number under each swatch is its signed APCA Lc against that base; overlay swatches show the Lc of page text under the overlay. Below, the real newspack-ui styles in light and in dark.</p></header>
<div class="strips">${ strip() }</div>
<div class="sides">${ side( 'light' ) }${ side( 'dark' ) }</div>
</body>
</html>
`;
fs.writeFileSync( path.join( OUT, 'preview.html' ), html );

console.log( `Scales: ${ SCALE_FAMILIES.map( f => `${ f } ${ Object.keys( scales[ f ] ).length }` ).join( ', ' ) }, overlay ${ Object.keys( overlay ).length }` );
console.log( `Scale exceptions: ${ scaleExceptions.length }` );
console.log( `Pairs: ${ pairResults.length }, failing: ${ failures.length }, failing before overrides: ${ scaleOnlyFailures.length }` );
for ( const f of failures ) console.log( `  FAIL ${ f.label }: ${ fmt( f.dark.lc ) } (${ f.dark.fg } on ${ f.dark.bg })` );
