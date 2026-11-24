import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..' );
const motionSource = readFileSync( join( root, '..', 'motion', 'index.js' ), 'utf8' );
const motion = await import( `data:text/javascript,${ encodeURIComponent( motionSource ) }` );
const kit = await import( '../registry/components/deck/deck-motion.ts' );
const navigation = await import( '../registry/components/deck/deck-navigation.ts' );

test( 'the deck motion values are the newspack-motion values', () => {
	for ( const name of [ 'EASE_DRAWER', 'DURATION_MODAL', 'TRAVEL_BASE_DISTANCE', 'TRAVEL_PX_PER_MS', 'DURATION_TRAVEL_MAX', 'REDUCED_MOTION_QUERY' ] ) {
		assert.equal( kit[ name ], motion[ name ], name );
	}
	for ( const distance of [ 0, 120, 240, 241, 300, 410, 632, 800, 964, 1040, 5000, Infinity, NaN ] ) {
		for ( const base of [ undefined, 150, 250, 500 ] ) {
			assert.equal( kit.travelDuration( distance, base ), motion.travelDuration( distance, base ), `${ distance }px from ${ base }` );
		}
	}
} );

test( 'the tokens the deck reads are in the built themes with the newspack-motion values', () => {
	for ( const file of [ 'theme.css', 'brand.css' ] ) {
		const css = readFileSync( join( root, 'dist', file ), 'utf8' );
		assert.ok( css.includes( `${ kit.DURATION_TOKEN }: ${ motion.DURATION_MODAL }ms;` ), `${ file } duration` );
		assert.ok( css.includes( `${ kit.EASING_TOKEN }: ${ motion.EASE_DRAWER };` ), `${ file } easing` );
	}
} );

test( 'cubicBezier evaluates the curve the token defines', () => {
	const [ x1, y1, x2, y2 ] = [ 0.32, 0.72, 0, 1 ];
	const ease = kit.parseEasing( motion.EASE_DRAWER );
	const point = ( a, b, t ) => 3 * ( 1 - t ) ** 2 * t * a + 3 * ( 1 - t ) * t ** 2 * b + t ** 3;
	for ( let i = 0; i <= 100; i++ ) {
		const t = i / 100;
		assert.ok( Math.abs( ease( point( x1, x2, t ) ) - point( y1, y2, t ) ) < 1e-6, `t=${ t }` );
	}
	assert.equal( ease( 0 ), 0 );
	assert.equal( ease( 1 ), 1 );
	const linear = kit.parseEasing( 'linear' );
	assert.ok( Math.abs( linear( 0.3 ) - 0.3 ) < 1e-6 );
	const keyword = kit.parseEasing( 'ease' );
	const explicit = kit.parseEasing( 'cubic-bezier(0.25, 0.1, 0.25, 1)' );
	assert.ok( Math.abs( keyword( 0.4 ) - explicit( 0.4 ) ) < 1e-9 );
	assert.equal( kit.parseEasing( 'steps(4)' ), null );
	assert.equal( kit.parseEasing( 'cubic-bezier(1.2, 0, 0, 1)' ), null );
} );

test( 'readDeckMotionTokens follows a theme override and falls back without the theme', () => {
	const style = values => ( { getPropertyValue: name => values[ name ] ?? '' } );
	const overridden = kit.readDeckMotionTokens( style( { [ kit.DURATION_TOKEN ]: ' 0.3s', [ kit.EASING_TOKEN ]: ' linear' } ) );
	assert.equal( overridden.base, 300 );
	assert.ok( Math.abs( overridden.easing( 0.5 ) - 0.5 ) < 1e-6 );
	const missing = kit.readDeckMotionTokens( style( {} ) );
	assert.equal( missing.base, motion.DURATION_MODAL );
	assert.equal( missing.easing( 0.5 ), kit.parseEasing( motion.EASE_DRAWER )( 0.5 ) );
} );

const setup = ( { reduced = false, height = 800, length = 8000 } = {} ) => {
	let time = 0;
	let nextId = 0;
	const frames = new Map();
	const writes = [];
	const scroller = {
		scrollTop: 0,
		scrollHeight: length,
		clientHeight: height,
		style: { scrollSnapType: '' },
		scrollTo( { top, behavior } ) {
			writes.push( behavior );
			this.scrollTop = top;
		},
	};
	const env = {
		now: () => time,
		requestFrame: callback => {
			frames.set( ++nextId, callback );
			return nextId;
		},
		cancelFrame: id => frames.delete( id ),
		reducedMotion: () => reduced,
		tokens: () => ( { base: motion.DURATION_MODAL, easing: kit.parseEasing( motion.EASE_DRAWER ) } ),
	};
	const advance = ms => {
		const end = time + ms;
		while ( time < end ) {
			time = Math.min( end, time + 16 );
			const due = [ ...frames.entries() ];
			frames.clear();
			due.forEach( ( [ , callback ] ) => callback() );
		}
	};
	return { scroller, frames, writes, advance, motion: kit.createDeckMotion( scroller, env ), now: () => time };
};

test( 'a scripted scroll lasts the travel duration for its distance', () => {
	const ease = kit.parseEasing( motion.EASE_DRAWER );
	for ( const distance of [ 200, 800, 1200, 2400 ] ) {
		const { scroller, motion: deck, advance } = setup();
		deck.scrollTo( distance, 1 );
		const duration = motion.travelDuration( distance );
		assert.equal( scroller.style.scrollSnapType, 'none', 'snapping is off in flight' );
		advance( 112 );
		assert.ok( Math.abs( scroller.scrollTop - distance * ease( 112 / duration ) ) < 1e-6, `${ distance }px follows the curve` );
		advance( duration - 112 - 16 );
		assert.ok( deck.animating && scroller.scrollTop < distance, `${ distance }px still moving before ${ duration }ms` );
		assert.equal( deck.pending, 1 );
		advance( 16 );
		assert.equal( scroller.scrollTop, distance );
		assert.equal( deck.animating, false, `${ distance }px done at ${ duration }ms` );
		assert.equal( deck.pending, null );
		assert.equal( scroller.style.scrollSnapType, '' );
	}
	assert.equal( motion.travelDuration( 200 ), 250 );
	assert.equal( motion.travelDuration( 800 ), 390 );
	assert.equal( motion.travelDuration( 2400 ), 450 );
} );

test( 'every write is instant, so a smooth scroll-behavior on the page cannot slow it down', () => {
	const { motion: deck, advance, writes } = setup();
	deck.scrollTo( 800 );
	advance( 500 );
	assert.ok( writes.length > 1 && writes.every( behavior => behavior === 'instant' ) );
} );

test( 'reduced motion jumps at once', () => {
	const { scroller, motion: deck, frames } = setup( { reduced: true } );
	deck.scrollTo( 1600, 2 );
	assert.equal( scroller.scrollTop, 1600 );
	assert.equal( frames.size, 0 );
	assert.equal( deck.animating, false );
	assert.equal( deck.pending, null );
	assert.equal( scroller.style.scrollSnapType, '' );
} );

test( 'a new target mid-scroll starts again from where the scroll is', () => {
	const { scroller, motion: deck, frames, advance } = setup();
	deck.scrollTo( 800, 1 );
	advance( 160 );
	const from = scroller.scrollTop;
	assert.ok( from > 0 && from < 800 );
	deck.scrollTo( 1600, 2 );
	assert.equal( frames.size, 1, 'one frame loop' );
	assert.equal( deck.destination(), 1600 );
	assert.equal( deck.pending, 2 );
	const duration = motion.travelDuration( 1600 - from );
	advance( 16 );
	assert.ok( scroller.scrollTop > from, 'moves on from the current position' );
	advance( duration - 32 );
	assert.ok( scroller.scrollTop < 1600, 'timed from the retarget' );
	advance( 16 );
	assert.equal( scroller.scrollTop, 1600 );
	assert.equal( deck.animating, false );
} );

test( 'cancel stops the scroll where it is and restores snapping', () => {
	const { scroller, motion: deck, frames, advance } = setup();
	deck.scrollTo( 1600, 2 );
	advance( 100 );
	const at = scroller.scrollTop;
	deck.cancel();
	advance( 600 );
	assert.equal( scroller.scrollTop, at );
	assert.equal( frames.size, 0 );
	assert.equal( deck.pending, null );
	assert.equal( deck.destination(), at );
	assert.equal( scroller.style.scrollSnapType, '' );
	scroller.style.scrollSnapType = 'y mandatory';
	deck.cancel();
	assert.equal( scroller.style.scrollSnapType, 'y mandatory', 'cancel with nothing in flight leaves the style alone' );
} );

test( 'targets are clamped to the scroll range', () => {
	const { scroller, motion: deck, advance } = setup( { length: 2000, height: 800 } );
	deck.scrollTo( 5000 );
	advance( 1000 );
	assert.equal( scroller.scrollTop, 1200 );
	deck.scrollTo( -400 );
	advance( 1000 );
	assert.equal( scroller.scrollTop, 0 );
} );

const key = ( key, extra = {} ) => ( { key, shiftKey: false, ctrlKey: false, metaKey: false, altKey: false, defaultPrevented: false, ...extra } );
const element = matches => ( { closest: selector => ( matches.includes( selector ) ? {} : null ) } );

test( 'deckKeyAction keeps the deck keys and cancels on the scrolling keys it leaves to the browser', () => {
	assert.deepEqual( navigation.deckKeyAction( key( 'ArrowDown' ), null ), { type: 'step', direction: 1 } );
	assert.deepEqual( navigation.deckKeyAction( key( 'PageUp' ), null ), { type: 'step', direction: -1 } );
	assert.deepEqual( navigation.deckKeyAction( key( ' ' ), null ), { type: 'step', direction: 1 } );
	assert.deepEqual( navigation.deckKeyAction( key( ' ', { shiftKey: true } ), null ), { type: 'step', direction: -1 } );
	assert.deepEqual( navigation.deckKeyAction( key( 'Home' ), null ), { type: 'jump', to: 'first' } );
	assert.deepEqual( navigation.deckKeyAction( key( 'End' ), null ), { type: 'jump', to: 'last' } );
	assert.deepEqual( navigation.deckKeyAction( key( 'ArrowDown', { metaKey: true } ), null ), { type: 'cancel' } );
	assert.deepEqual( navigation.deckKeyAction( key( 'ArrowDown', { shiftKey: true } ), null ), { type: 'cancel' } );
	assert.equal( navigation.deckKeyAction( key( 'ArrowDown', { defaultPrevented: true } ), null ), null );
	assert.equal( navigation.deckKeyAction( key( 'a' ), null ), null );
	assert.equal( navigation.deckKeyAction( key( 'ArrowDown' ), element( [ navigation.IGNORED_TARGETS ] ) ), null );
	assert.equal( navigation.deckKeyAction( key( ' ' ), element( [ navigation.ACTIVATABLE_TARGETS ] ) ), null );
	assert.deepEqual( navigation.deckKeyAction( key( 'ArrowDown' ), element( [ navigation.ACTIVATABLE_TARGETS ] ) ), { type: 'step', direction: 1 } );
} );

test( 'slideScrollTop and atDestination measure from where the scroll is going', () => {
	const scroller = { scrollTop: 300, getBoundingClientRect: () => ( { top: 0, bottom: 800 } ) };
	const slide = { getBoundingClientRect: () => ( { top: 500, bottom: 1300 } ) };
	assert.equal( navigation.slideScrollTop( scroller, slide ), 800 );
	assert.deepEqual( navigation.atDestination( slide, 500 ).getBoundingClientRect(), { top: 0, bottom: 800 } );
	const viewport = { clientHeight: 800, getBoundingClientRect: () => ( { top: 0, bottom: 800 } ) };
	const tall = { getBoundingClientRect: () => ( { top: -200, bottom: 1800 } ) };
	const next = { getBoundingClientRect: () => ( { top: 1800, bottom: 2600 } ) };
	assert.deepEqual( navigation.planDeckStep( viewport, [ tall, next ].map( box => navigation.atDestination( box, 720 ) ), 1 ), { type: 'scroll', top: 280 } );
} );

test( 'the deck no longer relies on native smooth scrolling and its dot fades on the color tokens', () => {
	const css = readFileSync( join( root, 'registry', 'styles', 'newspack-deck.css' ), 'utf8' );
	assert.ok( ! css.includes( 'scroll-behavior' ) );
	const deck = readFileSync( join( root, 'registry', 'components', 'deck', 'deck.tsx' ), 'utf8' );
	assert.ok( ! /behavior:\s*'smooth'|scrollBy\(/.test( deck ) );
	const nav = readFileSync( join( root, 'registry', 'components', 'deck', 'deck-nav.tsx' ), 'utf8' );
	assert.ok( nav.includes( 'background-color_var(--newspack-ui-duration-color)_var(--newspack-ui-ease-color)' ) );
	assert.ok( nav.includes( 'scale_var(--newspack-ui-duration-press)_var(--newspack-ui-ease-out)' ) );
	assert.ok( ! /\btransition-colors\b/.test( nav ) );
} );

const COMPONENT_VALUES = [ { file: 'newspack-deck.css', declaration: '--newspack-deck-closing-logo-pause: 300ms;' } ];

const TOKEN_DURATION = /^var\(--newspack-ui-duration-[a-z]+\)$/;
const TOKEN_EASE = /^var\(--newspack-ui-ease-[a-z]+\)$/;

function motionProblems( text, file = '' ) {
	const problems = [];
	let source = text;
	for ( const { file: home, declaration } of COMPONENT_VALUES ) {
		if ( file.endsWith( `/${ home }` ) && source.split( declaration ).length === 2 ) {
			source = source.replace( declaration, '' );
		}
	}
	const classes = source.match( /[^\s"'`{}]+/g ) ?? [];
	for ( const raw of classes ) {
		const utility = raw.replace( /^(?:[a-z-]+(?:-\[[^\]]*\])?:)+/, '' );
		if ( /^(?:transition|transition-(?:all|colors|opacity|shadow|transform|discrete))$/.test( utility ) ) {
			problems.push( `${ raw }: a Tailwind transition utility with its default properties` );
		}
		if ( /^transition-\[/.test( utility ) && /\ball\b/.test( utility ) ) {
			problems.push( `${ raw }: transition all` );
		}
		if ( /^(?:duration|ease|delay)-/.test( utility ) && ! /^(?:duration-\(--newspack-ui-duration-[a-z]+\)|ease-\(--newspack-ui-ease-[a-z]+\))$/.test( utility ) ) {
			problems.push( `${ raw }: a duration, easing or delay that is not a newspack-motion token` );
		}
		if ( /^animate-/.test( utility ) && utility !== 'animate-none' ) {
			problems.push( `${ raw }: a Tailwind animation` );
		}
		const bracket = /\[transition:([^\]]*)\]/.exec( raw );
		if ( bracket ) {
			for ( const part of bracket[ 1 ].split( ',' ) ) {
				const [ property, duration, easing, ...rest ] = part.split( '_' );
				if ( property === 'all' || ! TOKEN_DURATION.test( duration ?? '' ) || ! TOKEN_EASE.test( easing ?? '' ) || rest.length ) {
					problems.push( `${ raw }: ${ part } is not a named property on a duration and an easing token` );
				}
			}
		}
	}
	for ( const element of source.match( /className=(?:"[^"]*"|\{[^}]*\})/g ) ?? [] ) {
		if ( /\btransition-\[/.test( element ) && ! ( /duration-\(--newspack-ui-duration-/.test( element ) && /ease-\(--newspack-ui-ease-/.test( element ) ) ) {
			problems.push( `${ element.slice( 0, 60 ) }: transition-[…] without a duration and an easing token` );
		}
	}
	const code = source.replace( /\/\*[\s\S]*?\*\/|\/\/.*$|^\s*\*.*$/gm, '' );
	if ( /\b\d+(?:\.\d+)?m?s\b(?![-\w])/.test( code ) || /cubic-bezier\(/.test( code ) ) {
		problems.push( 'a literal duration or cubic-bezier()' );
	}
	return problems;
}

test( 'the motion check catches untokenized motion', () => {
	for ( const bad of [ 'className="transition"', 'className="transition-transform"', 'className="transition-colors"', 'className="duration-150"', 'className="ease-out"', 'className="hover:ease-in"', 'className="animate-spin"', 'className="transition-[all]"', 'className="[transition:scale_160ms_ease-out]"', 'className="transition-[scale] duration-(--newspack-ui-duration-press)"', 'const x = "cubic-bezier(0.2, 0, 0, 1)"', 'const x = "200ms"' ] ) {
		assert.ok( motionProblems( bad ).length, bad );
	}
	assert.deepEqual( motionProblems( 'className="transition-[scale] duration-(--newspack-ui-duration-press) ease-(--newspack-ui-ease-out) motion-reduce:transition-none [transition:scale_var(--newspack-ui-duration-press)_var(--newspack-ui-ease-out)]"' ), [] );
} );

test( 'the closing logo pause is the one documented component value the motion check accepts', () => {
	const file = join( root, 'registry', 'styles', 'newspack-deck.css' );
	const css = readFileSync( file, 'utf8' );
	const [ { declaration } ] = COMPONENT_VALUES;
	assert.equal( css.split( declaration ).length, 2, 'declared once, in the deck stylesheet' );
	assert.deepEqual( motionProblems( css, file ), [] );
	assert.ok( motionProblems( css ).length, 'the same declaration outside its file is a literal duration' );
	assert.ok( motionProblems( `${ css }\n${ declaration }`, file ).length, 'declared twice, it is no longer the documented value' );
	assert.ok( motionProblems( css.replace( '300ms', '250ms' ), file ).length, 'another value is not the documented one' );
	assert.ok( motionProblems( `${ css }\n.newspack-deck { transition-delay: 300ms; }`, file ).length, 'other literals in the file still fail' );
	for ( const doc of [ 'README.md', 'DECISIONS.md' ] ) {
		assert.ok( readFileSync( join( root, doc ), 'utf8' ).includes( '`--newspack-deck-closing-logo-pause`' ), `${ doc } documents it` );
	}
} );

test( 'every transition in the deck and brand files uses named properties and newspack-motion tokens', () => {
	const deck = join( root, 'registry', 'components', 'deck' );
	const files = [
		...readdirSync( deck ).filter( file => file !== 'deck-motion.ts' ).map( file => join( deck, file ) ),
		join( root, 'registry', 'components', 'brand', 'newspack-logo.tsx' ),
		join( root, 'registry', 'styles', 'newspack-deck.css' ),
	];
	for ( const file of files ) {
		assert.deepEqual( motionProblems( readFileSync( file, 'utf8' ), file ), [], file );
	}
	const brand = JSON.parse( readFileSync( join( root, 'dist', 'registry', 'newspack-brand.json' ), 'utf8' ) );
	const declarations = [];
	const walk = node => Object.entries( node ).forEach( ( [ key, value ] ) => ( typeof value === 'object' ? walk( value ) : declarations.push( [ key, value ] ) ) );
	walk( brand.css );
	const brandCss = readFileSync( join( root, 'dist', 'brand.css' ), 'utf8' );
	assert.deepEqual( declarations.filter( ( [ key ] ) => /^(?:transition|animation)/.test( key ) ), [], 'the brand item sets no transition or animation' );
	assert.ok( ! /(?:^|[\s{;])(?:transition|animation)(?:-[a-z-]+)?\s*:/m.test( brandCss ), 'brand.css sets no transition or animation' );
} );
