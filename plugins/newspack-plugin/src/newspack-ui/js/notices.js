import { domReady, getTransitionTime } from './utils';
import { getNoticeIcon } from './icons';
import { announce, getLiveRegion, getWrapper } from './announce';

const AUTOHIDE_DELAY = 8000;
const VISIBLE_IN_STACK = 3;

const timers = new WeakMap();
const heights = new WeakMap();
const contentSizes = new WeakMap();
let openCount = 0;
let resizeObserver = null;

domReady( function () {
	// Create the live regions up front so they are registered before any message is injected.
	getLiveRegion( false );
	getLiveRegion( true );

	const notices = [ ...document.querySelectorAll( '.newspack-ui__snackbar__item' ) ];
	notices.forEach( notice => {
		const interactiveElements = notice.querySelectorAll( 'a, button' );
		[ ...interactiveElements ].forEach( element => {
			element.addEventListener( 'click', () => {
				closeNotice( notice );
			} );
		} );
	} );

	// Activate after two frames so the live regions are parsed before their text is injected;
	// a live region will not announce content that changed before it existed.
	requestAnimationFrame( () =>
		requestAnimationFrame( () => {
			notices.forEach( notice => {
				if ( notice.dataset.activeOnLoad === 'true' ) {
					openNotice( notice );
				}
			} );
		} )
	);
} );

/**
 * Get (or create) the snackbar container.
 *
 * @return {HTMLElement} The snackbar container.
 */
function getSnackbar() {
	const wrapper = getWrapper();
	let snackbar = wrapper.querySelector( '.newspack-ui__snackbar' );
	if ( ! snackbar ) {
		snackbar = document.createElement( 'div' );
		snackbar.classList.add( 'newspack-ui__snackbar' );
		wrapper.appendChild( snackbar );
	}
	return snackbar;
}

/**
 * Get (or create) one of the snackbar's two groups: the stack, for notices that hide on their
 * own, and the pinned list below it, for notices that stay until the reader closes them.
 *
 * @param {HTMLElement} snackbar The snackbar container.
 * @param {string}      name     Group name, 'stack' or 'pinned'.
 * @return {HTMLElement} The group element.
 */
function getGroup( snackbar, name ) {
	const className = `newspack-ui__snackbar__${ name }`;
	let group = [ ...snackbar.children ].find( child => child.classList.contains( className ) );
	if ( group ) {
		return group;
	}
	group = document.createElement( 'div' );
	group.classList.add( className );
	if ( 'stack' === name ) {
		bindStack( group );
		const pinned = [ ...snackbar.children ].find( child => child.classList.contains( 'newspack-ui__snackbar__pinned' ) );
		snackbar.insertBefore( group, pinned || null );
	} else {
		snackbar.appendChild( group );
	}
	return group;
}

/**
 * Whether an element is the stack group.
 *
 * @param {HTMLElement|null} element The element.
 * @return {boolean} Whether it's the stack.
 */
function isStack( element ) {
	return !! element?.classList.contains( 'newspack-ui__snackbar__stack' );
}

/**
 * The visible notices in a stack, newest first.
 *
 * @param {HTMLElement} stack The stack group.
 * @return {HTMLElement[]} The notices.
 */
function getStackItems( stack ) {
	return [ ...stack.children ]
		.filter( item => item.classList.contains( 'active' ) )
		.sort( ( a, b ) => Number( b.dataset.stackOrder ) - Number( a.dataset.stackOrder ) );
}

/**
 * Record each notice's natural height, which the stack needs to fan the notices out and to
 * size the cards peeking behind the front one.
 *
 * @param {HTMLElement[]} items Notices in a stack.
 */
function measure( items ) {
	items.forEach( item => item.classList.add( 'is-measuring' ) );
	items.forEach( item => heights.set( item, item.getBoundingClientRect().height ) );
	items.forEach( item => {
		item.style.setProperty( '--newspack-ui-snackbar-height', `${ heights.get( item ) }px` );
		item.classList.replace( 'is-measuring', 'is-settling' );
	} );
	// Settle at the measured height before transitions come back, so measuring never animates.
	void items[ 0 ]?.offsetHeight;
	items.forEach( item => item.classList.remove( 'is-settling' ) );
}

/**
 * Re-measure a stack whenever a notice's text reflows, as when a web font loads late or the
 * stack moves into a dialog of a different width.
 *
 * @param {HTMLElement} item A notice in a stack.
 */
function watchSize( item ) {
	const content = item.querySelector( '.newspack-ui__snackbar__content' );
	if ( ! content || typeof window.ResizeObserver !== 'function' ) {
		return;
	}
	if ( ! resizeObserver ) {
		resizeObserver = new window.ResizeObserver( entries => {
			const stacks = new Set();
			entries.forEach( ( { target, contentRect } ) => {
				const previous = contentSizes.get( target );
				contentSizes.set( target, { width: contentRect.width, height: contentRect.height } );
				const notice = target.closest( '.newspack-ui__snackbar__item' );
				if (
					previous &&
					( previous.width !== contentRect.width || previous.height !== contentRect.height ) &&
					isStack( notice?.parentElement )
				) {
					stacks.add( notice.parentElement );
				}
			} );
			stacks.forEach( stack => {
				measure( getStackItems( stack ) );
				layoutStack( stack );
			} );
		} );
	}
	resizeObserver.observe( content );
}

/**
 * Stop watching a notice's size.
 *
 * @param {HTMLElement} item A notice.
 */
function unwatchSize( item ) {
	const content = item.querySelector( '.newspack-ui__snackbar__content' );
	if ( resizeObserver && content ) {
		resizeObserver.unobserve( content );
		contentSizes.delete( content );
	}
}

/**
 * Position the notices in a stack: the newest in front, the next two peeking behind it, all of
 * them fanned out while the stack is expanded.
 *
 * @param {HTMLElement} stack The stack group.
 */
function layoutStack( stack ) {
	const items = getStackItems( stack );
	let before = 0;
	items.forEach( ( item, index ) => {
		item.style.setProperty( '--newspack-ui-snackbar-index', index );
		item.style.setProperty( '--newspack-ui-snackbar-before', `${ before }px` );
		if ( 0 === index ) {
			item.dataset.stackPosition = 'front';
		} else {
			item.dataset.stackPosition = index < VISIBLE_IN_STACK ? 'behind' : 'hidden';
		}
		before += heights.get( item ) || 0;
	} );
	stack.style.setProperty( '--newspack-ui-snackbar-count', items.length );
	stack.style.setProperty( '--newspack-ui-snackbar-peeks', Math.max( 0, Math.min( items.length, VISIBLE_IN_STACK ) - 1 ) );
	stack.style.setProperty( '--newspack-ui-snackbar-front-height', `${ items.length ? heights.get( items[ 0 ] ) || 0 : 0 }px` );
	stack.style.setProperty( '--newspack-ui-snackbar-total-height', `${ before }px` );
	if ( ! items.length ) {
		setExpanded( stack, false );
	}
}

/**
 * Fan the stack out or collapse it. Every timer in the stack pauses while it's fanned out.
 *
 * @param {HTMLElement} stack    The stack group.
 * @param {boolean}     expanded Whether to expand it.
 */
function setExpanded( stack, expanded ) {
	if ( ( 'true' === stack.dataset.expanded ) === expanded ) {
		return;
	}
	stack.dataset.expanded = expanded ? 'true' : 'false';
	[ ...stack.children ].forEach( item => {
		const timer = timers.get( item );
		if ( timer ) {
			if ( expanded ) {
				timer.pause();
			} else {
				timer.run();
			}
		}
	} );
}

/**
 * Whether the reader is still pointing at or focused inside the stack.
 *
 * @param {HTMLElement} stack The stack group.
 * @return {boolean} Whether the stack should stay expanded.
 */
function isEngaged( stack ) {
	return stack.matches( ':hover' ) || stack.contains( stack.ownerDocument.activeElement );
}

/**
 * Expand the stack on hover and keyboard focus, and on tap for touch screens, which have no hover.
 *
 * @param {HTMLElement} stack The stack group.
 */
function bindStack( stack ) {
	stack.addEventListener( 'pointerenter', event => {
		if ( 'touch' !== event.pointerType ) {
			setExpanded( stack, true );
		}
	} );
	stack.addEventListener( 'pointerleave', event => {
		if ( 'touch' !== event.pointerType ) {
			setExpanded( stack, stack.contains( stack.ownerDocument.activeElement ) );
		}
	} );
	stack.addEventListener( 'pointerup', event => {
		if ( 'touch' === event.pointerType ) {
			setExpanded( stack, 'true' !== stack.dataset.expanded );
		}
	} );
	stack.ownerDocument.addEventListener( 'pointerdown', event => {
		if ( 'touch' === event.pointerType && ! stack.contains( event.target ) ) {
			setExpanded( stack, false );
		}
	} );
	stack.addEventListener( 'focusin', () => setExpanded( stack, true ) );
	stack.addEventListener( 'focusout', event => {
		if ( ! stack.contains( event.relatedTarget ) ) {
			setExpanded( stack, stack.matches( ':hover' ) );
		}
	} );
}

/**
 * Open a notice.
 *
 * Notices that hide on their own join the stack; notices that stay until closed join the
 * pinned list below it, so they're always shown in full.
 *
 * @param {Element} element - The notice element.
 * @param {boolean} remove  - Whether to remove the notice element on close.
 */
function openNotice( element, remove = true ) {
	const snackbar = element.closest( '.newspack-ui__snackbar' ) || getSnackbar();
	const autohide = element.dataset.autohide !== 'false';
	const group = getGroup( snackbar, autohide ? 'stack' : 'pinned' );
	if ( element.parentElement !== group ) {
		group.appendChild( element );
	}
	element.classList.remove( 'is-leaving' );
	if ( autohide ) {
		element.dataset.stackOrder = String( ++openCount );
		element.dataset.stackPosition = 'front';
		measure( [ element ] );
		watchSize( element );
	}
	// Force a reflow so the off-screen start state is painted before `.active`
	// is added, otherwise the enter transition is skipped.
	void element.offsetWidth;
	element.classList.add( 'active' );
	const content = element.querySelector( '.newspack-ui__snackbar__content' );
	announce( content ? content.textContent.trim() : '', element.dataset.type === 'error' );
	if ( autohide ) {
		layoutStack( group );
		scheduleAutohide( element, remove );
	}
}

/**
 * Hide a snackbar after 8 seconds of being visible. The timer pauses while the stack is
 * expanded, so an action such as Undo stays reachable.
 *
 * @param {HTMLElement} element Snackbar item.
 * @param {boolean}     remove  Whether to remove the item once hidden.
 */
function scheduleAutohide( element, remove ) {
	let remaining = AUTOHIDE_DELAY;
	let startedAt = 0;
	let timeout = null;
	const timer = {
		run() {
			if ( timeout ) {
				return;
			}
			startedAt = Date.now();
			timeout = setTimeout( () => {
				timeout = null;
				closeNotice( element, remove );
			}, remaining );
		},
		pause() {
			if ( ! timeout ) {
				return;
			}
			clearTimeout( timeout );
			timeout = null;
			remaining = Math.max( 0, remaining - ( Date.now() - startedAt ) );
		},
		stop() {
			clearTimeout( timeout );
			timeout = null;
		},
	};
	timers.get( element )?.stop();
	timers.set( element, timer );
	if ( 'true' !== element.parentElement?.dataset.expanded ) {
		timer.run();
	}
}

/**
 * Close a notice.
 *
 * @param {Element} element - The notice element.
 * @param {boolean} remove  - Whether to remove the notice element on dismiss.
 */
function closeNotice( element, remove = true ) {
	timers.get( element )?.stop();
	timers.delete( element );
	const stack = isStack( element.parentElement ) ? element.parentElement : null;
	element.classList.remove( 'active' );
	if ( stack ) {
		element.classList.add( 'is-leaving' );
		layoutStack( stack );
	}
	if ( remove ) {
		setTimeout( () => {
			if ( element.classList.contains( 'active' ) ) {
				return;
			}
			unwatchSize( element );
			element.remove();
			// A removed element doesn't report focus leaving, so check whether the stack is still in use.
			if ( stack && 'true' === stack.dataset.expanded ) {
				setExpanded( stack, isEngaged( stack ) );
			}
		}, getTransitionTime( element ) );
	}
	if ( element.dataset.noticeId ) {
		wp.ajax.send( 'newspack_ui_notice_dismissed', {
			data: {
				id: element.dataset.noticeId,
				nonce: element.dataset.nonce,
			},
		} );
	}
}

/**
 * Build a snackbar's close button.
 *
 * @param {HTMLElement} item The snackbar item it closes.
 * @return {HTMLButtonElement} The button.
 */
function createCloseButton( item ) {
	const label = window.newspackUIData?.closeLabel || 'Close';
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost-dark newspack-ui__snackbar__close';
	button.setAttribute( 'aria-label', label );
	button.title = label;
	if ( window.newspackUIData?.closeIcon ) {
		const template = document.createElement( 'template' );
		template.innerHTML = window.newspackUIData.closeIcon.trim();
		if ( template.content.firstElementChild ) {
			button.appendChild( template.content.firstElementChild );
		}
	}
	button.addEventListener( 'click', () => closeNotice( item ) );
	return button;
}

/**
 * Dynamically create and show a snackbar notice. Errors stay until the reader closes them,
 * like the errors printed by the server, so a newer notice can never cover one.
 *
 * @param {string} message Message text to show.
 * @param {string} type    Severity; drives the ARIA announcement and the type icon ('info', 'error' and 'warning').
 */
function createNotice( message, type = 'success' ) {
	const snackbar = getSnackbar();
	const autohide = 'error' !== type;

	const item = document.createElement( 'div' );
	item.classList.add( 'newspack-ui__snackbar__item' );
	item.dataset.type = type;
	item.setAttribute( 'data-autohide', autohide ? 'true' : 'false' );

	const typeIcon = [ 'info', 'error', 'warning' ].includes( type ) ? getNoticeIcon( type ) : null;
	if ( typeIcon ) {
		const icon = document.createElement( 'span' );
		icon.classList.add( 'newspack-ui__snackbar__icon' );
		icon.appendChild( typeIcon );
		item.appendChild( icon );
	}

	const content = document.createElement( 'div' );
	content.classList.add( 'newspack-ui__snackbar__content' );
	content.textContent = message;

	item.appendChild( content );
	if ( ! autohide ) {
		item.appendChild( createCloseButton( item ) );
	}
	snackbar.appendChild( item );
	openNotice( item, true );
}

// Expose notice functions to the global API.
export default { openNotice, closeNotice, createNotice };
