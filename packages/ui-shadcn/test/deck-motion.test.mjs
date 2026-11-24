import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
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
	assert.ok( nav.includes( 'duration-(--newspack-ui-duration-color)' ) );
	assert.ok( nav.includes( 'ease-(--newspack-ui-ease-color)' ) );
	assert.ok( ! /\btransition-colors\b/.test( nav ) );
} );
