import { centerItem, getScrollEdges, initScrollingTabs, observeScrollEdges, updateScrollEdges } from './scroll-edges';

const ITEM_WIDTH = 100;

/**
 * A row of five 100px items in a 250px window, laid out like a browser would.
 *
 * @param {Object} options     Options.
 * @param {string} options.dir Text direction.
 * @return {HTMLElement} The row.
 */
const makeRow = ( { dir = 'ltr' } = {} ) => {
	document.body.innerHTML = `
		<div class="newspack-ui__tabs newspack-ui__tabs--scroll" dir="${ dir }">
			<nav class="newspack-ui__tabs__list" style="direction: ${ dir }">
				<a href="#1">One</a><a href="#2">Two</a><a href="#3">Three</a><a href="#4">Four</a><a href="#5">Five</a>
			</nav>
		</div>
	`;
	const row = document.querySelector( '.newspack-ui__tabs__list' );
	const items = [ ...row.children ];
	let scrollLeft = 0;
	Object.defineProperty( row, 'scrollWidth', { configurable: true, get: () => items.length * ITEM_WIDTH } );
	Object.defineProperty( row, 'clientWidth', { configurable: true, get: () => 250 } );
	Object.defineProperty( row, 'scrollLeft', {
		configurable: true,
		get: () => scrollLeft,
		set: value => {
			scrollLeft = value;
		},
	} );
	row.getBoundingClientRect = () => ( { left: 0, width: 250 } );
	items.forEach( ( item, index ) => {
		item.getBoundingClientRect = () => {
			// In RTL the first item sits at the right edge and the row scrolls into negative values.
			const fromStart = index * ITEM_WIDTH;
			const left = 'rtl' === dir ? 250 - ITEM_WIDTH - fromStart - scrollLeft : fromStart - scrollLeft;
			return { left, width: ITEM_WIDTH };
		};
	} );
	return row;
};

describe( 'newspack-ui scroll edges', () => {
	it( 'reports hidden content at the end of a row scrolled to its start', () => {
		const row = makeRow();
		expect( getScrollEdges( row ) ).toEqual( { start: false, end: true, overflowing: true } );
		updateScrollEdges( row );
		expect( row.classList.contains( 'is-overflowing-end' ) ).toBe( true );
		expect( row.classList.contains( 'is-overflowing-start' ) ).toBe( false );
	} );

	it( 'reports both ends in the middle and only the start at the end', () => {
		const row = makeRow();
		row.scrollLeft = 100;
		expect( getScrollEdges( row ) ).toMatchObject( { start: true, end: true } );
		row.scrollLeft = 250;
		updateScrollEdges( row );
		expect( row.classList.contains( 'is-overflowing-start' ) ).toBe( true );
		expect( row.classList.contains( 'is-overflowing-end' ) ).toBe( false );
	} );

	it( 'tracks the top and bottom of a vertically scrolling element', () => {
		document.body.innerHTML = '<div class="newspack-ui__modal"></div>';
		const modal = document.querySelector( '.newspack-ui__modal' );
		Object.defineProperty( modal, 'scrollHeight', { configurable: true, get: () => 800 } );
		Object.defineProperty( modal, 'clientHeight', { configurable: true, get: () => 500 } );
		expect( getScrollEdges( modal, 'y' ) ).toEqual( { start: false, end: true, overflowing: true } );
		modal.scrollTop = 150;
		updateScrollEdges( modal, 'y' );
		expect( modal.classList.contains( 'is-overflowing-start' ) ).toBe( true );
		expect( modal.classList.contains( 'is-overflowing-end' ) ).toBe( true );
		modal.scrollTop = 300;
		expect( getScrollEdges( modal, 'y' ) ).toMatchObject( { start: true, end: false } );
	} );

	it( 'reads negative RTL scroll positions from the start', () => {
		const row = makeRow( { dir: 'rtl' } );
		row.scrollLeft = -100;
		expect( getScrollEdges( row ) ).toMatchObject( { start: true, end: true } );
		row.scrollLeft = -250;
		expect( getScrollEdges( row ) ).toMatchObject( { start: true, end: false } );
	} );

	it( 'keeps the row at its start when the first item is selected', () => {
		const row = makeRow();
		row.scrollLeft = 120;
		centerItem( row, row.children[ 0 ] );
		expect( row.scrollLeft ).toBe( 0 );
	} );

	it( 'centers a middle item', () => {
		const row = makeRow();
		centerItem( row, row.children[ 2 ] );
		// Item three spans 200 to 300; its center, 250, sits in the middle of the 250px window.
		expect( row.scrollLeft ).toBe( 125 );
	} );

	it( 'clamps the last item to the end of the row', () => {
		const row = makeRow();
		centerItem( row, row.children[ 4 ] );
		expect( row.scrollLeft ).toBe( 250 );
	} );

	it( 'centers and clamps in RTL', () => {
		const row = makeRow( { dir: 'rtl' } );
		centerItem( row, row.children[ 2 ] );
		expect( row.scrollLeft ).toBe( -125 );
		centerItem( row, row.children[ 4 ] );
		expect( row.scrollLeft ).toBe( -250 );
	} );

	it( 'updates the classes when the row scrolls and stops when asked', () => {
		const frames = [];
		jest.spyOn( window, 'requestAnimationFrame' ).mockImplementation( callback => frames.push( callback ) );
		const row = makeRow();
		const stop = observeScrollEdges( row );
		row.scrollLeft = 250;
		row.dispatchEvent( new Event( 'scroll' ) );
		frames.shift()();
		expect( row.classList.contains( 'is-overflowing-start' ) ).toBe( true );
		expect( row.classList.contains( 'is-overflowing-end' ) ).toBe( false );
		stop();
		row.scrollLeft = 0;
		row.dispatchEvent( new Event( 'scroll' ) );
		expect( frames ).toHaveLength( 0 );
		window.requestAnimationFrame.mockRestore();
	} );

	it( 'centers the current page link when it sets up the tab rows', () => {
		const row = makeRow();
		row.children[ 3 ].setAttribute( 'aria-current', 'page' );
		initScrollingTabs();
		expect( row.scrollLeft ).toBe( 225 );
		expect( row.classList.contains( 'is-overflowing-start' ) ).toBe( true );
		expect( row.classList.contains( 'is-overflowing-end' ) ).toBe( true );
	} );
} );
