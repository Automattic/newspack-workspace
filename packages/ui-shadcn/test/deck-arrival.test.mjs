import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const motionSource = readFileSync( join( root, '..', 'motion', 'index.js' ), 'utf8' );
const motion = await import( `data:text/javascript,${ encodeURIComponent( motionSource ) }` );
const kit = await import( '../registry/components/deck/deck-motion.ts' );
const arrival = await import( '../registry/components/deck/deck-arrival.ts' );

const PAUSE = 300;

const fakeView = ( opacity = 1 ) => {
	const calls = [];
	return {
		calls,
		opacity: () => opacity,
		set: value => {
			opacity = value;
		},
		show: delay => calls.push( [ 'show', delay ] ),
		hide: animate => {
			calls.push( [ 'hide', animate ] );
			if ( ! animate ) {
				opacity = 0;
			}
		},
	};
};
const env = ( reduced = false ) => ( { reducedMotion: () => reduced, pause: () => PAUSE } );

const fakeMotion = ( { reduced = false } = {} ) => {
	let time = 0;
	let nextId = 0;
	const frames = new Map();
	const scroller = {
		scrollTop: 0,
		scrollHeight: 8000,
		clientHeight: 800,
		style: { scrollSnapType: '' },
		scrollTo( { top } ) {
			this.scrollTop = top;
		},
	};
	const deck = kit.createDeckMotion( scroller, {
		now: () => time,
		requestFrame: callback => {
			frames.set( ++nextId, callback );
			return nextId;
		},
		cancelFrame: id => frames.delete( id ),
		reducedMotion: () => reduced,
		tokens: () => ( { base: motion.DURATION_MODAL, easing: kit.parseEasing( motion.EASE_DRAWER ) } ),
	} );
	const advance = ms => {
		const end = time + ms;
		while ( time < end ) {
			time = Math.min( end, time + 16 );
			const due = [ ...frames.values() ];
			frames.clear();
			due.forEach( callback => callback() );
		}
	};
	return { deck, scroller, advance, now: () => time };
};

test( 'the closing logo waits for the travel of its arrival plus the pause', () => {
	for ( const distance of [ 200, 800, 1600, 4000 ] ) {
		const travel = motion.travelDuration( distance );
		assert.equal( arrival.closingLogoDelay( travel, PAUSE, false ), travel + PAUSE, `${ distance }px` );
	}
	assert.equal( arrival.closingLogoDelay( 390, PAUSE, false ), 690 );
	assert.equal( arrival.closingLogoDelay( 0, PAUSE, false ), PAUSE, 'a settled scroll waits only the pause' );
	assert.equal( arrival.closingLogoDelay( -5, -1, false ), 0 );
} );

test( 'a scripted navigation hands the fade its own travel time', () => {
	const { deck, scroller, advance, now } = fakeMotion();
	const events = [];
	deck.subscribe( event => events.push( { ...event, at: now(), from: scroller.scrollTop } ) );
	deck.scrollTo( 800, 1 );
	assert.deepEqual( events, [ { type: 'start', to: 800, duration: motion.travelDuration( 800 ), at: 0, from: 0 } ] );
	advance( 1000 );
	assert.equal( events.at( -1 ).type, 'end' );
	assert.ok( events.at( -1 ).at >= motion.travelDuration( 800 ) && events.at( -1 ).at < motion.travelDuration( 800 ) + 16 );

	const view = fakeView();
	const fade = arrival.createClosingLogoFade( view, env(), false );
	fade.arrive( events[ 0 ].duration );
	assert.deepEqual( view.calls, [ [ 'hide', false ], [ 'show', 390 + PAUSE ] ] );
} );

test( 'motion events: a retarget restarts, a cancel is reported, a jump reports a zero travel before it moves', () => {
	const { deck, scroller, advance } = fakeMotion();
	const events = [];
	const unsubscribe = deck.subscribe( event => events.push( event.type === 'start' ? [ event.type, event.to, event.duration, scroller.scrollTop ] : [ event.type ] ) );
	deck.scrollTo( 800 );
	advance( 160 );
	const at = scroller.scrollTop;
	deck.scrollTo( 1600 );
	assert.deepEqual( events.at( -1 ), [ 'start', 1600, motion.travelDuration( 1600 - at ), at ] );
	deck.cancel();
	assert.deepEqual( events.at( -1 ), [ 'cancel' ] );
	deck.cancel();
	assert.equal( events.filter( ( [ type ] ) => type === 'cancel' ).length, 1, 'cancel with nothing in flight says nothing' );
	unsubscribe();
	deck.scrollTo( 0 );
	assert.equal( events.length, 3, 'an unsubscribed listener hears nothing' );

	const reduced = fakeMotion( { reduced: true } );
	const jumps = [];
	reduced.deck.subscribe( event => jumps.push( [ event.type, event.duration, reduced.scroller.scrollTop ] ) );
	reduced.deck.scrollTo( 1600 );
	assert.deepEqual( jumps, [ [ 'start', 0, 0 ], [ 'end', undefined, 1600 ] ] );
} );

test( 'a retarget to the same slide before the fade starts restarts the wait', () => {
	const view = fakeView();
	const fade = arrival.createClosingLogoFade( view, env(), false );
	fade.arrive( 450 );
	fade.arrive( 250 );
	assert.deepEqual( view.calls.slice( 1 ), [ [ 'show', 750 ], [ 'hide', false ], [ 'show', 550 ] ] );
	view.set( 0.4 );
	fade.arrive( 250 );
	fade.settle();
	assert.equal( view.calls.length, 4, 'a fade under way is left alone' );
} );

test( 'a scroll that settles on the slide fades after the pause, once', () => {
	const view = fakeView();
	const fade = arrival.createClosingLogoFade( view, env(), false );
	fade.settle();
	fade.settle();
	assert.deepEqual( view.calls, [ [ 'hide', false ], [ 'show', PAUSE ] ] );
} );

test( 'leaving resets the logo so the next arrival fades again', () => {
	const view = fakeView();
	const fade = arrival.createClosingLogoFade( view, env(), false );
	fade.arrive( 390 );
	view.set( 1 );
	fade.leave();
	assert.equal( fade.shown, true, 'a finished logo leaves with its slide' );
	assert.equal( view.calls.length, 2 );
	fade.reset();
	assert.equal( fade.shown, false );
	assert.deepEqual( view.calls.at( -1 ), [ 'hide', false ], 'hidden at once, out of sight' );
	fade.arrive( 390 );
	assert.deepEqual( view.calls.at( -1 ), [ 'show', 690 ] );
	fade.reset();
	fade.reset();
	assert.deepEqual( view.calls.slice( -2 ), [ [ 'show', 690 ], [ 'hide', false ] ], 'reset is idempotent' );
} );

test( 'leaving mid-fade cancels cleanly', () => {
	const pending = fakeView();
	const before = arrival.createClosingLogoFade( pending, env(), false );
	before.arrive( 390 );
	before.leave();
	assert.deepEqual( pending.calls.at( -1 ), [ 'hide', false ], 'a fade still waiting is dropped, unseen' );
	assert.equal( before.shown, false );
	before.leave();
	assert.equal( pending.calls.length, 3 );

	const fading = fakeView();
	const during = arrival.createClosingLogoFade( fading, env(), false );
	during.arrive( 390 );
	fading.set( 0.5 );
	during.leave();
	assert.deepEqual( fading.calls.at( -1 ), [ 'hide', true ], 'a fade under way reverses from where it is' );
	assert.equal( during.shown, false );
	during.settle();
	assert.deepEqual( fading.calls.at( -1 ), [ 'show', 0 ], 'coming back while it fades out fades straight back in' );
	fading.set( 0 );
	during.reset();
	during.settle();
	assert.deepEqual( fading.calls.at( -1 ), [ 'show', PAUSE ], 'from hidden it waits the pause again' );
} );

test( 'reduced motion keeps the fade and drops the wait', () => {
	const view = fakeView();
	const fade = arrival.createClosingLogoFade( view, env( true ), false );
	fade.arrive( 0 );
	assert.deepEqual( view.calls.at( -1 ), [ 'show', 0 ] );
	fade.reset();
	fade.settle();
	assert.deepEqual( view.calls.at( -1 ), [ 'show', 0 ] );
	assert.equal( arrival.closingLogoDelay( 390, PAUSE, true ), 0 );
} );

test( 'the logo is visible without script, before hydration and when the deck opens on the slide', () => {
	const view = fakeView();
	const fade = arrival.createClosingLogoFade( view, env(), true );
	assert.deepEqual( view.calls, [], 'nothing hides a logo the reader can see' );
	fade.settle();
	fade.arrive( 390 );
	assert.deepEqual( view.calls, [], 'no fade for a first placement' );

	const source = readFileSync( join( root, 'registry', 'components', 'deck', 'deck-closing-logo.tsx' ), 'utf8' );
	const jsx = source.slice( source.lastIndexOf( 'return (' ) );
	assert.ok( ! /data-hidden=|style=|opacity-0(?<!data-\[hidden\]:opacity-0)/.test( jsx ), 'the markup renders the logo visible' );
	assert.ok( jsx.includes( 'data-[hidden]:opacity-0' ), 'only the hidden attribute, set from an effect, hides it' );
	assert.ok( jsx.includes( 'print:transition-none print:data-[hidden]:opacity-100' ), 'a printed deck keeps its logo, with no fade' );

	assert.equal( arrival.opensVisible( 'closing', 'closing', 'away' ), true, 'a deck opened on the slide shows the logo at once' );
	assert.equal( arrival.opensVisible( null, 'closing', 'visible' ), true, 'a slide already in view keeps its logo' );
	assert.equal( arrival.opensVisible( 'opening', 'closing', 'away' ), false );
	assert.equal( arrival.opensVisible( null, 'closing', 'away' ), false );
} );

test( 'the logo fades in opacity only, keeps its name and role and takes no space of its own', () => {
	const source = readFileSync( join( root, 'registry', 'components', 'deck', 'deck-closing-logo.tsx' ), 'utf8' );
	const transitions = source.match( /\[transition:([^\]]*)\]/g );
	assert.deepEqual( transitions, [ '[transition:opacity_var(--newspack-ui-duration-reveal)_var(--newspack-ui-ease-out)]' ] );
	assert.ok( ! /\b(?:scale|translate|transform)-/.test( source ) );
	const layouts = readFileSync( join( root, 'registry', 'components', 'deck', 'layouts.tsx' ), 'utf8' );
	const closing = layouts.slice( layouts.indexOf( 'export function ClosingSlide(' ) ).split( /\nexport function /, 2 )[ 0 ];
	assert.ok( closing.includes( '<DeckClosingLogo className="p-[clamp(4rem,10vw,10rem)]">' ), 'the fade sits on the clearspace wrapper' );
	assert.ok( closing.includes( '<NewspackLogo variant="reversed" title="" className="h-[clamp(4rem,10vw,10rem)] w-auto max-w-full" />' ), 'the logo itself is unchanged' );
	assert.ok( closing.includes( 'title={ title }' ), 'the slide keeps its accessible name' );
} );

test( 'slidePlace tells current, visible and away apart', () => {
	const viewport = { top: 0, bottom: 800 };
	assert.equal( arrival.slidePlace( { top: 0, bottom: 800 }, viewport ), 'current' );
	assert.equal( arrival.slidePlace( { top: 0.5, bottom: 800.5 }, viewport ), 'current' );
	assert.equal( arrival.slidePlace( { top: -400, bottom: 1600 }, viewport ), 'current', 'a tall slide scrolled into' );
	assert.equal( arrival.slidePlace( { top: 300, bottom: 1100 }, viewport ), 'visible' );
	assert.equal( arrival.slidePlace( { top: -799, bottom: 1 }, viewport ), 'visible' );
	assert.equal( arrival.slidePlace( { top: 800, bottom: 1600 }, viewport ), 'away' );
	assert.equal( arrival.slidePlace( { top: -800, bottom: 0 }, viewport ), 'away' );
} );

const routed = ( { place = 'away', settles = true } = {} ) => {
	const deck = { at: place, destination: null, top: 0, animating: false, settles };
	const calls = [];
	const fade = {
		arrive: travel => calls.push( [ 'arrive', travel ] ),
		settle: () => calls.push( [ 'settle' ] ),
		leave: () => calls.push( [ 'leave' ] ),
		reset: () => calls.push( [ 'reset' ] ),
	};
	const route = arrival.routeClosingLogo( fade, {
		place: offset => ( offset === undefined ? deck.at : deck.destination ),
		scrollTop: () => deck.top,
		animating: () => deck.animating,
		settles: deck.settles,
	} );
	return { deck, calls, route };
};

test( 'a scripted navigation onto the slide is an arrival with its travel, any other is a leave', () => {
	const toward = routed( { place: 'away' } );
	toward.deck.destination = 'current';
	toward.route.motion( { type: 'start', to: 800, duration: 390 } );
	assert.deepEqual( toward.calls, [ [ 'reset' ], [ 'arrive', 390 ] ], 'from out of sight the logo resets first, so a turn back mid-travel fades again' );

	const near = routed( { place: 'visible' } );
	near.deck.destination = 'current';
	near.route.motion( { type: 'start', to: 800, duration: 265 } );
	assert.deepEqual( near.calls, [ [ 'arrive', 265 ] ] );

	const there = routed( { place: 'current' } );
	there.deck.destination = 'current';
	there.route.motion( { type: 'start', to: 0, duration: 0 } );
	assert.deepEqual( there.calls, [], 'already there is not an arrival' );

	const away = routed( { place: 'current' } );
	away.deck.destination = 'visible';
	away.route.motion( { type: 'start', to: 0, duration: 390 } );
	assert.deepEqual( away.calls, [ [ 'leave' ] ] );
} );

test( 'the destination is measured from where the scroll is now', () => {
	const offsets = [];
	const route = arrival.routeClosingLogo(
		{ arrive() {}, settle() {}, leave() {}, reset() {} },
		{ place: offset => ( offsets.push( offset ), 'away' ), scrollTop: () => 300, animating: () => true, settles: true }
	);
	route.motion( { type: 'start', to: 1100, duration: 390 } );
	assert.ok( offsets.includes( 800 ) );
} );

test( 'when a scripted scroll ends or is stopped, where the slide is decides', () => {
	for ( const [ place, expected ] of [ [ 'current', 'settle' ], [ 'visible', 'leave' ], [ 'away', 'reset' ] ] ) {
		for ( const type of [ 'end', 'cancel' ] ) {
			const { calls, route } = routed( { place } );
			route.motion( { type } );
			assert.deepEqual( calls, [ [ expected ] ], `${ type } with the slide ${ place }` );
		}
	}
} );

test( 'scrolls are ignored while a scripted scroll runs, and decide once it is not', () => {
	const busy = routed( { place: 'visible' } );
	busy.deck.animating = true;
	busy.route.scroll();
	busy.route.scrollEnd();
	assert.deepEqual( busy.calls, [], 'a scripted travel toward the slide is not cancelled by its own scroll' );

	const { deck, calls, route } = routed( { place: 'visible' } );
	route.scroll();
	deck.at = 'away';
	route.scroll();
	deck.at = 'current';
	route.scroll();
	assert.deepEqual( calls, [ [ 'leave' ], [ 'reset' ] ], 'with scrollend the slide becoming current waits for it' );
	route.scrollEnd();
	assert.deepEqual( calls.at( -1 ), [ 'settle' ] );

	const old = routed( { place: 'current', settles: false } );
	old.route.scroll();
	assert.deepEqual( old.calls, [ [ 'settle' ] ], 'without scrollend the scroll that makes it current settles' );
} );
