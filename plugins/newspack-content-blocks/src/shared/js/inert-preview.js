/**
 * Prevent ordinary clicks on links in a block's editor preview from navigating
 * the editor away from the post being edited.
 *
 * Runs on the preview wrapper in the capture phase so it catches links however
 * they were rendered while preserving their real destinations. Cmd/Ctrl-click
 * is left alone for opening links in a new tab; Shift- and Alt-clicks are
 * cancelled like plain clicks.
 *
 * Only links inside the wrapper's own DOM are cancelled. React can bubble
 * portal events through their React ancestors, so this avoids blocking links in
 * popovers opened from the preview.
 *
 * Ignore targets that cannot be checked for an ancestor link rather than
 * throwing from the capture handler.
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
