/**
 * Get the icon for a notice type, as printed by PHP, for notices and field errors built in JS.
 *
 * @param {string} type Notice type: 'info', 'success', 'warning' or 'error'.
 * @return {SVGElement|null} A fresh copy of the icon, or null when the type has none.
 */
export function getNoticeIcon( type ) {
	const markup = window.newspackUIData?.noticeIcons?.[ type ];
	if ( ! markup ) {
		return null;
	}
	const template = document.createElement( 'template' );
	template.innerHTML = markup.trim();
	return template.content.firstElementChild;
}
