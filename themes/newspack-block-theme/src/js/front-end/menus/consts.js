// Prefix for class name to be added to document.body when any menu is open.
export const MENU_OPEN_CLASS_NAME = 'menu-open--';

// Prefix for overlay position class names.
export const OVERLAY_POSITION_CLASS_PREFIX = 'overlay-contents--position--';

// Animation duration constants.
export const ANIMATION_DURATION = {
	OPACITY: 125,
	POSITION: 250,
	OVERLAY: 500,
};

// Animation position values.
export const POSITION_VALUES = {
	HIDDEN: '-100%',
	VISIBLE: '0',
	TRANSFORM_HIDDEN: 'translateY(-1rem)',
	TRANSFORM_VISIBLE: 'translateY(0)',
};

// Commonly used selectors.
export const SELECTORS = {
	FOCUSABLE:
		'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), iframe, object, embed, [contenteditable="true"]',
	CLOSE_BUTTON: '.newspack-icon-close',
	SCREEN_READER_LINK: 'a.screen-reader-text',
};
