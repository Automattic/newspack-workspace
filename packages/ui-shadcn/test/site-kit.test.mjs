import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const dist = join( dirname( fileURLToPath( import.meta.url ) ), '..', 'dist' );
const readItem = name => JSON.parse( readFileSync( join( dist, 'site-kit', `${ name }.json` ), 'utf8' ) );

test( 'newspack-logo is a site-kit component with an accessible inline SVG', () => {
	assert.ok( existsSync( join( dist, 'site-kit', 'newspack-logo.json' ) ), 'dist/site-kit/newspack-logo.json exists' );
	const item = readItem( 'newspack-logo' );
	assert.equal( item.files.length, 1 );
	assert.equal( item.files[ 0 ].type, 'registry:component' );
	assert.ok( item.files[ 0 ].content.includes( 'role: \'img\'' ) );
	assert.ok( item.files[ 0 ].content.includes( '--newspack-logo-mark' ) );
	assert.ok( item.files[ 0 ].content.includes( '--newspack-logo-n' ) );
	assert.ok( item.files[ 0 ].content.includes( 'aria-hidden' ) );
	for ( const name of [ 'mark', 'n', 'wordmark', 'reversed-mark', 'reversed-n', 'reversed-wordmark' ] ) {
		assert.ok( item.files[ 0 ].content.includes( `--newspack-logo-${ name }` ), `--newspack-logo-${ name }` );
	}
	assert.ok( item.files[ 0 ].content.includes( 'M384 316v-36h-36l36 36Zm-68-68h68v-36H28' ), 'official N path' );
} );

test( 'deck items ship the section, the nav and a comment-free stylesheet', () => {
	for ( const name of [ 'deck-section', 'deck-nav', 'newspack-deck' ] ) {
		assert.ok( existsSync( join( dist, 'site-kit', `${ name }.json` ) ), `${ name }.json exists` );
	}
	const bundle = readItem( 'newspack-deck' );
	assert.deepEqual( bundle.registryDependencies.map( d => d.split( '/' ).pop().replace( /\.json$/, '' ) ), [ 'newspack-brand', 'newspack-logo', 'deck-section', 'deck-nav', 'deck-layouts' ] );
	const section = readItem( 'deck-section' );
	const css = section.files.find( f => f.path.endsWith( 'newspack-deck.css' ) );
	assert.ok( css, 'deck-section ships newspack-deck.css' );
	assert.ok( ! css.content.includes( '/*' ) );
	const source = section.files.find( f => f.path.endsWith( 'deck.tsx' ) ).content;
	const nav = readItem( 'deck-nav' ).files[ 0 ].content;
	assert.match( source, /export function Deck\(/ );
	assert.match( source, /export function DeckSlide\(/ );
	assert.match( nav, /export function DeckNav\(/ );
	assert.ok( nav.includes( 'Go to slide' ) && nav.includes( 'aria-current' ) );
} );

test( 'deck-layouts ships the seven starter slides, with brand opening and closing', () => {
	assert.ok( existsSync( join( dist, 'site-kit', 'deck-layouts.json' ) ), 'deck-layouts.json exists' );
	const item = readItem( 'deck-layouts' );
	assert.deepEqual( item.registryDependencies.map( d => d.split( '/' ).pop().replace( /\.json$/, '' ) ).sort(), [ 'card', 'deck-section', 'newspack-logo', 'utils' ] );
	const source = item.files.find( f => f.path.endsWith( 'layouts.tsx' ) ).content;
	for ( const name of [ 'OpeningSlide', 'ClosingSlide', 'ContentSlide', 'StatementSlide', 'ImageTextSlide', 'StatsSlide', 'QuoteSlide', 'LogosSlide' ] ) {
		assert.match( source, new RegExp( `export function ${ name }\\(` ) );
	}
	for ( const name of [ 'OpeningSlide', 'ClosingSlide' ] ) {
		const body = source.slice( source.indexOf( `export function ${ name }(` ) ).split( /\nexport function /, 2 )[ 0 ];
		assert.ok( body.includes( 'tone="brand"' ), `${ name } uses tone="brand"` );
		assert.ok( body.includes( 'variant="reversed"' ), `${ name } uses variant="reversed"` );
	}
	assert.ok( ! /\b(?:text|bg|border)-(?:white|black|[a-z]+-\d{2,3})\b|rgba?\(|#[0-9a-f]{3,8}\b/i.test( source ), 'no literal colors' );
	assert.ok( source.includes( "@/components/brand/newspack-logo" ), 'logo import keeps the brand/ path' );
	assert.ok( ! source.includes( 'size="lg"' ), 'no size the Button lacks' );
	assert.ok( ! /<h2 [^>]*className="[^"]*\bfont-/.test( source ), 'slide titles set no font utility, so they take the brand h2 face' );
} );

const layoutsSource = () => readItem( 'deck-layouts' ).files.find( f => f.path.endsWith( 'layouts.tsx' ) ).content;
const layoutBody = ( source, name ) => source.slice( source.indexOf( `export function ${ name }(` ) ).split( /\nexport function /, 2 )[ 0 ];

test( 'the opening slide takes a heading, keeps title as its name and uses the brand h1 size', () => {
	const body = layoutBody( layoutsSource(), 'OpeningSlide' );
	assert.match( body, /heading\?: ReactNode/ );
	assert.ok( body.includes( '{ heading ?? title }' ) );
	assert.ok( body.includes( 'title={ title }' ) );
	const h1 = body.match( /<h1 className="([^"]*)"/ )[ 1 ];
	assert.ok( ! /\btext-\d?xl\b|\bfont-display\b|\bleading-/.test( h1 ), 'the h1 takes the brand h1 rule, not a fixed size' );
	assert.ok( h1.includes( 'text-balance' ) );
	assert.ok( ! /className="[^"]*(?<![a-z]:)\bp-16\b/.test( body ), 'the heavy padding is for larger screens only' );
} );

test( 'content layouts take tone and className, and SLIDE is exported for custom slides', () => {
	const source = layoutsSource();
	assert.match( source, /export const SLIDE_PADDING = 'py-16 ps-\[8vw\] pe-\[max\(8vw,3\.5rem\)\]';/ );
	assert.match( source, /export const SLIDE = `flex flex-col justify-center gap-8 \$\{ SLIDE_PADDING \}`;/ );
	assert.ok( source.includes( "import { cn } from '@/lib/utils';" ) );
	for ( const name of [ 'ContentSlide', 'StatementSlide', 'ImageTextSlide', 'StatsSlide', 'QuoteSlide', 'LogosSlide' ] ) {
		const body = layoutBody( source, name );
		assert.ok( /\btone,/.test( body ) && body.includes( 'tone={ tone }' ), `${ name } passes tone` );
		assert.match( body, /className=\{ cn\([^)]*className \) \}/, `${ name } merges className last` );
	}
	for ( const name of [ 'ImageTextSlide', 'StatsSlide', 'LogosSlide' ] ) {
		assert.match( layoutBody( source, name ), /<h2 className="text-balance">/, `${ name } balances its title` );
	}
} );

test( 'StatsSlide fits two to four stats on a phone and ImageTextSlide has an image-led layout', () => {
	const source = layoutsSource();
	const stats = layoutBody( source, 'StatsSlide' );
	assert.ok( stats.includes( 'grid grid-cols-2' ), 'two columns on phones' );
	assert.ok( stats.includes( 'lg:grid-flow-col' ), 'one row from lg' );
	assert.ok( stats.includes( '@container' ) && stats.includes( 'cqi' ), 'values scale with their card' );
	const image = layoutBody( source, 'ImageTextSlide' );
	assert.match( image, /layout\?: 'split' \| 'image-led'/ );
	assert.ok( image.includes( "layout = 'split'" ) );
	assert.ok( image.includes( 'lg:grid-cols-[minmax(0,5fr)_minmax(0,3fr)]' ) && image.includes( 'lg:grid-cols-[minmax(0,3fr)_minmax(0,5fr)]' ) );
	assert.ok( image.includes( 'h-auto w-auto max-w-full' ), 'an image-led image is never cropped' );
} );

test( 'every item that imports @/lib/utils declares the shadcn utils item', () => {
	const read = dir => readdirSync( join( dist, dir ) ).filter( file => file.endsWith( '.json' ) && file !== 'registry.json' ).map( file => JSON.parse( readFileSync( join( dist, dir, file ), 'utf8' ) ) );
	const items = [ ...read( 'registry' ), ...read( 'site-kit' ) ].filter( item => item.files?.length );
	const users = items.filter( item => item.files.some( file => file.content?.includes( '@/lib/utils' ) ) );
	assert.ok( users.some( item => item.name === 'card' ) );
	for ( const item of items ) {
		const declares = ( item.registryDependencies ?? [] ).includes( 'utils' );
		assert.equal( declares, users.includes( item ), item.name );
	}
} );

test( 'deck dot navigation uses the kit tokens, the brand surface and renders for one slide', () => {
	const section = readItem( 'deck-section' ).files.find( f => f.path.endsWith( 'deck.tsx' ) ).content;
	const nav = readItem( 'deck-nav' ).files[ 0 ].content;
	assert.ok( ! nav.includes( '< 2' ), 'no two-slide guard' );
	assert.ok( nav.includes( 'slides.length < 1' ) );
	assert.ok( nav.includes( '--newspack-ui-color-text' ) && nav.includes( '--newspack-ui-color-focus' ) );
	assert.ok( ! nav.includes( 'border-foreground' ) && ! nav.includes( 'ring-ring' ) );
	assert.ok( nav.includes( 'newspack-brand-inverse' ) );
	assert.ok( ! nav.includes( 'outline-none' ), 'the dot keeps its outline style for forced colors' );
	assert.ok( nav.includes( 'bg-transparent' ) && ! nav.includes( 'backgroundColor' ) );
	assert.ok( section.includes( 'window.history.replaceState( window.history.state' ) );
} );

test( 'deck dots read as clickable, have a 32px target and grow on hover and focus', () => {
	const nav = readItem( 'deck-nav' ).files[ 0 ].content;
	const button = nav.match( /className="(group [^"]*)"/ )[ 1 ].split( ' ' );
	const dot = nav.match( /<span className="([^"]*)"/ )[ 1 ].split( ' ' );
	assert.ok( button.includes( 'cursor-pointer' ) );
	assert.ok( button.includes( 'size-8' ) && ! button.includes( 'size-6' ), '32px hit target' );
	assert.ok( ! /flex-col gap-/.test( nav ), 'targets touch, with no gap between them' );
	assert.ok( dot.includes( 'size-2' ) );
	assert.ok( dot.includes( 'motion-safe:pointer-fine:group-hover:scale-150' ) );
	assert.ok( dot.includes( 'motion-safe:group-focus-visible:scale-150' ) );
	assert.ok( ! dot.some( name => /^(?!motion-safe:)[^ ]*scale-/.test( name ) ), 'no growth outside motion-safe' );
	assert.ok( dot.includes( '[transition:background-color_var(--newspack-ui-duration-color)_var(--newspack-ui-ease-color),border-color_var(--newspack-ui-duration-color)_var(--newspack-ui-ease-color),scale_var(--newspack-ui-duration-press)_var(--newspack-ui-ease-out)]' ) );
	assert.ok( ! nav.includes( 'transition-all' ) && ! /transition:all/.test( nav ) );
	assert.ok( button.includes( 'motion-reduce:pointer-fine:hover:bg-(--newspack-ui-color-neutral-fill)' ), 'reduced motion swaps the growth for the hover fill' );
	assert.ok( button.includes( 'transition-[background-color]' ) && button.includes( 'duration-(--newspack-ui-duration-color)' ) );
	assert.ok( dot.includes( 'group-aria-[current=step]:bg-[var(--newspack-ui-color-text)]' ) );
	assert.ok( dot.includes( 'forced-colors:group-aria-[current=step]:bg-[Highlight]' ) );
	assert.ok( button.some( name => name.startsWith( 'focus-visible:[box-shadow:inset_0_0_0_2px_var(--newspack-ui-color-focus)' ) ) );
} );

test( 'deck-section ships the navigation and motion helpers', () => {
	const files = readItem( 'deck-section' ).files.map( f => f.path );
	assert.ok( files.some( path => path.endsWith( 'deck-navigation.ts' ) ) );
	assert.ok( files.some( path => path.endsWith( 'deck-motion.ts' ) ) );
} );

const navigation = await import( '../registry/components/deck/deck-navigation.ts' );

const stubScroller = ( top = 0, height = 800 ) => ( { clientHeight: height, getBoundingClientRect: () => ( { top, bottom: top + height } ) } );
const stubSlide = ( top, bottom ) => ( { getBoundingClientRect: () => ( { top, bottom } ) } );

test( 'currentDeckIndex picks the last slide whose top reached the scroller top', () => {
	const slides = [ stubSlide( -1600, -800 ), stubSlide( -800, 0 ), stubSlide( 0.5, 800 ), stubSlide( 800, 1600 ) ];
	assert.equal( navigation.currentDeckIndex( stubScroller(), slides ), 2 );
	assert.equal( navigation.currentDeckIndex( stubScroller(), [ stubSlide( 2, 802 ) ] ), 0 );
	assert.equal( navigation.currentDeckIndex( stubScroller( 100 ), [ stubSlide( -700, 100 ), stubSlide( 101, 900 ), stubSlide( 102, 900 ) ] ), 1 );
} );

test( 'planDeckStep scrolls a tall slide by up to 0.9 of the viewport before moving on', () => {
	const scroller = stubScroller();
	const tall = [ stubSlide( 0, 2000 ), stubSlide( 2000, 2800 ) ];
	assert.deepEqual( navigation.planDeckStep( scroller, tall, 1 ), { type: 'scroll', top: 720 } );
	const nearEnd = [ stubSlide( -1000, 1000 ), stubSlide( 1000, 1800 ) ];
	assert.deepEqual( navigation.planDeckStep( scroller, nearEnd, 1 ), { type: 'scroll', top: 200 } );
	const fits = [ stubSlide( -1000, 800 ), stubSlide( 800, 1600 ) ];
	assert.deepEqual( navigation.planDeckStep( scroller, fits, 1 ), { type: 'slide', index: 1 } );
	const scrolledInto = [ stubSlide( -1500, 500 ), stubSlide( 500, 1300 ) ];
	assert.deepEqual( navigation.planDeckStep( scroller, [ stubSlide( -1500, 500 ) ], -1 ), { type: 'scroll', top: -720 } );
	assert.deepEqual( navigation.planDeckStep( scroller, scrolledInto, -1 ), { type: 'scroll', top: -720 } );
	const atTop = [ stubSlide( -800, 0 ), stubSlide( 0, 800 ) ];
	assert.deepEqual( navigation.planDeckStep( scroller, atTop, -1 ), { type: 'slide', index: 0 } );
} );

test( 'planDeckStep does nothing past either end and with one slide', () => {
	const scroller = stubScroller();
	assert.equal( navigation.planDeckStep( scroller, [ stubSlide( 0, 800 ) ], 1 ), null );
	assert.equal( navigation.planDeckStep( scroller, [ stubSlide( 0, 800 ) ], -1 ), null );
	assert.equal( navigation.planDeckStep( scroller, [ stubSlide( -800, 0 ), stubSlide( 0, 800 ) ], 1 ), null );
	assert.equal( navigation.planDeckStep( scroller, [ stubSlide( 0, 800 ), stubSlide( 800, 1600 ) ], -1 ), null );
} );

test( 'planDeckStep advances from the pending target while a smooth scroll is in flight', () => {
	const scroller = stubScroller();
	const slides = [ stubSlide( 0, 800 ), stubSlide( 800, 1600 ), stubSlide( 1600, 2400 ), stubSlide( 2400, 3200 ) ];
	assert.deepEqual( navigation.planDeckStep( scroller, slides, 1, 1 ), { type: 'slide', index: 2 } );
	assert.deepEqual( navigation.planDeckStep( scroller, slides, -1, 2 ), { type: 'slide', index: 1 } );
	assert.equal( navigation.planDeckStep( scroller, slides, 1, 3 ), null );
	const tall = [ stubSlide( 0, 2000 ), stubSlide( 2000, 2800 ) ];
	assert.deepEqual( navigation.planDeckStep( scroller, tall, 1, 0 ), { type: 'slide', index: 1 } );
} );

test( 'isIgnoredTarget skips fields, listboxes, dialogs and media', () => {
	const splitSelectorList = selector => {
		const parts = [];
		let depth = 0;
		let start = 0;
		for ( let i = 0; i < selector.length; i++ ) {
			depth += selector[ i ] === '[' ? 1 : selector[ i ] === ']' ? -1 : 0;
			if ( selector[ i ] === ',' && depth === 0 ) {
				parts.push( selector.slice( start, i ).trim() );
				start = i + 1;
			}
		}
		parts.push( selector.slice( start ).trim() );
		return parts;
	};
	const parts = splitSelectorList( navigation.IGNORED_TARGETS );
	for ( const wanted of [ 'input', 'textarea', 'select', 'video', 'audio', '[role="listbox"]', '[role="dialog"]', '[aria-modal="true"]' ] ) {
		assert.ok( parts.includes( wanted ), wanted );
	}
	const fake = selectorMatch => ( { closest: selector => ( selector === navigation.IGNORED_TARGETS && selectorMatch ? {} : null ) } );
	assert.equal( navigation.isIgnoredTarget( fake( true ) ), true );
	assert.equal( navigation.isIgnoredTarget( fake( false ) ), false );
	assert.equal( navigation.isIgnoredTarget( null ), false );
} );

test( 'deck hashes round-trip ids and survive malformed input', () => {
	assert.equal( navigation.encodeDeckHash( 'a b%c' ), '#a%20b%25c' );
	assert.equal( navigation.decodeDeckHash( navigation.encodeDeckHash( 'a b%c' ) ), 'a b%c' );
	assert.equal( navigation.decodeDeckHash( '#%E0%A4%A' ), '' );
	assert.equal( navigation.decodeDeckHash( '' ), '' );
} );
