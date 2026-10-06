/**
 * Cancel any plain click on a link rendered inside a block's editor preview.
 *
 * Attached with onClickCapture to the preview wrapper so no link in the
 * preview — JSX-authored, server-built, or filter-injected — navigates the
 * editor away from the post being edited, while links keep their real
 * destinations. Cmd/Ctrl-click still opens a link in a new tab. Shift- and
 * Alt-clicks are cancelled like plain clicks, since the browser would otherwise
 * open a new window or download the linked page mid-edit.
 *
 * Only links inside the wrapper's own DOM are cancelled. React delivers events
 * from portals to their React ancestors, so a popover opened from inside the
 * preview (the section header's link popover) would otherwise lose its links.
 *
 * A target that cannot be asked for an ancestor anchor is ignored rather than
 * throwing, which would surface as an uncaught error on an ordinary click.
 *
 * @param {MouseEvent | import('react').MouseEvent} event Click event from the capture phase.
 */
export const preventPreviewNavigation = event => {
	if ( event.metaKey || event.ctrlKey ) {
		return;
	}
	if ( typeof event.target?.closest !== 'function' ) {
		return;
	}
	const anchor = event.target.closest( 'a' );
	if ( anchor && event.currentTarget?.contains?.( anchor ) ) {
		event.preventDefault();
	}
};
