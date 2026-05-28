/* globals newspackScreenReaderText */

/**
 * Internal dependencies.
 */
import { MENU_OPEN_CLASS_NAME, OVERLAY_POSITION_CLASS_PREFIX, ANIMATION_DURATION, POSITION_VALUES, SELECTORS } from './consts.js';

// Stores the original DOM position of menu elements before they are moved to the body.
const menuPositions = new WeakMap();

// Stores cleanup functions for active focus traps.
const focusTrapCleanups = new WeakMap();

// Elements we added overlay-contents--position--full-width to (parent override); remove class on restore.
const fullWidthClassAddedByUs = new WeakSet();

// Stores the last focused element before a menu was opened.
let lastFocusedElement;

// Tracks the currently active menu element that has a focus trap.
let activeFocusTrapElement = null;

/**
 * Helper function to get visible focusable elements within a container.
 *
 * @param {HTMLElement} container The container element to search within.
 * @return {Array} Array of visible focusable elements.
 */
const getVisibleFocusableElements = container => {
	if ( ! container ) {
		return [];
	}

	const focusableElements = container.querySelectorAll( SELECTORS.FOCUSABLE );
	return Array.from( focusableElements ).filter( el => {
		try {
			const rect = el.getBoundingClientRect();
			const style = window.getComputedStyle( el );
			return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none' && ! el.hasAttribute( 'hidden' );
		} catch ( error ) {
			// Element might be detached from DOM
			return false;
		}
	} );
};

/**
 * Helper function to remove classes that start with a prefix.
 *
 * @param {HTMLElement} element The element to remove classes from.
 * @param {string}      prefix  The class prefix to match.
 */
const removeClassesWithPrefix = ( element, prefix ) => {
	const classesToRemove = Array.from( element.classList ).filter( className => className.startsWith( prefix ) );
	classesToRemove.forEach( className => element.classList.remove( className ) );
};

/**
 * Helper function to check if an element is effectively a full-width menu.
 * Uses cached slide params (parent override) when available, otherwise the element's own class.
 *
 * @param {HTMLElement} element                  The element to check.
 * @param {Object}      slideAnimationManagerRef Reference to the slide animation manager (used after it is created).
 * @return {boolean} True if element is a full-width menu, false otherwise.
 */
const isEffectiveFullWidthMenu = ( element, slideAnimationManagerRef ) => {
	if ( ! element ) {
		return false;
	}
	const params = slideAnimationManagerRef?.getSlideParams( element );
	if ( params ) {
		return params.direction === 'full-width';
	}
	return element.classList.contains( OVERLAY_POSITION_CLASS_PREFIX + 'full-width' );
};

/**
 * Helper function to restore an element to its original position.
 *
 * @param {HTMLElement} element          The element to restore.
 * @param {Object}      originalPosition The original position data.
 */
const restoreElementPosition = ( element, originalPosition ) => {
	if ( ! element || ! originalPosition ) {
		return;
	}

	try {
		if ( originalPosition.nextSibling && document.contains( originalPosition.nextSibling ) ) {
			originalPosition.parent.insertBefore( element, originalPosition.nextSibling );
		} else {
			originalPosition.parent.appendChild( element );
		}
	} catch ( error ) {
		// Fallback: append to body if restoration fails
		document.body.appendChild( element );
	}
};

/**
 * Finds elements with menu-related classes.
 *
 * @param {string} selector The selector to use.
 * @return {NodeList} Collection of elements matching the selector pattern.
 */
const findMenuElements = selector => {
	return document.querySelectorAll( `[class*=${ selector }]` );
};

/**
 * Checks if any menu is open.
 *
 * @return {boolean} True if any menu is currently open, false otherwise.
 */
const anyMenuIsOpen = () => {
	return document.body.className.includes( MENU_OPEN_CLASS_NAME );
};

/**
 * Gets the close button element for a menu.
 *
 * @param {string}      menuType        The type of menu (e.g., 'mobile-menu').
 * @param {HTMLElement} contentsElement The menu contents element.
 * @return {HTMLElement|null} The close button element or null if not found.
 */
const getMenuCloseButton = ( menuType, contentsElement ) => {
	if ( ! contentsElement ) {
		return null;
	}

	const selector = `.${ menuType }__toggle a`;
	return contentsElement.querySelector( selector );
};

/**
 * Validates menu configuration object.
 *
 * @param {Object} config The menu configuration to validate.
 * @return {boolean} True if configuration is valid, false otherwise.
 */
const validateMenuConfig = config => {
	const required = [ 'menuType', 'containerSelector', 'toggleSelector', 'contentsSelector' ];
	return required.every( key => config[ key ] );
};

/**
 * Creates an overlay manager for handling menu overlays.
 *
 * @return {Object} Object with show, hide, and cleanup methods for managing overlay visibility.
 */
const createOverlayManager = () => {
	let overlay = null;
	let overlayTimeout = null;
	let handleCloseRef = null;

	// Creates and returns the overlay element.
	const create = () => {
		if ( overlay ) {
			return overlay;
		}

		overlay = document.createElement( 'div' );
		overlay.className = 'overlay-mask';
		overlay.style.display = 'none';
		overlay.style.opacity = '0';

		// Handles overlay click and escape key to close menus.
		const handleClose = event => {
			if ( event.type === 'keydown' && event.key !== 'Escape' ) {
				return;
			}
			if ( anyMenuIsOpen() ) {
				closeAllMenus();
			}
		};

		overlay.addEventListener( 'click', handleClose );

		// Add document listener for cleanup.
		document.addEventListener( 'keydown', handleClose );
		handleCloseRef = handleClose;

		document.body.appendChild( overlay );

		return overlay;
	};

	// Shows the overlay with fade-in animation.
	const show = ( duration = ANIMATION_DURATION.OVERLAY ) => {
		const element = create();
		element.style.transition = `opacity ${ duration }ms ease-in-out`;
		element.style.display = 'block';
		void element.offsetHeight;
		requestAnimationFrame( () => {
			element.style.opacity = '1';
		} );
	};

	// Hides the overlay with fade-out animation.
	const hide = ( duration = ANIMATION_DURATION.OVERLAY ) => {
		if ( ! overlay ) {
			return;
		}

		overlay.style.opacity = '0';
		clearTimeout( overlayTimeout );
		overlayTimeout = setTimeout( () => {
			if ( overlay ) {
				overlay.style.display = 'none';
				overlay.remove();
				overlay = null;
			}
		}, duration );
	};

	// Cleanup document event listeners.
	const cleanup = () => {
		if ( handleCloseRef ) {
			document.removeEventListener( 'keydown', handleCloseRef );
			handleCloseRef = null;
		}

		if ( overlay ) {
			overlay.remove();
			overlay = null;
		}
	};

	return { show, hide, cleanup };
};

/**
 * Creates a slide animation manager for menu contents with position classes.
 *
 * @return {Object} Object with slideIn and slideOut methods for managing slide animations.
 */
const createSlideAnimationManager = () => {
	// Store slide animation cleanup functions.
	const slideCleanups = new WeakMap();

	// Cached slide params per element. Resolved before moving to body (when parent still has position class).
	const slideParamsCache = new WeakMap();

	const slideConfigs = [
		{
			direction: 'left',
			property: 'left',
			hiddenValue: POSITION_VALUES.HIDDEN,
			visibleValue: POSITION_VALUES.VISIBLE,
		},
		{
			direction: 'right',
			property: 'right',
			hiddenValue: POSITION_VALUES.HIDDEN,
			visibleValue: POSITION_VALUES.VISIBLE,
		},
		{
			direction: 'full-width',
			property: 'transform',
			hiddenValue: POSITION_VALUES.TRANSFORM_HIDDEN,
			visibleValue: POSITION_VALUES.TRANSFORM_VISIBLE,
		},
	];

	const getConfigForNode = ( node, { allowForce = false } = {} ) => {
		if ( ! node || ! node.classList ) {
			return null;
		}
		for ( const config of slideConfigs ) {
			const baseClass = OVERLAY_POSITION_CLASS_PREFIX + config.direction;
			const forceClass = `${ baseClass }--force`;

			if ( allowForce ) {
				if ( node.classList.contains( forceClass ) ) {
					return config;
				}
			} else if ( node.classList.contains( baseClass ) ) {
				return config;
			}
		}
		return null;
	};

	// Resolves slide params from DOM (element + parent). Call before moving element to body.
	const resolveSlideParams = element => {
		const parent = element.parentElement;
		if ( parent ) {
			// Parent can override using force classes, e.g. overlay-contents--position--right--force.
			const parentConfig = getConfigForNode( parent, { allowForce: true } );
			if ( parentConfig ) {
				return parentConfig;
			}
		}
		// Fall back to the element's own position classes (no force).
		return getConfigForNode( element );
	};

	// Determines slide direction and distance based on position class.
	// Uses cache (set before move) so parent's position class is still available after element is moved to body.
	const getSlideParams = element => {
		const cached = slideParamsCache.get( element );
		if ( cached ) {
			return cached;
		}
		return resolveSlideParams( element );
	};

	// Call before moving element to body so parent's position class (e.g. overlay-contents--position--right) is used.
	const cacheSlideParams = element => {
		const params = resolveSlideParams( element );
		if ( params ) {
			slideParamsCache.set( element, params );
		}
	};

	const clearSlideParamsCache = element => {
		slideParamsCache.delete( element );
	};

	// Slides the menu content in from the specified direction.
	const slideIn = ( element, opacityDuration = ANIMATION_DURATION.OPACITY, positionDuration = ANIMATION_DURATION.POSITION ) => {
		if ( ! element || ! element.style ) {
			return;
		}

		const slideParams = getSlideParams( element );
		if ( ! slideParams ) {
			return;
		}

		// Clean up any existing animation.
		const existingCleanup = slideCleanups.get( element );
		if ( existingCleanup ) {
			existingCleanup();
		}

		// Set opposite position to auto so CSS from the element's position class (e.g. --left) doesn't give zero width when we animate the other side (e.g. right).
		const oppositeProperty = slideParams.property === 'left' ? 'right' : 'left';
		element.style[ oppositeProperty ] = 'auto';

		// Set initial state.
		element.style.opacity = '0';
		element.style[ slideParams.property ] = slideParams.hiddenValue;
		element.style.transition = `opacity ${ opacityDuration }ms ease-in-out, ${ slideParams.property } ${ positionDuration }ms ease-in-out`;

		void element.offsetHeight;

		// Animate to final state.
		requestAnimationFrame( () => {
			element.style.opacity = '1';
			element.style[ slideParams.property ] = slideParams.visibleValue;
		} );

		// Store cleanup function.
		const cleanup = () => {
			element.style.opacity = '';
			element.style[ oppositeProperty ] = '';
			element.style[ slideParams.property ] = '';
			element.style.transition = '';
			slideCleanups.delete( element );
		};

		slideCleanups.set( element, cleanup );
	};

	// Slides the menu content out to the specified direction.
	const slideOut = ( element, opacityDuration = ANIMATION_DURATION.OPACITY, positionDuration = ANIMATION_DURATION.POSITION, callback = null ) => {
		if ( ! element || ! element.style ) {
			if ( callback ) {
				callback();
			}
			return;
		}

		const slideParams = getSlideParams( element );
		if ( ! slideParams ) {
			// No position classes - run callback immediately
			if ( callback ) {
				callback();
			}
			return;
		}

		// Set opposite position to auto so element has correct width (avoids zero width when element has CSS --left but we animate --right).
		const oppositeProperty = slideParams.property === 'left' ? 'right' : 'left';
		element.style[ oppositeProperty ] = 'auto';

		// Set transition for slide out.
		element.style.transition = `opacity ${ opacityDuration }ms ease-in-out, ${ slideParams.property } ${ positionDuration }ms ease-in-out`;

		// Animate to hidden state.
		element.style.opacity = '0';
		element.style[ slideParams.property ] = slideParams.hiddenValue;

		// Run callback after animation completes.
		const maxDuration = Math.max( opacityDuration, positionDuration );
		setTimeout( () => {
			const cleanup = slideCleanups.get( element );
			if ( cleanup ) {
				cleanup();
			}
			if ( callback ) {
				callback();
			}
		}, maxDuration );
	};

	// Cleanup all slide animations.
	const cleanup = () => {
		slideCleanups.forEach( cleanupFn => cleanupFn() );
	};

	return { cacheSlideParams, clearSlideParamsCache, getSlideParams, slideIn, slideOut, cleanup };
};

/**
 * Global focus monitor to catch escaping focus.
 *
 * @return {Object} Object with startMonitoring and stopMonitoring methods.
 */
const createGlobalFocusMonitor = () => {
	let monitorInterval = null;

	const startMonitoring = () => {
		if ( monitorInterval ) {
			return;
		}

		monitorInterval = setInterval( () => {
			if ( ! anyMenuIsOpen() || ! activeFocusTrapElement ) {
				return;
			}

			const activeElement = activeFocusTrapElement.ownerDocument.activeElement;

			// Check if focus has escaped outside the menu.
			if (
				! activeFocusTrapElement.contains( activeElement ) &&
				activeElement !== document.body &&
				activeElement !== document.documentElement
			) {
				// Focus has escaped, bring it back.
				const visibleFocusableElements = getVisibleFocusableElements( activeFocusTrapElement );
				const firstFocusable = visibleFocusableElements[ 0 ];
				if ( firstFocusable ) {
					firstFocusable.focus();
				}
			}
		}, 50 );
	};

	const stopMonitoring = () => {
		if ( monitorInterval ) {
			clearInterval( monitorInterval );
			monitorInterval = null;
		}
	};

	return { startMonitoring, stopMonitoring };
};

// Instantiate managers for use in this module.
const overlayManager = createOverlayManager();
const slideAnimationManager = createSlideAnimationManager();
const globalFocusMonitor = createGlobalFocusMonitor();

/**
 * Creates a focus trap for better accessibility.
 *
 * @param {HTMLElement} element The element to trap focus in.
 * @return {Function} Cleanup function to remove the focus trap.
 */
export const createFocusTrap = element => {
	// Clean up any existing focus trap first.
	const existingCleanup = focusTrapCleanups.get( element );
	if ( existingCleanup ) {
		existingCleanup();
	}

	const focusableElements = element.querySelectorAll( SELECTORS.FOCUSABLE );
	const firstFocusable = focusableElements[ 0 ];
	const lastFocusable = focusableElements[ focusableElements.length - 1 ];

	// Only create focus trap if there are focusable elements.
	if ( ! firstFocusable || ! lastFocusable ) {
		return () => {};
	}

	// Handles tab key navigation to trap focus within the element.
	const handleKeyDown = e => {
		if ( e.key !== 'Tab' ) {
			return;
		}

		// Only handle tab events when a menu is open.
		if ( ! anyMenuIsOpen() ) {
			return;
		}

		// Refresh focusable elements in case the DOM has changed.
		const visibleFocusableElements = getVisibleFocusableElements( element );
		const currentFirstFocusable = visibleFocusableElements[ 0 ];
		const currentLastFocusable = visibleFocusableElements[ visibleFocusableElements.length - 1 ];

		// Ensure we have valid focusable elements.
		if ( ! currentFirstFocusable || ! currentLastFocusable ) {
			e.preventDefault();
			return;
		}

		// Check if focus is currently within the menu element.
		const activeElement = element.ownerDocument.activeElement;
		if ( ! element.contains( activeElement ) ) {
			// Focus escaped the menu, bring it back.
			e.preventDefault();
			currentFirstFocusable.focus();
			return;
		}

		// Handle tabbing within the menu.
		if ( e.shiftKey ) {
			// Shift+Tab (backwards).
			if ( activeElement === currentFirstFocusable ) {
				e.preventDefault();
				currentLastFocusable.focus();
			}
		} else if ( activeElement === currentLastFocusable ) {
			// Tab (forwards).
			e.preventDefault();
			currentFirstFocusable.focus();
		}
	};

	// Add event listener to document to catch all tab events with capture.
	document.addEventListener( 'keydown', handleKeyDown, true );

	// Also add a fallback listener on the element itself.
	element.addEventListener( 'keydown', handleKeyDown );

	const cleanup = () => {
		document.removeEventListener( 'keydown', handleKeyDown, true );
		element.removeEventListener( 'keydown', handleKeyDown );
		focusTrapCleanups.delete( element );

		// Clear active focus trap if this was it.
		if ( activeFocusTrapElement === element ) {
			activeFocusTrapElement = null;
		}
	};

	// Store cleanup function.
	focusTrapCleanups.set( element, cleanup );

	// Set this as the active focus trap.
	activeFocusTrapElement = element;

	// Start global focus monitoring.
	globalFocusMonitor.startMonitoring();

	return cleanup;
};

/**
 * Adds accessibility features to the menu.
 *
 * @param {HTMLElement} menuElement The menu element to enhance.
 * @param {string}      menuType    The type of menu.
 */
const enhanceMenuAccessibility = ( menuElement, menuType = '' ) => {
	// Store the last focused element.
	lastFocusedElement = menuElement.ownerDocument.activeElement;

	// Add screen reader link if there's no close button.
	const closeButton = menuElement.querySelector( SELECTORS.CLOSE_BUTTON );
	const screenReaderLink = menuElement.querySelector( SELECTORS.SCREEN_READER_LINK );

	if ( ! closeButton && ! screenReaderLink ) {
		const closeText = menuType === 'search-menu' ? newspackScreenReaderText.close_search : newspackScreenReaderText.close_menu;
		const newScreenReaderLink = document.createElement( 'a' );
		newScreenReaderLink.href = '#';
		newScreenReaderLink.className = 'screen-reader-text';
		newScreenReaderLink.textContent = closeText;
		newScreenReaderLink.addEventListener( 'click', e => {
			e.preventDefault();
			closeAllMenus();
		} );
		menuElement.insertBefore( newScreenReaderLink, menuElement.firstChild );
	}

	// Create focus trap
	createFocusTrap( menuElement );

	// Focus first focusable element
	const firstFocusable = menuElement.querySelector( SELECTORS.FOCUSABLE );
	if ( firstFocusable ) {
		firstFocusable.focus();
	}
};

/**
 * Moves menu to body root when opened.
 *
 * @param {HTMLElement} menuElement The menu element to move.
 * @param {string}      menuType    The type of menu.
 */
const moveMenuToRoot = ( menuElement, menuType ) => {
	if ( ! menuElement || menuPositions.has( menuElement ) ) {
		return;
	}

	// Resolve and cache slide params while element is still in DOM (parent may have position class).
	slideAnimationManager.cacheSlideParams( menuElement );

	const slideParams = slideAnimationManager.getSlideParams( menuElement );
	if ( slideParams?.direction === 'full-width' && ! menuElement.classList.contains( OVERLAY_POSITION_CLASS_PREFIX + 'full-width' ) ) {
		menuElement.classList.add( OVERLAY_POSITION_CLASS_PREFIX + 'full-width' );
		fullWidthClassAddedByUs.add( menuElement );
	}

	// Store original position.
	menuPositions.set( menuElement, {
		parent: menuElement.parentNode,
		nextSibling: menuElement.nextSibling,
	} );

	// Move to body.
	document.body.appendChild( menuElement );

	// Apply slide-in animation if element has position classes.
	slideAnimationManager.slideIn( menuElement );

	// Enhance accessibility.
	enhanceMenuAccessibility( menuElement, menuType );
};

/**
 * Runs slide-out animation and restores one menu contents element to its original DOM position.
 *
 * @param {HTMLElement} element The menu contents element to restore.
 */
const restoreMenuContent = element => {
	// Clean up focus trap immediately
	const cleanup = focusTrapCleanups.get( element );
	if ( cleanup ) {
		cleanup();
	}

	// Get original position
	const originalPosition = menuPositions.get( element );
	if ( ! originalPosition ) {
		return;
	}

	// For full-width menus, delay class removal until after animation
	const elementIsFullWidth = isEffectiveFullWidthMenu( element, slideAnimationManager );

	// Start slide-out animation
	slideAnimationManager.slideOut( element, ANIMATION_DURATION.OPACITY, ANIMATION_DURATION.POSITION, () => {
		// Remove menu-open class from full-width elements after animation
		if ( elementIsFullWidth ) {
			removeClassesWithPrefix( element, MENU_OPEN_CLASS_NAME );
		}

		// Remove full-width class if we added it for parent override
		if ( fullWidthClassAddedByUs.has( element ) ) {
			element.classList.remove( OVERLAY_POSITION_CLASS_PREFIX + 'full-width' );
			fullWidthClassAddedByUs.delete( element );
		}

		// Restore position after slide-out animation
		restoreElementPosition( element, originalPosition );
		menuPositions.delete( element );
		slideAnimationManager.clearSlideParamsCache( element );
	} );
};

/**
 * Closes all open menus.
 */
export const closeAllMenus = () => {
	// Stop global focus monitoring.
	globalFocusMonitor.stopMonitoring();

	// Get elements before starting animations
	const openMenuElements = findMenuElements( MENU_OPEN_CLASS_NAME );
	const menuContents = Array.from( findMenuElements( '__contents' ) ).filter(
		element => element.parentNode === document.body && menuPositions.has( element )
	);

	// Remove menu-open classes immediately to allow toggle to work properly
	// (except for full-width menus which need the class during slide-out animation)
	openMenuElements.forEach( element => {
		if ( ! isEffectiveFullWidthMenu( element, slideAnimationManager ) ) {
			removeClassesWithPrefix( element, MENU_OPEN_CLASS_NAME );
		}
	} );
	removeClassesWithPrefix( document.body, MENU_OPEN_CLASS_NAME );

	menuContents.forEach( restoreMenuContent );

	// Restore focus immediately
	if ( lastFocusedElement ) {
		try {
			// Check if element is still in DOM and focusable
			if ( document.contains( lastFocusedElement ) && ! lastFocusedElement.disabled ) {
				lastFocusedElement.focus();
			}
		} catch ( error ) {
			// Element might be detached or no longer focusable
			// Focus will remain on body, which is acceptable
		}
	}

	// Hide overlay with standard duration.
	overlayManager.hide( ANIMATION_DURATION.OVERLAY );
};

/**
 * Creates a menu factory for consistent menu behavior.
 *
 * @param {Object}   config                          Menu configuration object.
 * @param {string}   config.menuType                 The type of menu (e.g., 'mobile-menu')
 * @param {string}   config.containerSelector        CSS selector for the menu container
 * @param {string}   config.toggleSelector           CSS selector for the menu toggle buttons
 * @param {string}   config.contentsSelector         CSS selector for the menu contents
 * @param {number}   config.overlayAnimationDuration Animation duration for overlay in milliseconds
 * @param {Function} config.onOpen                   Optional callback function when menu opens (receives contents, container, toggles). If not provided, defaults to focusing the close button.
 * @param {Function} config.onClose                  Callback function when menu closes (receives contents, container, toggles)
 * @param {Function} config.specialHandling          Function for menu-specific setup
 * @return {Object} Object with init method for setting up the menu behavior.
 */
export const createMenu = config => {
	// Validate configuration.
	if ( ! validateMenuConfig( config ) ) {
		return { init: () => {} }; // Return no-op object.
	}

	const {
		menuType,
		containerSelector,
		toggleSelector,
		contentsSelector,
		overlayAnimationDuration = ANIMATION_DURATION.OVERLAY,
		onOpen = null,
		onClose = () => {},
		specialHandling = () => {},
	} = config;

	const openClassName = MENU_OPEN_CLASS_NAME + menuType;

	return {
		init: () => {
			const body = document.body;
			const container = document.querySelector( containerSelector );
			const toggles = document.querySelectorAll( toggleSelector );
			const contents = document.querySelector( contentsSelector );

			if ( ! container || ! toggles.length || ! contents ) {
				return;
			}

			// Apply any special handling (like Jetpack search).
			const shouldContinue = specialHandling( container, toggles, contents );

			// If specialHandling returns false, don't set up the menu.
			if ( shouldContinue === false ) {
				return;
			}

			// Toggles the menu open/closed state.
			const toggleMenu = event => {
				event.preventDefault();
				if ( body.classList.contains( openClassName ) ) {
					closeAllMenus();
					onClose( contents, container, toggles );
				} else {
					// Resolve container and contents for the clicked toggle (e.g. search menu exists in both desktop and mobile header).
					const containerForToggle = event.currentTarget.closest( containerSelector );
					const contentsForToggle = containerForToggle?.querySelector( contentsSelector );
					if ( containerForToggle && contentsForToggle ) {
						openMenu( contentsForToggle, containerForToggle );
					}
				}
			};

			// Opens the menu and applies necessary styling.
			const openMenu = ( contentsToOpen, containerToUse ) => {
				body.classList.add( openClassName );
				contentsToOpen.classList.add( openClassName );
				moveMenuToRoot( contentsToOpen, menuType );

				// Only show overlay for non-full-width menus
				if ( ! isEffectiveFullWidthMenu( contentsToOpen, slideAnimationManager ) ) {
					overlayManager.show( overlayAnimationDuration );
				}

				// Handle onOpen callback or default behavior.
				if ( onOpen ) {
					onOpen( contentsToOpen, containerToUse, toggles );
				} else {
					// Default behavior: focus the close button.
					const closeButton = getMenuCloseButton( menuType, contentsToOpen );
					if ( closeButton ) {
						closeButton.focus();
					}
				}
			};

			toggles.forEach( toggle => {
				toggle.addEventListener( 'click', toggleMenu, false );
			} );
		},
	};
};
