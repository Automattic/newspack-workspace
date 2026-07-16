import { domReady, debounce, elementCollides, hasStickyHeader } from './utils';

/**
 * Selector for the main element to place the side rail placements on.
 */
const mainElement = '#primary';

/**
 * Selectors for elements to detect collisions with.
 */
const collisionElements = [
	'#masthead',
	'#primary',
	'.above-footer-widgets',
	'.scaip .newspack_global_ad',
	'#colophon',
	'.newspack_global_ad.sticky',
];

// The GPT command queue stub only carries `cmd` until the GPT library loads and
// replaces/extends this global with the full API (this is GPT's own documented
// bootstrap pattern; see the `pubads` doc comment in `./globals.d.ts`).
window.googletag = window.googletag || { cmd: [] };

/**
 * Initialize a side rail placement.
 *
 * @param selector The selector for the side rail placement.
 * @param side     The side of the side rail placement.
 * @param elements The elements to detect collisions with.
 */
function initPlacement( selector: string, side: 'left' | 'right', elements: NodeListOf< Element > ): void {
	const main = document.querySelector( mainElement );
	if ( ! main ) {
		return;
	}

	const element = document.querySelector< HTMLElement >( selector );
	if ( ! element ) {
		return;
	}
	element.style.right = 'auto';

	const ad = element.querySelector( 'div' );
	if ( ! ad ) {
		return;
	}

	ad.classList.add( 'ad-slot' );

	// NOTE: pre-existing -- `header` is not null-checked before use in `updateDimensions`
	// below (only guarded by `hasStickyHeader()`, which says nothing about `#masthead`
	// existing). Flagging rather than adding a guard not present in the original.
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
			const headerRect = ( header as Element ).getBoundingClientRect();
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
		const stickyAd = document.querySelector< HTMLElement >( '.newspack_global_ad.sticky' );
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
		// `pubads` is guaranteed present by the time a queued `cmd` callback runs (see the
		// doc comment on `NewspackGoogletag.pubads` in `./globals.d.ts`).
		window.googletag.pubads!().addEventListener( 'slotRenderEnded', function ( event ) {
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
