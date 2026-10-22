/**
 * On phones a modal is a bottom sheet, and a touch dragged down from its header, or
 * from its body while that is scrolled to the top, can dismiss it. Released far
 * enough down or flicked, it closes through its Close button; otherwise it settles
 * back. Wide screens and mouse pointers are left alone.
 */

import { getSheetOffset } from './sheet-drag';

const media = { sheet: true, reduceMotion: false };

/**
 * Render a modal shown as a sheet, and load the modal script.
 *
 * @param {string} content The modal content markup.
 */
function renderSheet( content = '<p id="text">Text</p><input id="field" type="text" />' ) {
	document.body.innerHTML = `
		<div id="newspack-my-account__example" class="newspack-ui__modal-container" data-state="open">
			<div class="newspack-ui__modal-container__overlay"></div>
			<div class="newspack-ui__modal">
				<header class="newspack-ui__modal__header">
					<h2>Example</h2>
					<button class="newspack-ui__modal__close">Close</button>
				</header>
				<section class="newspack-ui__modal__content">${ content }</section>
			</div>
		</div>
	`;
	jest.isolateModules( () => {
		require( './modals' );
	} );
	const modal = document.getElementById( 'newspack-my-account__example' );
	const sheet = modal.querySelector( '.newspack-ui__modal' );
	Object.defineProperty( sheet, 'offsetHeight', { value: 500, configurable: true } );
	return {
		modal,
		sheet,
		overlay: modal.querySelector( '.newspack-ui__modal-container__overlay' ),
		heading: modal.querySelector( 'h2' ),
	};
}

/**
 * Dispatch a pointer event.
 *
 * @param {HTMLElement} target              The target.
 * @param {string}      type                The event type.
 * @param {Object}      options             Event details.
 * @param {number}      options.clientY     The pointer's vertical position.
 * @param {number}      options.timeStamp   When it happened, in milliseconds.
 * @param {string}      options.pointerType The pointer type.
 * @param {number}      options.pointerId   The pointer's ID; any but 1 is a second finger.
 */
function pointer( target, type, { clientY, timeStamp, pointerType = 'touch', pointerId = 1 } ) {
	const event = new MouseEvent( type, { bubbles: true, cancelable: true, clientX: 100, clientY } );
	Object.defineProperties( event, {
		pointerId: { value: pointerId },
		pointerType: { value: pointerType },
		isPrimary: { value: 1 === pointerId },
		timeStamp: { value: timeStamp },
	} );
	target.dispatchEvent( event );
}

/**
 * Drag from a target by a distance, over a duration, and release.
 *
 * @param {HTMLElement} target              Where the drag starts.
 * @param {number}      distance            How far down it moves, negative for up.
 * @param {number}      duration            How long it takes, in milliseconds.
 * @param {Object}      options             Drag details.
 * @param {string}      options.pointerType The pointer type.
 * @param {boolean}     options.release     Whether to release at the end.
 */
function drag( target, distance, duration, { pointerType = 'touch', release = true } = {} ) {
	pointer( target, 'pointerdown', { clientY: 100, timeStamp: 0, pointerType } );
	pointer( target, 'pointermove', { clientY: 100 + distance / 2, timeStamp: duration / 2, pointerType } );
	pointer( target, 'pointermove', { clientY: 100 + distance, timeStamp: duration, pointerType } );
	if ( release ) {
		pointer( target, 'pointerup', { clientY: 100 + distance, timeStamp: duration, pointerType } );
	}
}

/**
 * Dispatch a touchmove with a finger at each of the given positions.
 *
 * @param {HTMLElement} target   The target.
 * @param {number[]}    clientYs Each finger's vertical position.
 * @return {Event} The dispatched event.
 */
function touchmove( target, clientYs ) {
	const event = new Event( 'touchmove', { bubbles: true, cancelable: true } );
	Object.defineProperty( event, 'touches', { value: clientYs.map( clientY => ( { clientX: 100, clientY } ) ) } );
	target.dispatchEvent( event );
	return event;
}

const nextTask = () => new Promise( resolve => setTimeout( resolve, 0 ) );

beforeAll( () => {
	window.matchMedia = query => ( {
		matches: query.includes( 'prefers-reduced-motion' ) ? media.reduceMotion : media.sheet,
	} );
	Element.prototype.checkVisibility = function () {
		return ! this.closest( '[hidden]' );
	};
	window.DOMMatrixReadOnly = class {
		constructor( transform ) {
			this.m42 = parseFloat( /translateY\((-?[\d.]+)px\)/.exec( transform )?.[ 1 ] ?? 0 );
		}
	};
} );

afterAll( () => {
	delete window.matchMedia;
	delete window.DOMMatrixReadOnly;
	delete Element.prototype.checkVisibility;
} );

afterEach( () => {
	media.sheet = true;
	media.reduceMotion = false;
	document.body.innerHTML = '';
} );

describe( 'newspack-ui bottom sheet swipe', () => {
	it( 'follows a drag down from the header and fades the overlay with it', () => {
		const { sheet, overlay, heading } = renderSheet();
		drag( heading, 100, 2000, { release: false } );

		expect( sheet.style.transform ).toBe( 'translateY(100px)' );
		expect( overlay.style.opacity ).toBe( '0.8' );
	} );

	it( 'closes when released past 30% of the sheet', async () => {
		const { modal, sheet, overlay, heading } = renderSheet();
		drag( heading, 160, 4000 );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'closed' );
		expect( sheet.style.transform ).toBe( '' );
		expect( overlay.style.opacity ).toBe( '' );
	} );

	it( 'settles back when released short of the threshold', async () => {
		const { modal, sheet, overlay, heading } = renderSheet();
		drag( heading, 140, 4000 );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'open' );
		expect( sheet.style.transform ).toBe( '' );
		expect( sheet.style.transition ).toBe( '' );
		expect( overlay.style.opacity ).toBe( '' );
	} );

	it( 'closes on a quick flick down, however short', async () => {
		const { modal, heading } = renderSheet();
		drag( heading, 40, 100 );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'closed' );
	} );

	it( 'stays open after a tap whose finger rolls a little as it lifts', async () => {
		const { modal, sheet, heading } = renderSheet();
		const text = document.getElementById( 'text' );
		pointer( text, 'pointerdown', { clientY: 100, timeStamp: 0 } );
		pointer( text, 'pointermove', { clientY: 102, timeStamp: 80 } );
		expect( sheet.style.transform ).toBe( '' );
		pointer( text, 'pointerup', { clientY: 102, timeStamp: 90 } );
		await nextTask();
		expect( modal.dataset.state ).toBe( 'open' );

		[ text, heading ].forEach( target => {
			pointer( target, 'pointerdown', { clientY: 100, timeStamp: 0 } );
			pointer( target, 'pointermove', { clientY: 101, timeStamp: 120 } );
			expect( sheet.style.transform ).toBe( '' );
			pointer( target, 'pointerup', { clientY: 101, timeStamp: 125 } );
		} );
		await nextTask();
		expect( sheet.style.transform ).toBe( '' );
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'measures a flick from where the drag starts, not from a resting touch before it', async () => {
		const { modal, heading } = renderSheet();
		pointer( heading, 'pointerdown', { clientY: 100, timeStamp: 0 } );
		pointer( heading, 'pointermove', { clientY: 105, timeStamp: 600 } );
		pointer( heading, 'pointermove', { clientY: 135, timeStamp: 620 } );
		pointer( heading, 'pointermove', { clientY: 165, timeStamp: 640 } );
		pointer( heading, 'pointerup', { clientY: 165, timeStamp: 640 } );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'closed' );
	} );

	it( 'settles back after a pull that pauses before release', async () => {
		const { modal, heading } = renderSheet();
		pointer( heading, 'pointerdown', { clientY: 100, timeStamp: 0 } );
		pointer( heading, 'pointermove', { clientY: 150, timeStamp: 150 } );
		pointer( heading, 'pointermove', { clientY: 200, timeStamp: 300 } );
		pointer( heading, 'pointerup', { clientY: 200, timeStamp: 700 } );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'needs more than a twitch to count as a flick', async () => {
		const { modal, heading } = renderSheet();
		drag( heading, 15, 20 );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'stays open after a header tap while the sheet is still sliding in', async () => {
		const { modal, sheet, heading } = renderSheet();
		sheet.style.transform = 'translateY(400px)';
		pointer( heading, 'pointerdown', { clientY: 100, timeStamp: 0 } );
		pointer( heading, 'pointerup', { clientY: 100, timeStamp: 60 } );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'picks the sheet up where it is, but decides on how far the reader dragged it', async () => {
		const { modal, sheet, heading } = renderSheet();
		sheet.style.transform = 'translateY(200px)';
		drag( heading, 40, 2000, { release: false } );
		expect( sheet.style.transform ).toBe( 'translateY(240px)' );

		pointer( heading, 'pointerup', { clientY: 140, timeStamp: 2000 } );
		await nextTask();
		expect( sheet.style.transform ).toBe( '' );
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'drops a drag when the modal closes under it', async () => {
		const { modal, sheet, heading } = renderSheet();
		const closeButton = modal.querySelector( '.newspack-ui__modal__close' );
		const onClose = jest.fn();
		closeButton.addEventListener( 'click', onClose );
		drag( heading, 300, 4000, { release: false } );
		modal.setAttribute( 'data-state', 'closed' );
		pointer( heading, 'pointerup', { clientY: 400, timeStamp: 4000 } );
		await nextTask();

		expect( onClose ).not.toHaveBeenCalled();
		expect( sheet.style.transform ).toBe( '' );
	} );

	it( 'drops a drag when the sheet loses the pointer, and takes the next one', async () => {
		const { modal, sheet, heading } = renderSheet();
		drag( heading, 300, 4000, { release: false } );
		pointer( heading, 'lostpointercapture', { clientY: 400, timeStamp: 4000 } );
		expect( sheet.style.transform ).toBe( 'translateY(300px)' );
		pointer( sheet, 'lostpointercapture', { clientY: 400, timeStamp: 4000 } );
		expect( sheet.style.transform ).toBe( '' );
		expect( modal.dataset.state ).toBe( 'open' );

		drag( heading, 300, 4000 );
		await nextTask();
		expect( modal.dataset.state ).toBe( 'closed' );
	} );

	it( 'leaves a pinch alone', async () => {
		const { modal, sheet, heading } = renderSheet();
		pointer( heading, 'pointerdown', { clientY: 100, timeStamp: 0 } );
		pointer( heading, 'pointerdown', { clientY: 200, timeStamp: 10, pointerId: 2 } );
		pointer( heading, 'pointermove', { clientY: 400, timeStamp: 1000 } );
		expect( sheet.style.transform ).toBe( '' );
		pointer( heading, 'pointerup', { clientY: 400, timeStamp: 1000 } );
		await nextTask();
		expect( modal.dataset.state ).toBe( 'open' );

		drag( heading, 300, 4000, { release: false } );
		expect( sheet.style.transform ).toBe( 'translateY(300px)' );
		pointer( heading, 'pointerdown', { clientY: 200, timeStamp: 4010, pointerId: 2 } );
		expect( sheet.style.transform ).toBe( '' );
		pointer( heading, 'pointermove', { clientY: 450, timeStamp: 4100 } );
		expect( sheet.style.transform ).toBe( '' );
		pointer( heading, 'pointerup', { clientY: 450, timeStamp: 4100 } );
		await nextTask();
		expect( modal.dataset.state ).toBe( 'open' );

		pointer( heading, 'pointerdown', { clientY: 100, timeStamp: 5000 } );
		expect( touchmove( heading, [ 140 ] ).defaultPrevented ).toBe( true );
		pointer( heading, 'pointermove', { clientY: 140, timeStamp: 5050 } );
		expect( touchmove( heading, [ 180, 300 ] ).defaultPrevented ).toBe( false );
		expect( sheet.style.transform ).toBe( '' );
		pointer( heading, 'pointermove', { clientY: 400, timeStamp: 5100 } );
		pointer( heading, 'pointerup', { clientY: 400, timeStamp: 5100 } );
		await nextTask();
		expect( sheet.style.transform ).toBe( '' );
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'resists a drag up', () => {
		const { sheet, heading } = renderSheet();
		drag( heading, -200, 1000, { release: false } );

		const offset = parseFloat( sheet.style.transform.replace( 'translateY(', '' ) );
		expect( offset ).toBeLessThan( 0 );
		expect( offset ).toBeGreaterThan( -24 );
		expect( getSheetOffset( -50 ) ).toBeGreaterThan( getSheetOffset( -200 ) );
	} );

	it( 'drags from the body when it is scrolled to the top and the drag heads down', async () => {
		const { modal } = renderSheet();
		drag( document.getElementById( 'text' ), 200, 4000 );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'closed' );
	} );

	it( 'leaves the body to scroll when it is scrolled down', async () => {
		const { modal, sheet } = renderSheet();
		Object.defineProperty( sheet, 'scrollTop', { value: 80, configurable: true } );
		drag( document.getElementById( 'text' ), 200, 4000, { release: false } );

		expect( sheet.style.transform ).toBe( '' );
		pointer( sheet, 'pointerup', { clientY: 300, timeStamp: 4000 } );
		await nextTask();
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'leaves the body to scroll when the drag heads up', async () => {
		const { modal, sheet } = renderSheet();
		const text = document.getElementById( 'text' );
		pointer( text, 'pointerdown', { clientY: 300, timeStamp: 0 } );
		pointer( text, 'pointermove', { clientY: 280, timeStamp: 50 } );
		pointer( text, 'pointermove', { clientY: 500, timeStamp: 100 } );
		pointer( text, 'pointerup', { clientY: 500, timeStamp: 100 } );
		await nextTask();

		expect( sheet.style.transform ).toBe( '' );
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'ignores drags that start on a field or a button', async () => {
		const { modal, sheet } = renderSheet();
		[ document.getElementById( 'field' ), modal.querySelector( '.newspack-ui__modal__close' ) ].forEach( target => {
			drag( target, 300, 4000, { release: false } );
			expect( sheet.style.transform ).toBe( '' );
			pointer( target, 'pointerup', { clientY: 400, timeStamp: 4000 } );
		} );
		await nextTask();

		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'ignores the mouse', async () => {
		const { modal, sheet, heading } = renderSheet();
		drag( heading, 300, 4000, { pointerType: 'mouse' } );
		await nextTask();

		expect( sheet.style.transform ).toBe( '' );
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'does nothing on wide screens, where the modal is not a sheet', async () => {
		media.sheet = false;
		const { modal, sheet, heading } = renderSheet();
		drag( heading, 300, 4000, { release: false } );

		expect( sheet.style.transform ).toBe( '' );
		pointer( heading, 'pointerup', { clientY: 400, timeStamp: 4000 } );
		await nextTask();
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'stays open when the browser takes the gesture over', async () => {
		const { modal, sheet, heading } = renderSheet();
		drag( heading, 300, 4000, { release: false } );
		pointer( heading, 'pointercancel', { clientY: 400, timeStamp: 4000 } );
		await nextTask();

		expect( sheet.style.transform ).toBe( '' );
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'stays open while a request is in flight, or when its Close button is disabled', async () => {
		const { modal, heading } = renderSheet( '<button id="busy" aria-busy="true">Saving</button>' );
		drag( heading, 300, 4000 );
		await nextTask();
		expect( modal.dataset.state ).toBe( 'open' );

		document.getElementById( 'busy' ).removeAttribute( 'aria-busy' );
		modal.querySelector( '.newspack-ui__modal__close' ).setAttribute( 'aria-disabled', 'true' );
		drag( heading, 300, 4000 );
		await nextTask();
		expect( modal.dataset.state ).toBe( 'open' );
	} );

	it( 'closes through the header Close button while the header is hidden, as on a success screen', async () => {
		const { modal } = renderSheet();
		const close = jest.fn();
		modal.querySelector( '.newspack-ui__modal__close' ).addEventListener( 'click', close );
		modal.querySelector( '.newspack-ui__modal__header' ).hidden = true;
		drag( document.getElementById( 'text' ), 200, 4000 );
		await nextTask();

		expect( close ).toHaveBeenCalledTimes( 1 );
		expect( modal.dataset.state ).toBe( 'closed' );
	} );

	describe( 'with reduced motion', () => {
		beforeEach( () => {
			media.reduceMotion = true;
		} );

		it( 'does not move the sheet or the overlay while dragging', () => {
			const { sheet, overlay, heading } = renderSheet();
			drag( heading, 300, 4000, { release: false } );

			expect( sheet.style.transform ).toBe( '' );
			expect( sheet.style.transition ).toBe( '' );
			expect( overlay.style.opacity ).toBe( '' );
		} );

		it( 'still closes on a qualifying swipe', async () => {
			const { modal, heading } = renderSheet();
			drag( heading, 300, 4000 );
			await nextTask();

			expect( modal.dataset.state ).toBe( 'closed' );
		} );

		it( 'stays open after a short one', async () => {
			const { modal, heading } = renderSheet();
			drag( heading, 60, 4000 );
			await nextTask();

			expect( modal.dataset.state ).toBe( 'open' );
		} );
	} );
} );
