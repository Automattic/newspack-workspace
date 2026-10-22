/**
 * Swipe down to dismiss a modal shown as a bottom sheet on phones. The sheet follows
 * a touch or pen dragged from its header, or from its body while the body is scrolled
 * to the top. Released far enough down, or flicked, it closes through its own Close
 * button, so every listener and veto on that button runs; otherwise it settles back.
 */

import { isVisible } from './focus';

const SHEET_QUERY = '(max-width: 599px)';
const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

const CLOSE_DISTANCE = 0.3;

// Pixels per millisecond, measured over the last VELOCITY_WINDOW milliseconds of the drag.
const CLOSE_VELOCITY = 0.11;
const VELOCITY_WINDOW = 100;
const FLICK_DISTANCE = 20;

// A touch that moves less than this is a tap, so a finger rolling as it lifts can't start a drag.
const DEAD_ZONE = 10;

// Upward drags move the sheet less the further they go, up to this many pixels, which
// the surface-colored fill under the sheet covers.
const MAX_LIFT = 24;
const RUBBER_BAND = 0.55;

const NOT_DRAGGABLE = 'input, button, a, select, textarea, label, summary, iframe, [contenteditable], [role="button"], [role="link"]';

/**
 * Whether a media query matches.
 *
 * @param {string} query The media query.
 * @return {boolean} Whether it matches.
 */
const matches = query => !! window.matchMedia?.( query ).matches;

/**
 * The sheet's offset for a drag distance, with resistance above its resting place.
 *
 * @param {number} distance How far the pointer moved down, negative for up.
 * @return {number} The sheet's offset in pixels.
 */
export const getSheetOffset = distance => {
	if ( distance >= 0 ) {
		return distance;
	}
	return -MAX_LIFT * ( 1 - 1 / ( ( -distance * RUBBER_BAND ) / MAX_LIFT + 1 ) );
};

/**
 * Whether nothing between an element and the sheet is scrolled away from its top.
 *
 * @param {HTMLElement} element The element.
 * @param {HTMLElement} sheet   The sheet.
 * @return {boolean} Whether all of it is at the top.
 */
const isAtTop = ( element, sheet ) => {
	for ( let node = element; node; node = node.parentElement ) {
		if ( node.scrollTop > 0 ) {
			return false;
		}
		if ( node === sheet ) {
			return true;
		}
	}
	return true;
};

/**
 * Where a sheet's transform has it right now, including partway through settling.
 *
 * @param {HTMLElement} sheet The sheet.
 * @return {number} Its vertical offset in pixels.
 */
const getCurrentOffset = sheet => {
	const transform = window.getComputedStyle( sheet ).transform;
	if ( ! transform || 'none' === transform || typeof window.DOMMatrixReadOnly !== 'function' ) {
		return 0;
	}
	return new window.DOMMatrixReadOnly( transform ).m42;
};

/**
 * How fast a drag was moving down when it was released.
 *
 * @param {Array}  samples Where the pointer was, as `{ y, time }`, oldest first.
 * @param {number} y       Where it was released.
 * @param {number} time    When it was released, in milliseconds.
 * @return {number} Its speed in pixels per millisecond, negative for up.
 */
const getReleaseVelocity = ( samples, y, time ) => {
	let base = samples[ 0 ];
	for ( const sample of samples ) {
		if ( sample.time <= time - VELOCITY_WINDOW ) {
			base = sample;
		}
	}
	if ( ! base || time <= base.time ) {
		return 0;
	}
	return ( y - base.y ) / ( time - base.time );
};

/**
 * Let a modal's bottom sheet be swiped down to dismiss it.
 *
 * @param {HTMLElement} modal The modal container.
 */
export function setupSheetDrag( modal ) {
	const sheet = modal.querySelector( '.newspack-ui__modal' );
	if ( ! sheet ) {
		return;
	}
	const overlay = modal.querySelector( '.newspack-ui__modal-container__overlay' );
	let drag = null;

	const render = distance => {
		const offset = getSheetOffset( distance );
		sheet.style.transform = `translateY(${ offset }px)`;
		if ( overlay ) {
			overlay.style.opacity = String( Math.max( 0, 1 - Math.max( 0, offset ) / drag.height ) );
		}
	};

	const release = () => {
		[ sheet, overlay ].forEach( element => {
			element?.style.removeProperty( 'transform' );
			element?.style.removeProperty( 'opacity' );
			element?.style.removeProperty( 'transition' );
		} );
	};

	const start = () => {
		drag.started = true;
		drag.samples = [];
		drag.offset = drag.reduceMotion ? 0 : Math.max( 0, getCurrentOffset( sheet ) );
		try {
			sheet.setPointerCapture( drag.pointerId );
		} catch {
			// A pointer that already lifted can't be captured, and needs no capture.
		}
		if ( ! drag.reduceMotion ) {
			sheet.style.transition = 'none';
			if ( overlay ) {
				overlay.style.transition = 'none';
			}
		}
	};

	const cancel = () => {
		if ( drag?.started ) {
			release();
		}
		drag = null;
	};

	// A drag from the body starts only once it heads down, so scrolling stays the browser's.
	const follow = ( event, clientX, clientY ) => {
		if ( ! drag ) {
			return;
		}
		if ( 'open' !== modal.dataset.state ) {
			cancel();
			return;
		}
		const distance = clientY - drag.originY;
		if ( ! drag.started ) {
			const across = Math.abs( clientX - drag.originX );
			if ( Math.max( across, Math.abs( distance ) ) < DEAD_ZONE ) {
				return;
			}
			if ( across > Math.abs( distance ) || ( ! drag.fromHeader && distance < 0 ) ) {
				drag = null;
				return;
			}
			start();
		}
		drag.samples.push( { y: clientY, time: event.timeStamp } );
		while ( drag.samples.length > 2 && drag.samples[ 1 ].time <= event.timeStamp - VELOCITY_WINDOW ) {
			drag.samples.shift();
		}
		drag.lastY = clientY;
		drag.distance = distance;
		if ( ! drag.reduceMotion ) {
			render( drag.offset + distance );
		}
	};

	const end = ( event, cancelled ) => {
		if ( ! drag || event.pointerId !== drag.pointerId ) {
			return;
		}
		const { started, distance, height, samples, lastY } = drag;
		drag = null;
		if ( ! started ) {
			return;
		}
		release();
		if ( cancelled || 'open' !== modal.dataset.state || distance <= 0 ) {
			return;
		}
		const fast = distance >= FLICK_DISTANCE && getReleaseVelocity( samples, lastY, event.timeStamp ) > CLOSE_VELOCITY;
		if ( distance < height * CLOSE_DISTANCE && ! fast ) {
			return;
		}
		// A success screen hides the header, and its Close button with it, but still closes on a swipe.
		const closeButton =
			[ ...modal.querySelectorAll( '.newspack-ui__modal__close' ) ].find( button => isVisible( button ) ) ||
			sheet.querySelector( '.newspack-ui__modal__header .newspack-ui__modal__close' );
		if ( ! closeButton || closeButton.disabled || 'true' === closeButton.getAttribute( 'aria-disabled' ) ) {
			return;
		}
		if ( ! modal.querySelector( '[aria-busy="true"]' ) ) {
			closeButton.click();
		}
	};

	sheet.addEventListener(
		'pointerdown',
		event => {
			cancel();
			if ( ! event.isPrimary || 'mouse' === event.pointerType || 'open' !== modal.dataset.state || ! matches( SHEET_QUERY ) ) {
				return;
			}
			if ( event.target.closest?.( NOT_DRAGGABLE ) ) {
				return;
			}
			const fromHeader = !! event.target.closest?.( '.newspack-ui__modal__header' );
			if ( ! fromHeader && ! isAtTop( event.target, sheet ) ) {
				return;
			}
			drag = {
				pointerId: event.pointerId,
				fromHeader,
				originX: event.clientX,
				originY: event.clientY,
				lastY: event.clientY,
				samples: [ { y: event.clientY, time: event.timeStamp } ],
				offset: 0,
				height: sheet.offsetHeight || 1,
				distance: 0,
				reduceMotion: matches( REDUCED_MOTION_QUERY ),
				started: false,
			};
		},
		{ passive: true }
	);

	sheet.addEventListener(
		'pointermove',
		event => {
			if ( drag && event.pointerId === drag.pointerId ) {
				follow( event, event.clientX, event.clientY );
			}
		},
		{ passive: true }
	);

	sheet.addEventListener( 'pointerup', event => end( event, false ), { passive: true } );
	sheet.addEventListener( 'pointercancel', event => end( event, true ), { passive: true } );
	sheet.addEventListener(
		'lostpointercapture',
		event => {
			if ( event.target === sheet ) {
				end( event, true );
			}
		},
		{ passive: true }
	);

	// The body keeps native scrolling, so a drag that started from it holds scrolling off here.
	sheet.addEventListener(
		'touchmove',
		event => {
			if ( event.touches.length > 1 ) {
				cancel();
				return;
			}
			const touch = event.touches[ 0 ];
			if ( drag && ! drag.started && touch ) {
				follow( event, touch.clientX, touch.clientY );
			}
			if ( ! drag || ! touch || ! event.cancelable ) {
				return;
			}
			const distance = touch.clientY - drag.originY;
			if ( drag.started || ( distance > 0 && Math.abs( touch.clientX - drag.originX ) <= distance ) ) {
				event.preventDefault();
			}
		},
		{ passive: false }
	);
}
