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
 * @param event Click event from the capture phase.
 */
export const preventPreviewNavigation = ( event: MouseEvent | import('react').MouseEvent ): void => {
	if ( event.metaKey || event.ctrlKey ) {
		return;
	}
	const target = event.target as Element | null;
	if ( typeof target?.closest !== 'function' ) {
		return;
	}
	const currentTarget = event.currentTarget as Node | null;
	const anchor = target.closest( 'a' );
	if ( anchor && currentTarget?.contains?.( anchor ) ) {
		event.preventDefault();
	}
};
