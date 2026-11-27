/**
 * Get the icon for a notice type, as printed by PHP, for notices and field errors built in JS.
 *
 * @param type Notice type: 'info', 'success', 'warning', 'error' or 'check'.
 * @return A fresh copy of the icon, or null when the type has none.
 */
export function getNoticeIcon( type: string ): Element | null {
	return parseIcon( window.newspackUIData?.noticeIcons?.[ type ] );
}

/**
 * Get the close icon, as printed by PHP, for close buttons built in JS.
 *
 * @return A fresh copy of the icon, or null when it's unavailable.
 */
export function getCloseIcon(): Element | null {
	return parseIcon( window.newspackUIData?.closeIcon );
}

/**
 * Turn icon markup localized from PHP into an element.
 *
 * @param markup The icon's SVG markup.
 * @return The icon, or null when there's no markup.
 */
function parseIcon( markup: string | undefined ): Element | null {
	if ( ! markup ) {
		return null;
	}
	const template = document.createElement( 'template' );
	template.innerHTML = markup.trim();
	return template.content.firstElementChild;
}
