import { domReady, debounce, elementCollides, hasStickyHeader } from './utils';

/**
 * Selector for the main element to place the side rail placements on.
 *
 * @type {string}
 */
const mainElement = '#primary';

/**
 * Selectors for elements to detect collisions with.
 *
 * @type {string[]}
 */
const collisionElements = [
	'#masthead',
	'#primary',
	'.above-footer-widgets',
	'.scaip .newspack_global_ad',
	'#colophon',
	'.newspack_global_ad.sticky',
];

window.googletag = window.googletag || { cmd: [] };

/**
 * Initialize a side rail placement.
 *
 * @param {string}   selector The selector for the side rail placement.
 * @param {string}   side     The side of the side rail placement.
 * @param {NodeList} elements The elements to detect collisions with.
 *
 * @return {void}
 */
function initPlacement( selector, side, elements ) {
	const main = document.querySelector( mainElement );
	if ( ! main ) {
		return;
	}

	const element = document.querySelector( selector );
	if ( ! element ) {
		return;
	}
	element.style.right = 'auto';

	const ad = element.querySelector( 'div' );
	if ( ! ad ) {
		return;
	}

	ad.classList.add( 'ad-slot' );

	const header = document.querySelector( '#masthead' );

	// Prepend a reference div to the element.
	const refDiv = document.createElement( 'div' );
	refDiv.style.width = ( ad._size ? ad._size[ 0 ] : ad.offsetWidth ) + 'px';
	refDiv.style.height = ( ad._size ? ad._size[ 1 ] : ad.offsetHeight ) + 'px';
	refDiv.style.position = 'absolute';
	refDiv.style.pointerEvents = 'none';
	element.prepend( refDiv );

	const hideAd = () => {
		ad.classList.add( 'ad-hidden' );
		ad.classList.remove( 'ad-visible' );
	};
	const showAd = () => {
		ad.classList.remove( 'ad-hidden' );
		ad.classList.add( 'ad-visible' );
		ad.style.removeProperty( 'display' );
	};

	const handleCollision = () => {
		if ( ad.style.width && parseInt( ad.style.width.replace( 'px', '' ) ) > element.offsetWidth ) {
			hideAd();
			return;
		}

		if ( elementCollides( refDiv, elements ) ) {
			hideAd();
		} else {
			showAd();
		}
	};

	const updateDimensions = () => {
		if ( hasStickyHeader() ) {
			const headerRect = header.getBoundingClientRect();
			element.style.top = `${ headerRect.bottom }px`;
		}

		const mainRect = main.getBoundingClientRect();
		let newWidth = 0;
		if ( side === 'left' ) {
			element.style.left = '0';
			newWidth = mainRect.left;
		} else {
			element.style.left = `${ mainRect.right }px`;
			newWidth = window.innerWidth - mainRect.right;
		}

		element.style.width = `${ newWidth }px`;
	};

	const handleStickyAd = () => {
		const stickyAd = document.querySelector( '.newspack_global_ad.sticky' );
		const stickyAdClose = document.querySelector( '.newspack_sticky_ad__close' );
		if ( stickyAd ) {
			element.style.bottom = `${ stickyAd.offsetHeight }px`;
		}
		if ( stickyAdClose ) {
			stickyAdClose.addEventListener( 'click', () => {
				element.style.removeProperty( 'bottom' );
				handlePlacement();
			} );
		}
	};

	const handlePlacement = () => {
		handleStickyAd();
		updateDimensions();
		handleCollision();
	};
	handlePlacement();

	window.addEventListener( 'scroll', debounce( handlePlacement, 50 ) );
	window.addEventListener( 'resize', debounce( handlePlacement, 200 ) );

	window.googletag.cmd.push( function () {
		window.googletag.pubads().addEventListener( 'slotRenderEnded', function ( event ) {
			if ( ad.id !== event.slot.getSlotElementId() ) {
				return;
			}
			refDiv.style.width = event.size[ 0 ] + 'px';
			refDiv.style.height = event.size[ 1 ] + 'px';
			handlePlacement();
		} );
	} );
}

domReady( () => {
	const elements = document.querySelectorAll( collisionElements.join( ',' ) );

	initPlacement( '.newspack_global_ad.left_side_rail', 'left', elements );
	initPlacement( '.newspack_global_ad.right_side_rail', 'right', elements );
} );
